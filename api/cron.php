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
require_once __DIR__ . '/godstate.php';
require_once __DIR__ . '/signallog.php';

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

    // "CANDIDATE", not "BUY".
    //
    // A real alert went out reading "[BUY] MIDCPNIFTY CE 14500 - 70%" and the automated
    // desk then did nothing, because the browser stack returned NO_TRADE: God Mode
    // conviction was 50% (below the 60% bar) and the score had decayed by the time the
    // desk looked. The alert was not wrong about the engine — it was wrong to be phrased
    // as an instruction, because this worker evaluates strictly LESS than the desk does.
    // Anything worded as a decision that the system itself then declines destroys trust
    // in every later alert, so the subject now says what it actually is.
    $subject = sprintf('[CANDIDATE %s] %s %d %s — engine %d%%',
        $sig['direction'], $sig['symbol'], $atm,
        $sig['direction'] === 'BUY' ? 'CE' : 'PE', (int)$sig['confidence']);

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
        // State the boundary of what was actually checked. The desk applies gates this
        // worker deliberately does not implement (they need live browser state or a full
        // God Mode port), so it can legitimately refuse this candidate. Saying so here
        // means a refusal reads as the system working rather than as a contradiction.
        . '<p style="font-size:12px;color:#31708f;background:#eaf4fb;border:1px solid #bce8f1;'
        . 'padding:8px;border-radius:4px;margin-top:12px"><b>This is a candidate, not a '
        . 'confirmed trade.</b> The server checked the engine gates only: reversion score '
        . 'inside ±30–50, ADX ≥ 25, RSI limits, and confidence. It did <b>not</b> check God '
        . 'Mode conviction, whether the exact listed contract has a fresh live quote, or the '
        . 'exposure and cooldown caps. The automated paper desk applies all of those and may '
        . 'well decline this — if it does, that is the desk working correctly, not a fault. '
        . 'Scores also decay as price moves, so a setup can be gone within minutes.</p>'
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

// ─── outcome feedback into God Mode ──────────────────────────────────────────
/**
 * Label the God Mode pattern behind a position the SERVER just closed.
 *
 * Without this, the cron worker was quietly destroying the system's only source of
 * learning. Every position it squared off — which is the entire reason it exists — closed
 * without ever labelling the pattern that produced it, so the outcome was gone. Combined
 * with the lease no-op on the browser side, that is how the live store reached 425 patterns
 * against 5 outcomes, leaving calibration permanently dormant at a 20-sample threshold.
 *
 * Failures are logged, never fatal: a missing pattern link must not stop a square-off.
 */
function cron_feed_outcome(array $pos, string $outcome, $pnlPct, $holdMin, array &$out): void {
    $pid = $pos['patternId'] ?? null;
    if (!$pid) {
        cron_log($out, '    (no patternId on this position — outcome cannot be attributed)');
        return;
    }
    $res = gs_record_outcome($pid, $outcome, $pnlPct, null, $holdMin, 'server-cron');
    if (!empty($res['ok'])) {
        cron_log($out, sprintf('    outcome fed to God Mode: %s -> %s (%d completed total%s)',
            substr((string)$pid, 0, 24), $outcome, (int)$res['completedTotal'],
            $res['completedTotal'] >= 20 ? ', CALIBRATION ACTIVE' : ', needs 20'));
        auditLog('order', 'god_outcome_recorded', ['patternId' => (string)$pid,
            'outcome' => $outcome, 'completedTotal' => $res['completedTotal'], 'by' => 'server-cron']);
    } else {
        cron_log($out, '    outcome NOT recorded: ' . ($res['reason'] ?? 'unknown'));
    }
}

/**
 * Sweep the desks' closed-trade histories and label any pattern still missing its outcome.
 *
 * Fixing the two known holes is not enough on its own. The loop had been leaking silently
 * for weeks with nothing to reveal it, and the only reason it was ever noticed was a manual
 * count of the store. A close path that forgets to label — a new desk, a manual exit, a
 * failed request, a tab closed mid-write — puts the system straight back into the state it
 * was just rescued from, and just as invisibly.
 *
 * So the authority is inverted: the trade log is ground truth, and every tick reconciles the
 * pattern store against it. A missed label becomes a delay of at most one minute instead of
 * permanent data loss. This also recovers outcomes already lost, which is how the fix gets
 * verified against real data rather than a fixture.
 *
 * Idempotent by construction: gs_record_outcome() refuses to relabel.
 */
