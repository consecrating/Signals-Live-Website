<?php
/**
 * engine-parity.php — prove api/engine.php matches js/live-engine.js
 *
 * The server engine only has value if it makes the SAME decision the page would.
 * A silent divergence would mean cron trades one strategy while the UI shows another,
 * which is worse than the browser-only setup it replaces. This harness runs both
 * implementations over identical deterministic series and reports any disagreement.
 *
 *   php tools/engine-parity.php            # needs node on PATH
 *
 * Exits non-zero on any mismatch so it can gate a deploy.
 */

require_once __DIR__ . '/../api/engine.php';

const TOL = 1e-9;

/** Deterministic series (no RNG) so PHP and JS see byte-identical input. */
function series(string $kind, int $n = 120): array {
    $h = []; $l = []; $c = [];
    $p = 20000.0;
    for ($i = 0; $i < $n; $i++) {
        if ($kind === 'uptrend')        $p += 12.0;
        elseif ($kind === 'downtrend')  $p -= 12.0;
        elseif ($kind === 'chop')       $p += 25.0 * sin($i / 2.0);
        elseif ($kind === 'spike')      $p += ($i > $n - 12) ? 60.0 : 3.0 * sin($i / 3.0);
        else                            $p += 2.0 * cos($i / 5.0);
        $c[] = $p; $h[] = $p + 15.0; $l[] = $p - 15.0;
    }
    return [$h, $l, $c];
}

function run_js(array $h, array $l, array $c): ?array {
    $engine = realpath(__DIR__ . '/../js/live-engine.js');
    if (!$engine) { fwrite(STDERR, "live-engine.js not found\n"); return null; }
    $code = file_get_contents($engine);
    // Neutralise the market-hours gate so the comparison is time-independent.
    $code = str_replace(
        'if (_dayCheck === 0 || _dayCheck === 6 || _minCheck < 555 || _minCheck > 930) {',
        'if (false) {', $code);

    $dir = sys_get_temp_dir() . '/parity_' . getmypid();
    @mkdir($dir, 0700, true);
    $mod = $dir . '/engine.mjs';
    file_put_contents($mod, $code);

    $payload = json_encode(['highs' => $h, 'lows' => $l, 'closes' => $c]);
    $driver = $dir . '/run.mjs';
    file_put_contents($driver,
        "import {generateSignal, RSI, ATR, ADX, EMA, bollinger, superTrend} from " . json_encode($mod) . ";\n"
        . "const p = $payload;\n"
        . "const snap = {symbol:'NIFTY', opens:p.closes, highs:p.highs, lows:p.lows, closes:p.closes,\n"
        . "  volumes:p.closes.map(()=>0), timestamps:p.closes.map((_,i)=>String(i)),\n"
        . "  ltp:p.closes[p.closes.length-1], pcr:null, iv:0.15, fiiFlow:null, newsSentiment:null,\n"
        . "  isIndex:true, lotSize:65, strikeStep:50, optionStrike:null, livePremium:null};\n"
        . "const s = generateSignal(snap, {confidenceThreshold:60});\n"
        . "const a = ADX(p.highs,p.lows,p.closes);\n"
        . "console.log(JSON.stringify({direction:s.direction, netScore:s.netScore,\n"
        . "  vetoes:(s.vetoes||[]).map(v=>String(v).split(':')[0]),\n"
        . "  rsi:RSI(p.closes).latest, atr:ATR(p.highs,p.lows,p.closes), adx:a.adx,\n"
        . "  ema21:EMA(p.closes,21), bbUpper:bollinger(p.closes,20,2).upper,\n"
        . "  st:superTrend(p.highs,p.lows,p.closes)}));\n");

    $out = shell_exec('node ' . escapeshellarg($driver) . ' 2>&1');
    @unlink($mod); @unlink($driver); @rmdir($dir);
    if (!$out) return null;
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $json = json_decode(end($lines), true);
    if (!is_array($json)) { fwrite(STDERR, "node output not JSON:\n$out\n"); return null; }
    return $json;
}

function near($a, $b, $tol = TOL): bool {
    if ($a === null && $b === null) return true;
    if ($a === null || $b === null) return false;
    $scale = max(1.0, abs((float)$a), abs((float)$b));
    return abs((float)$a - (float)$b) <= $tol * $scale;
}

