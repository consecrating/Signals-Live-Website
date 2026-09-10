<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * engine.php — server-side mean-reversion signal engine
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS FILE EXISTS
 * The signal engine lived only in the browser (js/live-engine.js), so with no tab
 * open nothing happened: no signals, no alerts, no stop/target checks, no 15:20
 * square-off. "Signals stop working when the laptop is off" was not a bug in the
 * engine, it was the architecture. This is the same engine, on the server, callable
 * from cron.php with no browser involved.
 *
 * THIS IS A PORT, NOT A REWRITE
 * Every indicator reproduces js/live-engine.js exactly — the Wilder smoothing seeds
 * in RSI/ADX, the SuperTrend band flip, and the `|| 1` divide-by-zero fallbacks are
 * all mirrored. If the server computed even slightly different numbers it would
 * generate different trades than the page shows, which is worse than not running at
 * all. tools/engine-parity.php pins this against the deployed JS.
 *
 * Pure functions only: no output, no headers, no side effects. Safe to include.
 *
 * Kept deliberately OUT of scope (they depend on live browser/lease state and would
 * be look-ahead or duplication here): God Mode's adaptive threshold, the option-chain
 * liquidity veto, and the live-premium execution gates. cron.php applies its own
 * execution checks and labels anything it opens as server-originated.
 */

if (!defined('ENGINE_MR_DIR_TH'))  define('ENGINE_MR_DIR_TH', 30.0);   // conviction floor
if (!defined('ENGINE_MR_EXTREME')) define('ENGINE_MR_EXTREME', 50.0);  // above = re-pricing, not stretch
if (!defined('ENGINE_ADX_MIN'))    define('ENGINE_ADX_MIN', 25.0);     // sub-25 measured negative expectancy

// ─── helpers ─────────────────────────────────────────────────────────────────
function eng_clamp($v, $lo, $hi) { return max($lo, min($hi, $v)); }

function eng_sma(array $v, int $p) {
    $n = count($v);
    if ($n < $p) return null;
    return array_sum(array_slice($v, -$p)) / $p;
}

/** EMA seeded with the SMA of the first p values, matching the JS emaSeries(). */
function eng_ema(array $v, int $p) {
    $n = count($v);
    if ($n < $p) return null;
    $k = 2.0 / ($p + 1.0);
    $e = array_sum(array_slice($v, 0, $p)) / $p;
    for ($i = $p; $i < $n; $i++) $e = $v[$i] * $k + $e * (1 - $k);
    return $e;
}

/** Wilder RSI. Mirrors the JS seeding and its `al == 0 -> 100` guard. */
function eng_rsi(array $c, int $p = 14) {
    $n = count($c);
    if ($n < $p + 1) return null;
    $g = 0.0; $l = 0.0;
    for ($i = 1; $i <= $p; $i++) {
        $d = $c[$i] - $c[$i - 1];
        if ($d > 0) $g += $d; else $l -= $d;
    }
    $ag = $g / $p; $al = $l / $p; $latest = null;
    for ($i = $p + 1; $i < $n; $i++) {
        $d = $c[$i] - $c[$i - 1];
        $ag = ($ag * ($p - 1) + ($d > 0 ? $d : 0.0)) / $p;
        $al = ($al * ($p - 1) + ($d < 0 ? -$d : 0.0)) / $p;
        $latest = ($al == 0) ? 100.0 : 100.0 - 100.0 / (1.0 + $ag / $al);
    }
    if ($latest === null) {
        $rs = ($al == 0) ? 100.0 : $ag / $al;
        $latest = 100.0 - 100.0 / (1.0 + $rs);
    }
    return $latest;
}

function eng_atr(array $h, array $l, array $c, int $p = 14) {
    $n = count($c);
    if ($n < $p + 1) return null;
    $tr = [];
    for ($i = 1; $i < $n; $i++) {
        $tr[] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
    }
    $a = array_sum(array_slice($tr, 0, $p)) / $p;
    for ($i = $p; $i < count($tr); $i++) $a = ($a * ($p - 1) + $tr[$i]) / $p;
    return $a;
}