function cron_reconcile_outcomes(array &$out): array {
    $repaired = 0; $checked = 0;

    // Virtual desk — trades live inside its state file.
    $vs = cron_read_json(kDir() . '/virtual/state.json');
    foreach (($vs['trades'] ?? []) as $t) {
        $pid = $t['patternId'] ?? null;
        $oc  = $t['outcome'] ?? ($t['status'] ?? null);
        if (!$pid || !$oc) continue;
        $checked++;
        $r = gs_record_outcome($pid, $oc, $t['pnlPct'] ?? null, null, $t['holdMinutes'] ?? null,
                               'reconcile-virtual');
        if (!empty($r['ok'])) {
            $repaired++;
            cron_log($out, sprintf('    RECOVERED virtual outcome: %s %s -> %s (%d completed)',
                $t['instrument'] ?? '?', substr((string)$pid, 0, 22), $oc, (int)$r['completedTotal']));
        }
    }

    // Paper desk — trades are append-only JSONL.
    $tf = kPaperTrades();
    if (is_file($tf)) {
        foreach (file($tf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $t = json_decode($line, true);
            if (!is_array($t)) continue;
            $pid = $t['patternId'] ?? null;
            $oc  = $t['outcome'] ?? null;
            if (!$pid || !$oc) continue;
            $checked++;
            $r = gs_record_outcome($pid, $oc, $t['pnlPct'] ?? null, null, $t['holdMinutes'] ?? null,
                                   'reconcile-paper');
            if (!empty($r['ok'])) {
                $repaired++;
                cron_log($out, sprintf('    RECOVERED paper outcome: %s %s -> %s (%d completed)',
                    $t['instrument'] ?? '?', substr((string)$pid, 0, 22), $oc, (int)$r['completedTotal']));
            }
        }
    }

    if ($repaired > 0) {
        auditLog('recovery', 'god_outcomes_reconciled', ['repaired' => $repaired, 'checked' => $checked]);
    }
    return ['checked' => $checked, 'repaired' => $repaired];
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
        cron_feed_outcome($pos, $outcome, $eff > 0 ? (($px - $eff) / $eff) * 100 : 0, $holdMin, $out);
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
            // Carried through so the reconciliation sweep can join this trade back to its
            // pattern even if the direct labelling call above failed.
            'patternId'  => isset($pos['patternId']) ? (string)$pos['patternId'] : null,
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
        cron_feed_outcome($pos, $outcome, $pnlPct, $holdMin, $out);
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

    // ── 1b. RECONCILE THE LEARNING LOOP ──────────────────────────────────────
    // Runs every tick, market open or not, because an unlabelled pattern is a permanent
    // loss of the scarcest data in the system and the cost of checking is a file read.
    $out['outcomes'] = cron_reconcile_outcomes($out);
    $out['loop'] = gs_outcome_stats();
    cron_log($out, sprintf('  learning loop: %d/%d patterns labelled%s · repaired %d this tick',
        $out['loop']['completed'], $out['loop']['patterns'],
        $out['loop']['calibrationActive'] ? ' · CALIBRATION ACTIVE' : ' · needs 20 to calibrate',
        $out['outcomes']['repaired']));

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
                // Log it before alerting, so the permanent record exists even if mail fails.
                // Marked source=server-cron because this path evaluates the engine gates only
                // — the log must not imply God Mode conviction was ever checked.
                $logged = sl_append([
                    'ts' => time(),
                    'instrument' => $sym,
                    'direction' => $sig['direction'],
                    'optionType' => $sig['optionType'],
                    'strike' => $sig['atmStrike'],
                    'spot' => $sig['ltp'],
                    'confidence' => $sig['confidence'],
                    'netScore' => $sig['netScore'],
                    'agreement' => $sig['agreement'] ?? null,
                    'adx' => $sig['adx'],
                    'rsi' => $sig['rsi'],
                    'regime' => $sig['regime'],
                    'deskDecision' => null,
                    'deskReason' => 'Server-side candidate; the automated desk evaluates '
                                  . 'God Mode conviction and live-quote gates separately.',
                    'source' => 'server-cron',
                ]);
                if (!empty($logged['ok']) && empty($logged['deduped'])) {
                    cron_log($out, "  logged confirmed signal to {$logged['istDate']} at {$logged['istTime']} IST");
                }

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
            // Be explicit about the gate boundary so no consumer treats these as
            // desk-confirmed decisions.
            'gatesEvaluated' => ['conviction_window_30_50', 'adx_min_25', 'rsi_extremes',
                                 'confidence_threshold', 'late_session_cutoff'],
            'gatesNotEvaluated' => ['god_mode_conviction', 'live_quote_availability',
                                    'option_chain_liquidity', 'exposure_and_correlation_caps',
                                    'reentry_cooldown'],
            'directionIsCandidateOnly' => true,
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