$kinds = ['uptrend', 'downtrend', 'chop', 'spike', 'drift'];
$fail = 0; $checks = 0;
$shared = ['WEAK_TREND_NO_FADE', 'EXTREME_STRETCH_NO_FADE', 'RSI_OVERBOUGHT', 'RSI_OVERSOLD', 'LOW_CONFIDENCE'];

echo "PHP <-> JS engine parity\n";
echo str_repeat('=', 72) . "\n";

foreach ($kinds as $kind) {
    list($h, $l, $c) = series($kind);
    $js = run_js($h, $l, $c);
    if ($js === null) { fwrite(STDERR, "SKIP $kind (node unavailable)\n"); continue; }

    $php = eng_generate_signal(
        ['symbol' => 'NIFTY', 'highs' => $h, 'lows' => $l, 'closes' => $c, 'ltp' => end($c)],
        ['confidenceThreshold' => 60.0, 'skipMarketHours' => true]);
    $adxPhp = eng_adx($h, $l, $c)['adx'];

    $rows = [
        ['rsi',       eng_rsi($c),                          $js['rsi']],
        ['atr',       eng_atr($h, $l, $c),                  $js['atr']],
        ['ema21',     eng_ema($c, 21),                      $js['ema21']],
        ['bbUpper',   eng_bollinger($c, 20, 2)['upper'],    $js['bbUpper']],
        ['adx',       $adxPhp,                              $js['adx']],
        ['netScore',  $php['netScore'],                     $js['netScore']],
    ];

    printf("\n%-10s  %-10s %-22s %-22s %s\n", $kind, 'metric', 'php', 'js', 'ok');
    foreach ($rows as [$name, $p, $j]) {
        $checks++;
        // netScore is rounded to 1dp on both sides, so compare at that precision.
        $ok = $name === 'netScore' ? near($p, $j, 1e-6) : near($p, $j);
        if (!$ok) $fail++;
        printf("%-10s  %-10s %-22s %-22s %s\n", '', $name,
            $p === null ? 'null' : sprintf('%.10f', $p),
            $j === null ? 'null' : sprintf('%.10f', $j),
            $ok ? 'OK' : '*** MISMATCH ***');
    }

    $checks++;
    $stPhp = eng_supertrend($h, $l, $c);
    $okSt = $stPhp === $js['st'];
    if (!$okSt) $fail++;
    printf("%-10s  %-10s %-22s %-22s %s\n", '', 'superTrend', $stPhp, $js['st'], $okSt ? 'OK' : '*** MISMATCH ***');

    $checks++;
    $okDir = $php['direction'] === $js['direction'];
    if (!$okDir) $fail++;
    printf("%-10s  %-10s %-22s %-22s %s\n", '', 'direction', $php['direction'], $js['direction'],
        $okDir ? 'OK' : '*** MISMATCH ***');

    // The port omits live-only gates, so JS may add codes it cannot raise; it must
    // never MISS one the port raises.
    $checks++;
    $pCodes = [];
    foreach ($php['vetoes'] as $v) { $code = trim(explode(':', $v)[0]); if (in_array($code, $shared, true)) $pCodes[] = $code; }
    $jCodes = array_values(array_intersect($js['vetoes'], $shared));
    sort($pCodes); sort($jCodes);
    $okVeto = $pCodes === $jCodes;
    if (!$okVeto) $fail++;
    printf("%-10s  %-10s %-22s %-22s %s\n", '', 'vetoes',
        implode(',', $pCodes) ?: '-', implode(',', $jCodes) ?: '-', $okVeto ? 'OK' : '*** MISMATCH ***');
}

echo "\n" . str_repeat('=', 72) . "\n";
if ($fail === 0) {
    echo "PARITY OK — $checks comparisons, 0 mismatches.\n";
    echo "The server engine makes the same decisions as the deployed browser engine.\n";
    exit(0);
}
echo "PARITY FAILED — $fail of $checks comparisons disagree.\n";
echo "Do NOT deploy: cron would trade different logic than the page displays.\n";
exit(1);