/** ATR with the JS fallback to 1.2% of spot when it cannot be computed. */
function eng_atr_proxy(array $h, array $l, array $c, $ltp) {
    $a = eng_atr($h, $l, $c);
    if ($a !== null && is_finite($a) && $a > 0) return $a;
    return ($ltp && is_finite($ltp)) ? $ltp * 0.012 : 0.0;
}

/** Wilder ADX/DI. Keeps the JS `(x || 1)` denominators so both sides agree. */
function eng_adx(array $h, array $l, array $c, int $p = 14) {
    $n = count($h);
    $none = ['adx' => null, 'plusDI' => null, 'minusDI' => null];
    if ($n < 2 * $p + 1) return $none;
    $tr = []; $plusDM = []; $minusDM = [];
    for ($i = 1; $i < $n; $i++) {
        $up = $h[$i] - $h[$i - 1];
        $down = $l[$i - 1] - $l[$i];
        $plusDM[]  = ($up > $down && $up > 0) ? $up : 0.0;
        $minusDM[] = ($down > $up && $down > 0) ? $down : 0.0;
        $tr[] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
    }
    $smooth = function (array $arr) use ($p) {
        $s = array_sum(array_slice($arr, 0, $p));
        $out = [$s];
        for ($i = $p; $i < count($arr); $i++) { $s = $s - $s / $p + $arr[$i]; $out[] = $s; }
        return $out;
    };
    $st = $smooth($tr); $sp = $smooth($plusDM); $sm = $smooth($minusDM);
    $dx = [];
    for ($i = 0; $i < count($st); $i++) {
        $den = $st[$i] ?: 1.0;
        $pDI = 100.0 * $sp[$i] / $den;
        $mDI = 100.0 * $sm[$i] / $den;
        $s = ($pDI + $mDI) ?: 1.0;
        $dx[] = 100.0 * abs($pDI - $mDI) / $s;
    }
    if (count($dx) < $p) return $none;
    $adx = array_sum(array_slice($dx, 0, $p)) / $p;
    for ($i = $p; $i < count($dx); $i++) $adx = ($adx * ($p - 1) + $dx[$i]) / $p;
    $li = count($st) - 1;
    $d = $st[$li] ?: 1.0;
    return ['adx' => $adx, 'plusDI' => 100.0 * $sp[$li] / $d, 'minusDI' => 100.0 * $sm[$li] / $d];
}

function eng_bollinger(array $c, int $p = 20, float $sd = 2.0) {
    if (count($c) < $p) return ['upper' => null, 'mid' => null, 'lower' => null];
    $s = array_slice($c, -$p);
    $mid = array_sum($s) / $p;
    $var = 0.0;
    foreach ($s as $x) $var += ($x - $mid) ** 2;
    $std = sqrt($var / $p);
    return ['upper' => $mid + $sd * $std, 'mid' => $mid, 'lower' => $mid - $sd * $std];
}

function eng_supertrend(array $h, array $l, array $c, int $p = 10, float $m = 3.0) {
    $n = count($c);
    if ($n < $p + 1) return 'bearish';
    $tr = [];
    for ($i = 1; $i < $n; $i++) {
        $tr[] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
    }
    $atr = array_fill(0, count($tr), 0.0);
    $a = array_sum(array_slice($tr, 0, $p)) / $p;
    $atr[$p - 1] = $a;
    for ($i = $p; $i < count($tr); $i++) { $a = ($a * ($p - 1) + $tr[$i]) / $p; $atr[$i] = $a; }
    $dir = 1; $pu = 0.0; $pl = 0.0;
    for ($i = $p; $i < $n; $i++) {
        $av = $atr[$i - 1];
        $hl = ($h[$i] + $l[$i]) / 2.0;
        $bu = $hl + $m * $av; $bl = $hl - $m * $av;
        $u  = ($bu < $pu || $c[$i - 1] > $pu) ? $bu : $pu;
        $lo = ($bl > $pl || $c[$i - 1] < $pl) ? $bl : $pl;
        if ($dir === 1 && $c[$i] < $lo) $dir = -1;
        elseif ($dir === -1 && $c[$i] > $u) $dir = 1;
        $pu = $u; $pl = $lo;
    }
    return $dir === 1 ? 'bullish' : 'bearish';
}

