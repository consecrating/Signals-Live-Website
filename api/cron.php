<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * cron.php — autonomous server-side worker
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * THE PROBLEM THIS SOLVES
 * Everything used to run in the browser. Signals were computed by js/live-engine.js,
 * positions were monitored by js/paper-trading.js and js/virtual-trading.js, and
 * execution was fenced behind a lease held by an open tab. Close the laptop and the
 * system stopped completely: no signals, no alerts, no stop or target checks, and no
 * 15:20 square-off — which is how positions ended up held for 700 and 2,100 minutes.
 *
 * This worker does that job on the server, with no browser involved. Point cron at it
 * (see docs/CRON-SETUP.md) and the desk keeps working with every device switched off.
 *
 * DESIGN CONSTRAINTS, AND WHY IT IS BUILT THIS WAY
 *  · proxy.php emits headers and routes at include time, so it CANNOT be included.
 *    Market data is therefore read over HTTP from its own endpoints — which also means
 *    this worker reuses the battle-tested broker auth, caching and retry logic rather
 *    than duplicating it. Reads need no authorisation; only writes do.
 *  · Those write endpoints deliberately reject non-browser callers (same-site + write
 *    token). Rather than weaken that, the worker writes the state files DIRECTLY on
 *    disk under flock — the same files kernel.php reconciles.
 *  · kernel.php is pure functions, so its helpers and audit log are reused as-is.
 *
 * DELIBERATE SCOPE LIMIT — IT DOES NOT OPEN NEW POSITIONS
 * It generates signals, alerts, and manages positions that already exist. Autonomous
 * ENTRY is a separate risk decision: it needs God Mode's adaptive threshold and the
 * full live-quote execution gate stack, and turning it on silently would mean trades
 * appearing with nobody watching. Enabling it is a conscious choice, not a side effect
 * of fixing the laptop problem. Exits are the opposite case — an open position with no
 * stop monitoring is strictly more dangerous than no automation at all, so those run.
 *
 * USAGE
 *   CLI  :  php /home/USER/public_html/signals/api/cron.php
 *   HTTP :  https://ads.sanctify.co.in/signals/api/cron.php?token=<cron_token>
 *
 * Safe to call every minute: it is idempotent, guarded by its own lock, and does
 * nothing outside market hours except the end-of-day square-off sweep.
 */

declare(strict_types=1);

@set_time_limit(110);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/kernel.php';

const CRON_INSTRUMENTS   = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'MIDCPNIFTY'];
const CRON_CONF_THRESHOLD = 60.0;
const CRON_EOD_MIN        = 15 * 60 + 20;  // 15:20 IST — matches both desks
const CRON_CLOSE_MIN      = 15 * 60 + 30;
const CRON_OPEN_MIN       = 9 * 60 + 15;
const CRON_ALERT_COOLDOWN = 1800;          // 30 min per symbol+direction
const CRON_QUOTE_MAX_AGE  = 180;           // seconds; exits may fill on a slightly older mark
const CRON_LOCK_STALE     = 300;

$IS_CLI = (PHP_SAPI === 'cli');
if (!$IS_CLI) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

// ─── authorisation ───────────────────────────────────────────────────────────
/**
 * CLI is trusted (only someone with shell/cron access can invoke it). Over HTTP a
 * token is required, because this endpoint moves money-like state and sends mail.
 * Falls back to admin_token so it works before cron_token is added to the config.
 */
function cron_authorise(bool $isCli): void {
    if ($isCli) return;
    $cfgFile = __DIR__ . '/config.secret.php';
    $expected = null;
    if (is_file($cfgFile)) {
        $c = include $cfgFile;
        $expected = $c['cron_token'] ?? ($c['admin_token'] ?? null);
    }
    $got = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    if (!$expected || !is_string($got) || $got === '' || !hash_equals((string)$expected, (string)$got)) {
        http_response_code(403);
        echo json_encode(['status' => false, 'error' => 'cron token required']);
        exit;
    }
}
cron_authorise($IS_CLI);

// ─── small utilities ─────────────────────────────────────────────────────────
function cron_base_url(): string {
    // Own origin when serving over HTTP; the public URL when run from cron/CLI.
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/signals/api/proxy.php';
    }
    return 'https://ads.sanctify.co.in/signals/api/proxy.php';
}

function cron_get(array $params, int $timeout = 25): ?array {
    $url = cron_base_url() . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'SignalsCron/1.0',
        // Same shared *.webhostbox.net certificate the FTP host uses; when the worker
        // calls its own hostname verification can fail on the shared cert. This is a
        // loopback call to our own origin, so it is not a meaningful exposure.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

function cron_log(array &$out, string $line): void {
    $out['log'][] = $line;
    if (PHP_SAPI === 'cli') echo $line . "\n";
}

/** Single-writer lock so overlapping cron ticks cannot interleave state writes. */
function cron_acquire_lock(string $file) {
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        // A previous tick may have died holding it; break the lock once it is stale.
        $age = time() - (int)@filemtime($file);
        if ($age < CRON_LOCK_STALE) { fclose($fh); return null; }
        @ftruncate($fh, 0);
    }
    @ftruncate($fh, 0);
    fwrite($fh, (string)time());
    fflush($fh);
    return $fh;
}
function cron_release_lock($fh): void {
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
}