// ─── signal ──────────────────────────────────────────────────────────────────
/**
 * Mean-reversion signal. Mirrors generateSignal() in js/live-engine.js.
 *
 * $snapshot: ['symbol','highs','lows','closes','ltp'] plus optional
 *            'pcr','ivSkew','gex','mtfBias','dte'.
 * $opts:     ['confidenceThreshold' => 60, 'skipMarketHours' => false]
 *
 * Returns an array shaped like the JS signal object for the fields cron.php and the
 * dashboards consume.
 */
function eng_generate_signal(array $snapshot, array $opts = []) {
    $symbol    = $snapshot['symbol'] ?? 'NIFTY';
    $highs     = $snapshot['highs'] ?? [];
    $lows      = $snapshot['lows'] ?? [];
    $closes    = $snapshot['closes'] ?? [];
    $threshold = (float)($opts['confidenceThreshold'] ?? 60.0);

    $out = [
        'symbol' => $symbol, 'timestamp' => gmdate('c'), 'direction' => 'NO_TRADE',
        'confidence' => 0.0, 'netScore' => 0.0, 'ltp' => 0.0, 'atr' => null, 'rsi' => null,
        'adx' => null, 'regime' => 'Unknown', 'components' => null, 'vetoes' => [],
        'agreement' => 0.0, 'optionType' => null, 'reason' => null,
    ];

    if (count($closes) < 30) {
        $out['vetoes'][] = 'INSUFFICIENT_DATA';
        $out['reason'] = 'Insufficient data';
        return $out;
    }

    // Market-hours gate: 09:15-15:30 IST, Mon-Fri. cron.php can bypass it when it
    // needs a read-only evaluation outside the session (e.g. an EOD square-off pass).
    if (empty($opts['skipMarketHours'])) {
        $ist = eng_ist_now();
        if ($ist['day'] === 0 || $ist['day'] === 6 || $ist['min'] < 555 || $ist['min'] > 930) {
            $out['reason'] = 'Market closed';
            $out['vetoes'][] = 'MARKET_CLOSED';
            return $out;
        }
    }

    $len = count($closes);
    $ltp = (float)($snapshot['ltp'] ?? $closes[$len - 1]);
    $out['ltp'] = $ltp;

    $e21    = eng_ema($closes, 21);
    $atrMR  = eng_atr($highs, $lows, $closes) ?: $ltp * 0.012;
    $rsi    = eng_rsi($closes);
    $out['atr'] = $atrMR;
    $out['rsi'] = $rsi;

    $comp = [];

    // Reversion (25): stretch from the 21-EMA in ATRs, inverted.
    $stretch = $atrMR > 0 && $e21 !== null ? ($ltp - $e21) / $atrMR : 0.0;
    $comp['reversion'] = ['bias' => eng_clamp(-$stretch / 2.2, -1, 1), 'weight' => 25.0];

    // Extreme fade (20): 20-bar range position + Bollinger tags.
    $pb = 0.0;
    if ($len >= 20) {
        $h20 = max(array_slice($highs, -20));
        $l20 = min(array_slice($lows, -20));
        $rng = $h20 - $l20;
        if ($rng > 0) {
            $pos = ($ltp - $l20) / $rng;
            if ($pos >= 0.85) $pb -= 0.4;
            elseif ($pos <= 0.15) $pb += 0.4;
            else $pb += (0.5 - $pos) * 0.5;
        }
    }
    $bb = eng_bollinger($closes, 20, 2.0);
    if ($bb['upper'] !== null) {
        if ($ltp >= $bb['upper']) $pb -= 0.35;
        elseif ($ltp <= $bb['lower']) $pb += 0.35;
    }
    $comp['extremeFade'] = ['bias' => eng_clamp($pb, -1, 1), 'weight' => 20.0];

    // Options flow (20): PCR / IV skew + dealer positioning, GEX only when reliable.
    $ob = 0.0;
    $gex = $snapshot['gex'] ?? null;
    $gexOk = $gex && (!isset($gex['reliable']) || $gex['reliable'] === true || $gex['reliable'] === 1);
    $g = $gexOk ? $gex : null;
    if (!empty($snapshot['pcr']) && $snapshot['pcr'] > 0) {
        $ob += eng_clamp(((float)$snapshot['pcr'] - 1.0) * 1.1, -0.45, 0.45);
    } elseif (isset($snapshot['ivSkew']) && $snapshot['ivSkew'] !== null) {
        $ob += eng_clamp(-((float)$snapshot['ivSkew']) / 6.0, -0.5, 0.5);
    }
    if ($g) {
        $ap = eng_atr_proxy($highs, $lows, $closes, $ltp);
        if (!empty($g['flip']) && $ap > 0) {
            $ob += eng_clamp((($ltp - (float)$g['flip']) / $ap) * 0.12, -0.3, 0.3);
        }
        if ($ap > 0) {
            if (!empty($g['callWall']) && abs((float)$g['callWall'] - $ltp) / $ap < 1.2 && $ltp < (float)$g['callWall']) $ob -= 0.2;
            if (!empty($g['putWall'])  && abs($ltp - (float)$g['putWall'])  / $ap < 1.2 && $ltp > (float)$g['putWall'])  $ob += 0.2;
        }
    }
    $comp['options'] = ['bias' => eng_clamp($ob, -1, 1), 'weight' => 20.0];

    // Volume (0 for indices): NSE publishes none, so it carries no directional weight.
    // Production measures option-chain volume as a liquidity gate instead.
    $comp['volume'] = ['bias' => 0.0, 'weight' => 0.0, 'available' => false];

    // RSI fade (10): overbought => fade short, oversold => fade long.
    $mb = 0.0;
    if ($rsi !== null) {
        if ($rsi >= 70) $mb -= 0.6;
        elseif ($rsi >= 62) $mb -= 0.3;
        elseif ($rsi <= 30) $mb += 0.6;
        elseif ($rsi <= 38) $mb += 0.3;
    }
    $comp['rsiFade'] = ['bias' => eng_clamp($mb, -1, 1), 'weight' => 10.0];

    $comp['institutional'] = ['bias' => eng_clamp((float)($snapshot['fiiFlow'] ?? 0) > 0 ? 0.4
                                        : ((float)($snapshot['fiiFlow'] ?? 0) < 0 ? -0.4 : 0.0), -1, 1),
                              'weight' => 10.0];
    $comp['news'] = ['bias' => eng_clamp((float)($snapshot['newsSentiment'] ?? 0), -1, 1), 'weight' => 5.0];

    // Net, with weights renormalised to 100 so an unavailable component shrinks the
    // model rather than silently rescaling every score.
    $wSum = 0.0;
    foreach ($comp as $c) $wSum += $c['weight'];
    $wSum = $wSum ?: 1.0;
    $norm = 100.0 / $wSum;
    $net = 0.0;
    foreach ($comp as $c) $net += $c['bias'] * $c['weight'] * $norm;
    $net = eng_clamp($net, -100, 100);

    // Agreement across components that actually HAVE an opinion.
    $dom = $net >= 0 ? 1 : -1;
    $opinions = 0; $agreeing = 0;
    foreach ($comp as $c) {
        if ($c['weight'] > 0 && $c['bias'] != 0) {
            $opinions++;
            if (($c['bias'] > 0 ? 1 : -1) === $dom) $agreeing++;
        }
    }
    $agree = $opinions ? $agreeing / $opinions : 0.0;

    $out['components'] = $comp;
    $out['netScore']   = round($net * 10) / 10;
    $out['agreement']  = round($agree * 1000) / 1000;

    $dir  = $net > ENGINE_MR_DIR_TH ? 'BUY' : ($net < -ENGINE_MR_DIR_TH ? 'SELL' : 'NO_TRADE');
    $conf = min(99.0, abs($net) * 0.62 + $agree * 42.0);

    $adxObj = eng_adx($highs, $lows, $closes);
    $adx = $adxObj['adx'];
    $out['adx'] = $adx === null ? null : round($adx);
    $out['regime'] = $adx === null ? 'Unknown' : ($adx >= 25 ? 'Trending' : ($adx >= 18 ? 'Developing' : 'Choppy'));

    if ($adx !== null) {
        if ($adx >= 28) $conf = min(99.0, $conf + 10);
        elseif ($adx >= 22) $conf = min(99.0, $conf + 5);
        elseif ($adx < 16) $conf = max(0.0, $conf - 12);
    }

    // Higher-timeframe alignment is a confidence modifier, not a block: a fade runs
    // counter to the short-term trend by design.
    if (!empty($snapshot['mtfBias'])) {
        $mtf = (float)$snapshot['mtfBias'];
        $agrees = ($mtf > 0 && $dir === 'BUY') || ($mtf < 0 && $dir === 'SELL');
        $conf = eng_clamp($conf + ($agrees ? 4 : -10), 0, 99);
    }

    // Gate: regime. Sub-25 ADX bands measured negative expectancy.
    if ($dir !== 'NO_TRADE' && $adx !== null && $adx < ENGINE_ADX_MIN) {
        $out['vetoes'][] = 'WEAK_TREND_NO_FADE: ADX ' . round($adx) . ' (<' . (int)ENGINE_ADX_MIN
            . ') — no directional energy to over-extend, so there is nothing stretched to fade.';
        $dir = 'NO_TRADE';
    }

    // Gate: an extreme stretch is re-pricing, not exhaustion.
    if ($dir !== 'NO_TRADE' && abs($net) >= ENGINE_MR_EXTREME) {
        $out['vetoes'][] = 'EXTREME_STRETCH_NO_FADE: reversion score ' . round($net)
            . ' is beyond the fade window (±' . (int)ENGINE_MR_EXTREME . ') — usually genuine re-pricing.';
        $dir = 'NO_TRADE';
    }

    // Retained hard risk filters.
    if ($rsi !== null) {
        if ($rsi > 85 && $dir === 'BUY')  { $out['vetoes'][] = 'RSI_OVERBOUGHT'; $dir = 'NO_TRADE'; }
        if ($rsi < 15 && $dir === 'SELL') { $out['vetoes'][] = 'RSI_OVERSOLD';   $dir = 'NO_TRADE'; }
    }

    // Late-session: no new entries after 15:15 IST (the desks square off at 15:20).
    if ($dir !== 'NO_TRADE' && empty($opts['skipMarketHours'])) {
        $ist = eng_ist_now();
        if ($ist['min'] >= 915) {
            $out['vetoes'][] = 'LATE_SESSION: no new entries after 15:15 IST.';
            $dir = 'NO_TRADE';
        }
    }

    if ($dir !== 'NO_TRADE' && $conf < $threshold) {
        $out['vetoes'][] = 'LOW_CONFIDENCE: ' . round($conf) . '% < ' . round($threshold) . '%';
        $dir = 'NO_TRADE';
    }

    $out['direction']  = $dir;
    $out['confidence'] = round($conf);
    $out['optionType'] = $dir === 'BUY' ? 'CE' : ($dir === 'SELL' ? 'PE' : null);
    if ($dir === 'NO_TRADE' && $out['reason'] === null) {
        $out['reason'] = $out['vetoes'] ? $out['vetoes'][0] : 'Neutral bias';
    }
    return $out;
}

/** IST wall-clock parts, independent of server timezone. */
function eng_ist_now($ts = null) {
    $t = $ts === null ? time() : $ts;
    $d = new DateTime('@' . $t);
    $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return [
        'date' => $d->format('Y-m-d'),
        'time' => $d->format('H:i'),
        'min'  => (int)$d->format('H') * 60 + (int)$d->format('i'),
        'day'  => (int)$d->format('w'),   // 0 = Sunday
        'dt'   => $d,
    ];
}

/** Strike step + lot size, mirroring the front-end constants. */
function eng_strike_step($symbol) {
    $m = ['NIFTY' => 50, 'BANKNIFTY' => 100, 'FINNIFTY' => 50, 'MIDCPNIFTY' => 25, 'SENSEX' => 100];
    return $m[strtoupper($symbol)] ?? 50;
}
function eng_lot_size($symbol) {
    $m = ['NIFTY' => 65, 'BANKNIFTY' => 30, 'FINNIFTY' => 60, 'MIDCPNIFTY' => 120, 'SENSEX' => 20];
    return $m[strtoupper($symbol)] ?? 50;
}
function eng_atm_strike($symbol, $spot) {
    $step = eng_strike_step($symbol);
    return (int)(round($spot / $step) * $step);
}