function cron_read_json(string $file): ?array {
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function cron_r2($x) { return round((float)$x, 2); }

// ─── market data ─────────────────────────────────────────────────────────────
function cron_candles(string $symbol, string $interval = 'FIFTEEN_MINUTE', int $days = 12): ?array {
    $to = eng_ist_now()['dt']->format('Y-m-d');
    $from = (new DateTime('@' . (time() - $days * 86400)))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
    $j = cron_get(['action' => 'candles', 'symbol' => $symbol, 'interval' => $interval,
                   'from' => $from, 'to' => $to]);
    $d = $j['data'] ?? null;
    if (!is_array($d) || empty($d['count'])) return null;
    return $d;
}

function cron_option_ltp(string $symbol, $expiry, $strike, string $type): ?array {
    $p = ['action' => 'option_ltp', 'symbol' => $symbol, 'strike' => (string)$strike,
          'type' => strtoupper($type), 'cb' => (string)time()];
    if ($expiry) $p['expiry'] = $expiry;
    $j = cron_get($p);
    if (!is_array($j) || empty($j['status']) || empty($j['data']['ltp'])) return null;
    $ltp = (float)$j['data']['ltp'];
    if ($ltp <= 0) return null;
    $qt = isset($j['timestamp']) ? strtotime((string)$j['timestamp']) : 0;
    return ['ltp' => $ltp, 'quoteAt' => $qt ? $qt * 1000 : (int)(microtime(true) * 1000)];
}

/** 1-hour EMA9/EMA21 bias, matching getMTF() on the signal page. */
function cron_mtf_bias(string $symbol): int {
    $c = cron_candles($symbol, 'ONE_HOUR', 20);
    if (!$c || count($c['closes'] ?? []) < 30) return 0;
    $e9 = eng_ema($c['closes'], 9);
    $e21 = eng_ema($c['closes'], 21);
    if ($e9 === null || $e21 === null) return 0;
    return $e9 > $e21 ? 1 : ($e9 < $e21 ? -1 : 0);
}

// ─── alerts ──────────────────────────────────────────────────────────────────
/**
 * Email a confirmed signal, with a per-symbol+direction cooldown.
 *
 * Written here rather than calling ?action=alert because that endpoint requires a
 * browser write token by design (it is an outbound mail relay). The dedup file is
 * separate from the browser's so the two paths cannot suppress each other's alerts.
 */
function cron_send_alert(array $sig, array &$out): bool {
    $dedupFile = kDir() . '/cron-alerts.json';
    $seen = cron_read_json($dedupFile) ?: [];
    $key = $sig['symbol'] . '|' . $sig['direction'];
    $now = time();
    if (isset($seen[$key]) && ($now - (int)$seen[$key]) < CRON_ALERT_COOLDOWN) {
        cron_log($out, "  alert suppressed (cooldown): $key");
        return false;
    }

    $ist = eng_ist_now();
    $atm = eng_atm_strike($sig['symbol'], (float)$sig['ltp']);
    $type = $sig['optionType'] ?: '';
    $thesis = $sig['direction'] === 'BUY'
        ? 'fade an oversold dip (long CE), expecting reversion up toward the mean'
        : 'fade an overbought rip (long PE), expecting reversion down toward the mean';

    $subject = sprintf('[%s] %s %s %d%s — %d%% (server)',
        $sig['direction'], $sig['symbol'], $sig['direction'] === 'BUY' ? 'CE' : 'PE',
        $atm, '', (int)$sig['confidence']);

    $rows = [
        'Instrument'      => $sig['symbol'],
        'Direction'       => $sig['direction'] . ' — ' . $thesis,
        'Suggested strike'=> $atm . ' ' . $type . ' (ATM)',
        'Spot'            => number_format((float)$sig['ltp'], 2),
        'Confidence'      => (int)$sig['confidence'] . '%',
        'Reversion score' => $sig['netScore'] . ' (fade window ±30 to ±50)',
        'RSI / ADX'       => (($sig['rsi'] === null) ? '—' : round((float)$sig['rsi'], 1))
                             . ' / ' . ($sig['adx'] ?? '—') . ' (' . $sig['regime'] . ')',
        'Generated'       => $ist['date'] . ' ' . $ist['time'] . ' IST (server, no browser needed)',
    ];
    $html = '<div style="font-family:system-ui,sans-serif;max-width:560px">'
        . '<h2 style="margin:0 0 8px">' . htmlspecialchars($sig['symbol']) . ' — '
        . htmlspecialchars($sig['direction']) . '</h2>'
        . '<table style="border-collapse:collapse;font-size:14px">';
    foreach ($rows as $k => $v) {
        $html .= '<tr><td style="padding:4px 10px 4px 0;color:#555">' . htmlspecialchars((string)$k)
              . '</td><td style="padding:4px 0"><b>' . htmlspecialchars((string)$v) . '</b></td></tr>';
    }
    $html .= '</table>'
        // Every alert carries the honest caveat. The offline validation put the pooled
        // deflated Sharpe at 0.55 against the 0.95 needed, so presenting a signal
        // without that context would overstate what it is worth.
        . '<p style="font-size:12px;color:#8a6d3b;background:#fcf8e3;border:1px solid #faebcc;'
        . 'padding:8px;border-radius:4px;margin-top:12px">This is an algorithmic mean-reversion '
        . 'signal. The strategy is <b>not yet statistically proven</b> (last validation: pooled '
        . 'deflated Sharpe 0.55 against the 0.95 needed, on 40 option trades). Paper-validate '
        . 'before risking capital. Not financial advice.</p>'
        . '<p style="font-size:11px;color:#888">Sent by the server-side worker, which runs '
        . 'whether or not any browser is open.</p></div>';

    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: FNO Signal Pro <signals@ads.sanctify.co.in>\r\n";
    $headers .= "Reply-To: signals@ads.sanctify.co.in\r\n";
    $ok = @mail('consecrating@gmail.com', $subject, $html, $headers);

    if ($ok) {
        $seen[$key] = $now;
        kAtomicWrite($dedupFile, json_encode($seen));
        cron_log($out, "  ALERT SENT: $key @ {$sig['confidence']}%");
        auditLog('order', 'cron_alert_sent', ['symbol' => $sig['symbol'],
            'direction' => $sig['direction'], 'confidence' => $sig['confidence']]);
    } else {
        cron_log($out, "  alert mail() FAILED for $key");
    }
    return (bool)$ok;
}

// ─── virtual desk: monitor + square off ──────────────────────────────────────
/**
 * Applies the same exit rules as js/virtual-trading.js: hard stop, target, trailing
 * stop, scheduled exit, and the intraday 15:20 square-off — including the stale case
 * where a position survived a session because no tab was open.
 */
function cron_monitor_virtual(array &$out): array {
    $file = kDir() . '/virtual/state.json';
    $s = cron_read_json($file);
    if (!$s || empty($s['active'])) return ['closed' => 0, 'marked' => 0];

    $ist = eng_ist_now();
    $todayKey = $ist['date'];
    $closed = 0; $marked = 0; $changed = false;
    $keep = [];

    foreach ($s['active'] as $pos) {
        $q = cron_option_ltp($pos['instrument'] ?? '', $pos['expiry'] ?? null,
                             $pos['strike'] ?? 0, $pos['type'] ?? 'CE');
        if ($q) {
            $pos['currentPremium'] = cron_r2($q['ltp']);
            $pos['lastQuoteAt'] = $q['quoteAt'];
            if ($q['ltp'] > (float)($pos['peakPremium'] ?? 0)) $pos['peakPremium'] = cron_r2($q['ltp']);
            $eff = (float)($pos['effectiveEntry'] ?? 0);
            $qty = (float)($pos['qty'] ?? 0);
            $pos['pnl'] = cron_r2(($q['ltp'] - $eff) * $qty);
            $pos['pnlPct'] = $eff > 0 ? (int)round((($q['ltp'] - $eff) / $eff) * 100) : 0;
            $marked++; $changed = true;
        }

        // Intraday square-off. Checked BEFORE any freshness requirement: after 15:30
        // the option feed stops ticking, so demanding a fresh quote here would let the
        // position ride overnight — exactly the failure this worker exists to prevent.
        $openKey = !empty($pos['openDate'])
            ? eng_ist_now(strtotime((string)$pos['openDate']))['date'] : $todayKey;
        $due = null;
        if ($openKey !== $todayKey)               $due = 'EOD_SQUAREOFF_STALE';
        elseif ($ist['min'] >= CRON_EOD_MIN)      $due = 'EOD_SQUAREOFF';

        $px = null; $outcome = null;
        if ($due !== null) {
            $px = (float)($pos['currentPremium'] ?? 0) ?: (float)($pos['effectiveEntry'] ?? 0);
            $outcome = $due;
        } elseif ($q) {
            $ltp = (float)$q['ltp'];
            $stop = (float)($pos['stopPremium'] ?? 0);
            $target = (float)($pos['targetPremium'] ?? 0);
            $trail = (float)($pos['trailPct'] ?? 0);
            $peak = (float)($pos['peakPremium'] ?? 0);
            $eff = (float)($pos['effectiveEntry'] ?? 0);
            if ($stop > 0 && $ltp <= $stop)              { $px = $ltp; $outcome = 'STOP_LOSS'; }
            elseif ($target > 0 && $ltp >= $target)      { $px = $ltp; $outcome = 'TARGET_HIT'; }
            elseif ($trail > 0 && $peak > $eff && $ltp <= $peak * (1 - $trail / 100)) {
                $px = $ltp; $outcome = 'TRAIL_STOP';
            } elseif (!empty($pos['schedule']['kind'])) {
                $sch = $pos['schedule'];
                if ($sch['kind'] === 'time' && !empty($sch['at']) && time() >= strtotime((string)$sch['at'])) {
                    $px = $ltp; $outcome = 'SCHEDULED_TIME';
                } elseif ($sch['kind'] === 'price_at_or_above' && $ltp >= (float)($sch['price'] ?? INF)) {
                    $px = $ltp; $outcome = 'SCHEDULED_PRICE';
                } elseif ($sch['kind'] === 'price_at_or_below' && $ltp <= (float)($sch['price'] ?? -INF)) {
                    $px = $ltp; $outcome = 'SCHEDULED_PRICE';
                }
            }
        }

        if ($outcome === null) { $keep[] = $pos; continue; }

        // Close it: mirror _sellInternal() so the ledger stays consistent with the
        // browser's own accounting (fills at the live premium, no synthetic friction).
        $eff = (float)($pos['effectiveEntry'] ?? 0);
        $qty = (float)($pos['qty'] ?? 0);
        $proceeds = cron_r2($px * $qty);
        $entryCost = cron_r2($eff * $qty);
        $pnl = cron_r2($proceeds - $entryCost);
        $holdMin = !empty($pos['openDate'])
            ? (int)round((time() - strtotime((string)$pos['openDate'])) / 60) : 0;

        $s['cash'] = cron_r2((float)($s['cash'] ?? 0) + $proceeds);
        $s['totalPnl'] = cron_r2((float)($s['totalPnl'] ?? 0) + $pnl);
        $s['trades'][] = [
            'id' => (string)($pos['id'] ?? uniqid('v', true)),
            'openDate' => $pos['openDate'] ?? null, 'exitDate' => gmdate('c'),
            'instrument' => $pos['instrument'] ?? '', 'direction' => $pos['direction'] ?? '',
            'strike' => $pos['strike'] ?? null, 'type' => $pos['type'] ?? '',
            'expiry' => $pos['expiry'] ?? '', 'expiryDisplay' => $pos['expiryDisplay'] ?? '',
            'lots' => $pos['lots'] ?? 1, 'lotSize' => $pos['lotSize'] ?? 0, 'qty' => $qty,
            'entryPremium' => $pos['entryPremium'] ?? $eff, 'effectiveEntry' => $eff,
            'exitPremium' => cron_r2($px), 'cost' => $entryCost, 'proceeds' => $proceeds,
            'pnl' => $pnl,
            'pnlPct' => $eff > 0 ? (int)round((($px - $eff) / $eff) * 100) : 0,
            'holdMinutes' => $holdMin, 'outcome' => $outcome, 'status' => $outcome,
            'partial' => false, 'confidence' => $pos['confidence'] ?? null,
            'regime' => $pos['regime'] ?? '', 'gex' => $pos['gex'] ?? '',
            'patternId' => $pos['patternId'] ?? null, 'slippage' => 0,
            'closedBy' => 'server-cron',
        ];
        $closed++; $changed = true;
        cron_log($out, sprintf('  VIRTUAL CLOSED %s %s %s%s @ %.2f -> %s (P&L %s)',
            $pos['instrument'] ?? '?', $pos['direction'] ?? '?', $pos['strike'] ?? '?',
            $pos['type'] ?? '', $px, $outcome, $pnl));
        auditLog('order', 'cron_virtual_exit', ['instrument' => $pos['instrument'] ?? '',
            'outcome' => $outcome, 'pnl' => $pnl, 'holdMinutes' => $holdMin]);
    }

    if (!$changed) return ['closed' => 0, 'marked' => 0];

    $s['active'] = $keep;
    $exposure = 0.0;
    foreach ($keep as $p) {
        $exposure += ((float)($p['currentPremium'] ?? 0) ?: (float)($p['effectiveEntry'] ?? 0)) * (float)($p['qty'] ?? 0);
    }
    $s['capital'] = cron_r2((float)($s['cash'] ?? 0) + $exposure);
    if ($s['capital'] > (float)($s['peakCapital'] ?? 0)) $s['peakCapital'] = $s['capital'];
    $today = $ist['date'];
    $curve = $s['equityCurve'] ?? [];
    if ($curve && ($curve[count($curve) - 1]['date'] ?? '') === $today) {
        $curve[count($curve) - 1]['equity'] = $s['capital'];
    } else {
        $curve[] = ['date' => $today, 'equity' => $s['capital']];
    }
    $s['equityCurve'] = $curve;
    // rev is an optimistic-concurrency counter; bump it so a stale browser tab
    // re-hydrates instead of overwriting what the server just did.
    $s['rev'] = (int)($s['rev'] ?? 0) + 1;
    $s['updatedAt'] = gmdate('c');
    $s['lastServerTick'] = gmdate('c');
    kAtomicWrite($file, json_encode($s));
    return ['closed' => $closed, 'marked' => $marked];
}

// ─── paper desk: monitor + square off ────────────────────────────────────────
/**
 * Same job for the automated paper book, following js/paper-trading.js: circuit
 * breaker, stop, T1/T2 per exitTarget, and the 15:20 forced close. Closed trades are
 * appended to trades.jsonl in the exact record shape proxy.php writes, because that
 * file is the reconciliation ground truth kernel.php replays.
 */
function cron_monitor_paper(array &$out): array {
    $file = kPaperState();
    $s = cron_read_json($file);
    if (!$s || empty($s['active'])) return ['closed' => 0, 'marked' => 0];

    $ist = eng_ist_now();
    $closed = 0; $marked = 0; $changed = false;
    $keep = [];

    foreach ($s['active'] as $pos) {
        $q = cron_option_ltp($pos['instrument'] ?? '', $pos['expiry'] ?? null,
                             $pos['strike'] ?? 0, $pos['type'] ?? 'CE');
        if ($q) {
            $pos['currentPremium'] = cron_r2($q['ltp']);
            $pos['lastQuoteAt'] = $q['quoteAt'];
            if ($q['ltp'] > (float)($pos['peakPremium'] ?? 0)) $pos['peakPremium'] = cron_r2($q['ltp']);
            $marked++; $changed = true;
        }

        $entry = (float)($pos['entryPremium'] ?? 0);
        $lotQty = (float)($pos['lotSize'] ?? 0) * (float)($pos['sizeMultiplier'] ?? 1);
        $openTs = !empty($pos['openDate']) ? strtotime((string)$pos['openDate']) : time();
        $openKey = eng_ist_now($openTs)['date'];

        $px = null; $outcome = null;
        if ($openKey !== $ist['date'])            { $outcome = 'EOD_SQUAREOFF_STALE'; }
        elseif ($ist['min'] >= CRON_EOD_MIN)      { $outcome = 'EOD_SQUAREOFF'; }

        if ($outcome !== null) {
            $px = (float)($pos['currentPremium'] ?? 0) ?: $entry;
        } elseif ($q) {
            $ltp = (float)$q['ltp'];
            $stop = (float)($pos['stopPremium'] ?? 0);
            $t1 = (float)($pos['t1Premium'] ?? 0);
            $t2 = (float)($pos['t2Premium'] ?? 0);
            $target = (($pos['exitTarget'] ?? 'T1') === 'T2' && $t2 > 0) ? $t2 : $t1;
            $elapsed = time() - $openTs;
            $ddPct = $entry > 0 ? (($entry - $ltp) / $entry) * 100 : 0;
            if ($ddPct >= 40 && $elapsed <= 300)      { $px = $ltp; $outcome = 'CIRCUIT_BREAKER'; }
            elseif ($stop > 0 && $ltp <= $stop)       { $px = $stop; $outcome = 'STOP_LOSS'; }
            elseif ($target > 0 && $ltp >= $target)   { $px = $ltp; $outcome = (($pos['exitTarget'] ?? 'T1') === 'T2') ? 'WIN_T2' : 'WIN_T1'; }
        }

        if ($outcome === null) { $keep[] = $pos; continue; }

        $pnl = cron_r2(($px - $entry) * $lotQty);
        $pnlPct = $entry > 0 ? (int)round((($px - $entry) / $entry) * 100) : 0;
        $holdMin = (int)round((time() - $openTs) / 60);

        $s['capital'] = cron_r2((float)($s['capital'] ?? 0) + (float)($pos['cost'] ?? 0) + $pnl);
        $s['totalPnl'] = cron_r2((float)($s['totalPnl'] ?? 0) + $pnl);
        if ((float)$s['capital'] > (float)($s['peakCapital'] ?? 0)) $s['peakCapital'] = $s['capital'];
        $s['consecutiveLosses'] = $pnl < 0 ? (int)($s['consecutiveLosses'] ?? 0) + 1 : 0;

        // Ground-truth append — identical field set to handlePaperTrades().
        $record = [
            'ts'         => time(),
            'instrument' => strtoupper((string)($pos['instrument'] ?? '')),
            'direction'  => $pos['direction'] ?? '',
            'strike'     => (float)($pos['strike'] ?? 0),
            'type'       => $pos['type'] ?? '',
            'strategy'   => $pos['strategy'] ?? '',
            'entry'      => (float)$entry,
            'exit'       => (float)cron_r2($px),
            'pnl'        => (float)$pnl,
            'pnlPct'     => (float)$pnlPct,
            'outcome'    => $outcome,
            'holdMinutes'=> $holdMin,
            'confidence' => (float)($pos['modelConfidence'] ?? $pos['confidence'] ?? 0),
            'regime'     => $pos['regime'] ?? '',
            'gex'        => $pos['gex'] ?? '',
            'slippage'   => (float)($pos['slippage'] ?? 0),
            'lotSize'    => (int)($pos['lotSize'] ?? 0),
            'closedBy'   => 'server-cron',
        ];
        @file_put_contents(kPaperTrades(), json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

        $pos['exitPremium'] = cron_r2($px);
        $pos['exitDate'] = gmdate('c');
        $pos['status'] = $outcome;
        $pos['pnl'] = $pnl;
        $pos['pnlPct'] = $pnlPct;
        $pos['holdMinutes'] = $holdMin;
        $s['trades'][] = $pos;

        $closed++; $changed = true;
        cron_log($out, sprintf('  PAPER CLOSED %s %s %s%s @ %.2f -> %s (P&L %s)',
            $pos['instrument'] ?? '?', $pos['direction'] ?? '?', $pos['strike'] ?? '?',
            $pos['type'] ?? '', $px, $outcome, $pnl));
        auditLog('order', 'cron_paper_exit', ['instrument' => $pos['instrument'] ?? '',
            'outcome' => $outcome, 'pnl' => $pnl, 'holdMinutes' => $holdMin]);
    }

    if (!$changed) return ['closed' => 0, 'marked' => 0];

    $s['active'] = $keep;
    $today = $ist['date'];
    $curve = $s['equityCurve'] ?? [];
    if ($curve && ($curve[count($curve) - 1]['date'] ?? '') === $today) {
        $curve[count($curve) - 1]['equity'] = $s['capital'];
    } else {
        $curve[] = ['date' => $today, 'equity' => $s['capital']];
    }
    $s['equityCurve'] = $curve;
    $s['updatedAt'] = gmdate('c');
    $s['lastServerTick'] = gmdate('c');
    kAtomicWrite($file, json_encode($s));
    return ['closed' => $closed, 'marked' => $marked];
}

// ─── setup helper ────────────────────────────────────────────────────────────
/**
 * ?setup=1 — report the absolute paths this host actually uses, and the ready-to-paste
 * cron lines built from them.
 *
 * Guessing the docroot and the PHP binary is the step where cPanel cron setup usually
 * fails silently: a wrong path produces no error anywhere visible, just a job that
 * never runs. The server knows both, so it should say so rather than leave you to
 * infer them. Token-protected, since it discloses filesystem paths.
 */
if (($_GET['setup'] ?? '') === '1') {
    $script = __DIR__ . '/cron.php';
    // PHP_BINARY is the CLI binary under cron, but under mod_php/FPM it is the web
    // server, so it is reported as a hint rather than an answer.
    $binHint = (PHP_SAPI === 'cli' && PHP_BINARY) ? PHP_BINARY : (PHP_BINDIR . '/php');
    $candidates = array_values(array_unique(array_filter([
        $binHint, '/usr/local/bin/php', '/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php',
    ])));
    $existing = array_values(array_filter($candidates, fn($p) => @is_file($p)));
    $php = $existing[0] ?? '/usr/local/bin/php';

    // Derive the hour range from the timezone cron ACTUALLY uses, rather than assuming.
    // This host reports php=UTC but system=Asia/Kolkata, and cron follows the system: a
    // UTC-shaped range like "3-10" would have run 03:00-10:59 IST, i.e. before the open
    // and finishing by 11:00, missing most of the session and the 15:20 square-off
    // entirely — while looking perfectly plausible in the cPanel UI.
    $sysTzName = (function () {
        $tz = @readlink('/etc/localtime');
        if (is_string($tz) && preg_match('#zoneinfo/(.+)$#', $tz, $m)) return $m[1];
        $f = @file_get_contents('/etc/timezone');
        if (is_string($f) && trim($f) !== '') return trim($f);
        return 'UTC';
    })();
    try { $sysTz = new DateTimeZone($sysTzName); } catch (Throwable $e) { $sysTz = new DateTimeZone('UTC'); $sysTzName = 'UTC (unresolved)'; }
    $istTz = new DateTimeZone('Asia/Kolkata');
    $localise = function (string $hhmm) use ($istTz, $sysTz) {
        $d = new DateTime('today ' . $hhmm, $istTz);
        $d->setTimezone($sysTz);
        return ['h' => (int)$d->format('G'), 'm' => (int)$d->format('i'), 'day' => $d->format('Y-m-d')];
    };
    $openL  = $localise('09:15');
    $closeL = $localise('15:30');
    $eodL   = $localise('15:20');
    // If the offset pushes the window across midnight, an hour range is not expressible
    // as one line; say so rather than emit something subtly broken.
    $wraps = $closeL['h'] < $openL['h'];
    $hourRange = $wraps ? null : ($openL['h'] === $closeL['h'] ? (string)$openL['h'] : $openL['h'] . '-' . $closeL['h']);

    $res = [
        'status' => true,
        'setup' => true,
        'scriptPath' => $script,
        'phpBinary' => $php,
        'phpBinaryCandidatesFound' => $existing,
        'sapi' => PHP_SAPI,
        'phpVersion' => PHP_VERSION,
        // PHP's timezone and the SYSTEM timezone are different things, and cron obeys the
        // system one. Reporting only the PHP setting would invite an hour-range that is
        // silently wrong — so both are shown, and the recommended schedule below avoids
        // depending on either.
        'phpTimezone' => date_default_timezone_get(),
        'systemTimezone' => (function () {
            $tz = @readlink('/etc/localtime');
            if (is_string($tz) && preg_match('#zoneinfo/(.+)$#', $tz, $m)) return $m[1];
            $f = @file_get_contents('/etc/timezone');
            if (is_string($f) && trim($f) !== '') return trim($f);
            return 'unknown';
        })(),
        'serverTimeUtc' => gmdate('Y-m-d H:i') . ' UTC',
        'serverTimeIst' => eng_ist_now()['date'] . ' ' . eng_ist_now()['time'] . ' IST',
        'cronTimezoneUsed' => $sysTzName,
        'marketWindowInCronTimezone' => sprintf('%02d:%02d-%02d:%02d',
            $openL['h'], $openL['m'], $closeL['h'], $closeL['m']),
        'cronLines' => [
            // RECOMMENDED. No hour range at all, so it cannot be broken by a timezone
            // mistake. cron.php resolves IST itself via Asia/Kolkata and self-gates:
            // outside market hours with no open position it returns in milliseconds
            // without touching the broker API.
            'recommended_timezone_proof' =>
                "* * * * * $php $script >/dev/null 2>&1",
            // Narrower alternative, built from the DETECTED cron timezone above.
            'alt_market_hours_only' => $hourRange === null
                ? 'not expressible as a single hour range in ' . $sysTzName
                  . ' (the IST session crosses midnight there) — use recommended_timezone_proof'
                : "*/1 $hourRange * * 1-5 $php $script >/dev/null 2>&1",
            'alt_eod_squareoff' => sprintf('%d,%d,%d %d * * 1-5 %s %s >/dev/null 2>&1',
                max(0, $eodL['m'] - 5), $eodL['m'], min(59, $eodL['m'] + 5), $eodL['h'], $php, $script),
        ],
        'note' => 'Use recommended_timezone_proof. Every minute, all day, and the script decides '
                . 'what to do — which removes the most common setup failure: an hour range '
                . 'written for the wrong timezone. NOTE this host reports phpTimezone=UTC but '
                . 'systemTimezone=' . $sysTzName . ', and cron obeys the SYSTEM one, so a '
                . 'UTC-shaped range here would silently run at the wrong times. The alt_* lines '
                . 'above are already converted into ' . $sysTzName . ' (session = '
                . sprintf('%02d:%02d-%02d:%02d', $openL['h'], $openL['m'], $closeL['h'], $closeL['m'])
                . ' local). CLI runs need no token.',
    ];
    if (PHP_SAPI === 'cli') { echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; }
    else { echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); }
    exit;
}

// ─── self-test ───────────────────────────────────────────────────────────────
/**
 * ?selftest=alert — send one clearly-labelled TEST email and report whether the host
 * accepted it.
 *
 * Worth having permanently: shared hosting frequently disables or throttles mail(), and
 * the failure is silent. Without this you would only discover that alerts do not work
 * by never receiving one — which is indistinguishable from "no signal fired", the exact
 * ambiguity this whole worker exists to remove. Bypasses the cooldown (it is a test) but
 * does NOT write the dedup file, so it cannot suppress a real alert afterwards.
 */
if (($_GET['selftest'] ?? '') === 'alert') {
    $subject = '[TEST] FNO Signal Pro server worker — alert path check';
    $ist = eng_ist_now();
    $html = '<div style="font-family:system-ui,sans-serif;max-width:560px">'
        . '<h2 style="margin:0 0 8px">Test alert — not a trading signal</h2>'
        . '<p style="font-size:14px">If you are reading this, the server-side worker can send '
        . 'email, so real signal alerts will reach you with no browser open.</p>'
        . '<p style="font-size:13px;color:#555">Sent ' . htmlspecialchars($ist['date'] . ' ' . $ist['time'])
        . ' IST by api/cron.php on ads.sanctify.co.in.</p>'
        . '<p style="font-size:12px;color:#8a6d3b;background:#fcf8e3;border:1px solid #faebcc;'
        . 'padding:8px;border-radius:4px">This message contains no trade recommendation.</p></div>';
    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: FNO Signal Pro <signals@ads.sanctify.co.in>\r\n";
    $headers .= "Reply-To: signals@ads.sanctify.co.in\r\n";
    $sent = @mail('consecrating@gmail.com', $subject, $html, $headers);
    $res = [
        'status' => (bool)$sent,
        'selftest' => 'alert',
        'mailAccepted' => (bool)$sent,
        'ist' => $ist['date'] . ' ' . $ist['time'] . ' IST',
        'note' => $sent
            ? 'The host accepted the message. Check the inbox and the spam folder.'
            : 'mail() returned false — this host is refusing to send. Alerts will NOT arrive; '
              . 'use an SMTP relay or enable mail in the hosting panel.',
    ];
    if (PHP_SAPI === 'cli') { echo json_encode($res, JSON_PRETTY_PRINT) . "\n"; }
    else { echo json_encode($res, JSON_PRETTY_PRINT); }
    exit;
}

// ─── main ────────────────────────────────────────────────────────────────────
$out = [
    'status' => true, 'ranAt' => gmdate('c'), 'ist' => null, 'marketOpen' => false,
    'signals' => [], 'alertsSent' => 0, 'virtual' => null, 'paper' => null, 'log' => [],
];

$lockFh = cron_acquire_lock(kDir() . '/cron.lock');
if (!$lockFh) {
    $out['status'] = false;
    $out['error'] = 'another cron tick is still running';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $ist = eng_ist_now();
    $out['ist'] = $ist['date'] . ' ' . $ist['time'] . ' IST';
    $isWeekday = $ist['day'] >= 1 && $ist['day'] <= 5;
    $marketOpen = $isWeekday && $ist['min'] >= CRON_OPEN_MIN && $ist['min'] <= CRON_CLOSE_MIN;
    $out['marketOpen'] = $marketOpen;
    cron_log($out, "cron tick {$out['ist']} — market " . ($marketOpen ? 'OPEN' : 'closed'));

    // ── 1. EXITS FIRST, ALWAYS ────────────────────────────────────────────────
    // Runs even when the market is shut, because a position left open from an earlier
    // session must be squared off the moment the worker next runs. Protecting an open
    // position takes priority over looking for new ones.
    $out['virtual'] = cron_monitor_virtual($out);
    $out['paper'] = cron_monitor_paper($out);
    cron_log($out, sprintf('  positions: virtual marked=%d closed=%d · paper marked=%d closed=%d',
        $out['virtual']['marked'], $out['virtual']['closed'],
        $out['paper']['marked'], $out['paper']['closed']));

    // ── 2. SIGNALS ────────────────────────────────────────────────────────────
    if (!$marketOpen) {
        cron_log($out, '  outside market hours — no signal generation');
    } else {
        $safetyOk = true;
        try { $safetyOk = kExecutionAllowed(); } catch (Throwable $e) { $safetyOk = true; }

        foreach (CRON_INSTRUMENTS as $sym) {
            $c = cron_candles($sym);
            if (!$c) { cron_log($out, "  $sym: no candles"); continue; }

            $snap = [
                'symbol' => $sym,
                'highs' => $c['highs'], 'lows' => $c['lows'], 'closes' => $c['closes'],
                'ltp' => (float)end($c['closes']),
                'mtfBias' => cron_mtf_bias($sym),
            ];
            $g = cron_get(['action' => 'gex', 'symbol' => $sym, 'cb' => (string)time()]);
            if (!empty($g['status']) && !empty($g['data'])) {
                $snap['gex'] = $g['data'];
                if (!empty($g['data']['pcr']) && (float)$g['data']['pcr'] > 0) {
                    $snap['pcr'] = (float)$g['data']['pcr'];
                }
            }

            $sig = eng_generate_signal($snap, ['confidenceThreshold' => CRON_CONF_THRESHOLD]);
            $sig['atmStrike'] = eng_atm_strike($sym, (float)$sig['ltp']);
            $sig['lotSize'] = eng_lot_size($sym);
            $sig['source'] = 'server-cron';
            $sig['engineBuild'] = '2026-09-08-mr-v2';
            $out['signals'][$sym] = [
                'direction' => $sig['direction'], 'confidence' => $sig['confidence'],
                'netScore' => $sig['netScore'], 'ltp' => $sig['ltp'], 'adx' => $sig['adx'],
                'regime' => $sig['regime'], 'rsi' => $sig['rsi'],
                'atmStrike' => $sig['atmStrike'], 'optionType' => $sig['optionType'],
                'vetoes' => $sig['vetoes'], 'reason' => $sig['reason'],
            ];
            cron_log($out, sprintf('  %-11s %-8s conf=%2d net=%s adx=%s %s',
                $sym, $sig['direction'], (int)$sig['confidence'], $sig['netScore'],
                $sig['adx'] ?? '—', $sig['vetoes'] ? '· ' . explode(':', $sig['vetoes'][0])[0] : ''));

            if ($sig['direction'] !== 'NO_TRADE') {
                if (!$safetyOk) {
                    cron_log($out, '  alert withheld: safety state blocks execution');
                } elseif (cron_send_alert($sig, $out)) {
                    $out['alertsSent']++;
                }
            }
        }

        // Persist for the dashboards, so any page can show what the SERVER last saw
        // even if this browser has been closed for hours.
        //
        // Written TWICE, deliberately. brain-data/ is denied to the web by .htaccess
        // (it holds paper state and full signal history), and that protection must not
        // be weakened — so the canonical copy stays there while a public snapshot is
        // published beside validation-summary.json for the front end to read. The
        // snapshot carries only what signal.html already renders publicly, so it adds
        // no exposure.
        $signalsPayload = json_encode([
            'generatedAt' => gmdate('c'),
            'ist' => $out['ist'],
            'engineBuild' => '2026-09-08-mr-v2',
            'approach' => 'mean-reversion',
            'confidenceThreshold' => CRON_CONF_THRESHOLD,
            'marketOpen' => $marketOpen,
            'signals' => $out['signals'],
            'note' => 'Generated server-side by api/cron.php, independent of any browser '
                    . 'session. Exits (stop/target/trail/schedule/15:20 square-off) are managed '
                    . 'autonomously; new positions are NOT opened autonomously by design.',
        ], JSON_UNESCAPED_SLASHES);
        kAtomicWrite(kDir() . '/server-signals.json', $signalsPayload);
        kAtomicWrite(__DIR__ . '/../server-signals.json', $signalsPayload);
    }

    // Audit only ticks that DID something.
    //
    // The recommended schedule is every minute all day (timezone-proof), which would
    // otherwise write ~1,440 identical "nothing happened" rows a day straight into the
    // audit trail — burying the exits and alerts that the trail exists to preserve, and
    // growing the file for no reason. Individual exits and alerts already log themselves,
    // so this records the tick-level summary only when it carries information. A
    // heartbeat file, rewritten every tick, covers "is it alive?" instead.
    $didSomething = ($out['virtual']['closed'] ?? 0) > 0
                 || ($out['paper']['closed'] ?? 0) > 0
                 || $out['alertsSent'] > 0;
    if ($didSomething) {
        auditLog('recovery', 'cron_tick', [
            'marketOpen' => $marketOpen,
            'signals' => count($out['signals']),
            'alerts' => $out['alertsSent'],
            'virtualClosed' => $out['virtual']['closed'] ?? 0,
            'paperClosed' => $out['paper']['closed'] ?? 0,
        ]);
    }

    kAtomicWrite(kDir() . '/cron-heartbeat.json', json_encode([
        'lastTickAt' => gmdate('c'),
        'ist' => $out['ist'],
        'marketOpen' => $marketOpen,
        'signalsScanned' => count($out['signals']),
        'positionsMarked' => ($out['virtual']['marked'] ?? 0) + ($out['paper']['marked'] ?? 0),
    ], JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) {
    $out['status'] = false;
    $out['error'] = $e->getMessage();
    cron_log($out, 'ERROR: ' . $e->getMessage());
} finally {
    cron_release_lock($lockFh);
}

if (PHP_SAPI === 'cli') {
    echo "\ndone: " . count($out['signals']) . " signals, {$out['alertsSent']} alerts, "
       . ($out['virtual']['closed'] ?? 0) . " virtual + " . ($out['paper']['closed'] ?? 0) . " paper exits\n";
} else {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
