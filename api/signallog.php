<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * signallog.php — permanent, date-wise log of confirmed signals
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * WHAT THIS IS FOR
 * Until now a confirmed signal left no durable trace you could go back and read. The raw
 * ingest files (brain-data/YYYY-MM-DD.jsonl) hold every 30-second re-evaluation of every
 * instrument — 6,191 rows across 21 days, 96% of them NO_TRADE — which is telemetry, not a
 * record of decisions. The pattern store holds fingerprint bands, not readable signals. So
 * "what did the system actually call on Tuesday, and at what time?" had no answer.
 *
 * This is that answer: one row per confirmed signal, stamped with IST date and time, stored
 * one file per trading day.
 *
 * DESIGN NOTES
 *  · Append-only per day. Nothing rewrites history.
 *  · Deduplicated on patternId, which is stable for 10 minutes by PatternMemory.record()'s
 *    own dedup. Without this the page's 30-second refresh would write ~20 identical rows for
 *    a single signal and the log would be useless for counting anything.
 *  · Outcomes are NOT copied in. They are joined at READ time from the pattern store by
 *    patternId, so a label recorded hours later by cron or another tab appears here
 *    automatically. Copying would have meant either stale rows or rewriting an append-only
 *    file.
 *  · Both writers are supported and attributed: the browser (which knows God Mode conviction
 *    and the desk's decision) and the server worker (which runs with no browser open). The
 *    `source` field keeps them distinguishable, because they evaluate different gate sets.
 *  · Lives under brain-data/, which .htaccess denies to the web, so it is only reachable
 *    through the read API.
 *
 * Pure functions. Safe to include.
 */

require_once __DIR__ . '/godstate.php';

function sl_dir() {
    $d = __DIR__ . '/../brain-data/signals';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function sl_ist_parts($ts = null) {
    $t = $ts === null ? time() : (int)$ts;
    $d = new DateTime('@' . $t);
    $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i:s'),
            'label' => $d->format('d M Y, H:i:s') . ' IST', 'dow' => $d->format('D')];
}

function sl_file(string $istDate) { return sl_dir() . '/' . $istDate . '.jsonl'; }

/** Only allow well-formed dates near the filesystem — never user input in a path. */
function sl_valid_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
}

/**
 * Append one confirmed signal.
 *
 * Returns ['ok'=>bool, 'deduped'=>bool]. A dedup is a success, not a failure — it means the
 * signal is already on record.
 */
function sl_append(array $in) {
    $ts = isset($in['ts']) ? (int)$in['ts'] : time();
    // Accept milliseconds from JS as well as seconds.
    if ($ts > 20000000000) $ts = (int)round($ts / 1000);
    $p = sl_ist_parts($ts);

    $rec = [
        'ts'          => $ts,
        'istDate'     => $p['date'],
        'istTime'     => $p['time'],
        'instrument'  => strtoupper((string)($in['instrument'] ?? '')),
        'direction'   => strtoupper((string)($in['direction'] ?? '')),
        'optionType'  => $in['optionType'] ?? null,
        'strike'      => isset($in['strike']) ? (float)$in['strike'] : null,
        'spot'        => isset($in['spot']) ? (float)$in['spot'] : null,
        'premium'     => isset($in['premium']) ? (float)$in['premium'] : null,
        'expiry'      => $in['expiry'] ?? null,
        // Engine layer
        'confidence'  => isset($in['confidence']) ? (float)$in['confidence'] : null,
        'netScore'    => isset($in['netScore']) ? (float)$in['netScore'] : null,
        'agreement'   => isset($in['agreement']) ? (float)$in['agreement'] : null,
        'adx'         => isset($in['adx']) ? (float)$in['adx'] : null,
        'rsi'         => isset($in['rsi']) ? (float)$in['rsi'] : null,
        'regime'      => $in['regime'] ?? null,
        // God Mode layer (absent for server-generated rows — it does not evaluate it)
        'godConfidence'     => isset($in['godConfidence']) ? (float)$in['godConfidence'] : null,
        'adaptiveThreshold' => isset($in['adaptiveThreshold']) ? (float)$in['adaptiveThreshold'] : null,
        'godActionable'     => isset($in['godActionable']) ? (bool)$in['godActionable'] : null,
        'calibrated'        => isset($in['calibrated']) ? (bool)$in['calibrated'] : null,
        // Execution
        'deskDecision' => $in['deskDecision'] ?? null,   // OPENED | REFUSED | null
        'deskReason'   => $in['deskReason'] ?? null,
        'patternId'    => isset($in['patternId']) ? (string)$in['patternId'] : null,
        'source'       => in_array($in['source'] ?? '', ['browser', 'server-cron'], true)
                          ? $in['source'] : 'browser',
    ];
    if ($rec['instrument'] === '' || $rec['direction'] === '' || $rec['direction'] === 'NO_TRADE') {
        return ['ok' => false, 'reason' => 'not_a_confirmed_signal'];
    }

    $file = sl_file($rec['istDate']);

    // Dedup on patternId within the day.
    if ($rec['patternId'] !== null && $rec['patternId'] !== '' && is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && (string)($r['patternId'] ?? '') === $rec['patternId']) {
                return ['ok' => true, 'deduped' => true, 'istDate' => $rec['istDate']];
            }
        }
    }
    // No patternId (server rows have none): fall back to a 10-minute window on the same
    // instrument + direction + strike, which is the same interval PatternMemory uses.
    if (($rec['patternId'] === null || $rec['patternId'] === '') && is_file($file)) {
        foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $r = json_decode($line, true);
            if (!is_array($r)) continue;
            if ($ts - (int)($r['ts'] ?? 0) > 600) break;
            if (($r['instrument'] ?? '') === $rec['instrument']
                && ($r['direction'] ?? '') === $rec['direction']
                && (float)($r['strike'] ?? -1) === (float)($rec['strike'] ?? -2)
                && ($r['source'] ?? '') === $rec['source']) {
                return ['ok' => true, 'deduped' => true, 'istDate' => $rec['istDate']];
            }
        }
    }

    $ok = @file_put_contents($file, json_encode($rec) . "\n", FILE_APPEND | LOCK_EX);
    return ['ok' => $ok !== false, 'deduped' => false, 'istDate' => $rec['istDate'],
            'istTime' => $rec['istTime']];
}

/** Dates that have a log, newest first. */
function sl_dates() {
    $out = [];
    foreach (glob(sl_dir() . '/*.jsonl') ?: [] as $f) {
        $d = basename($f, '.jsonl');
        if (sl_valid_date($d)) $out[] = $d;
    }
    rsort($out);
    return $out;
}

/**
 * Read one day, joining outcomes from the pattern store by patternId so a label recorded
 * later shows up without ever rewriting the log.
 */
function sl_read(string $istDate) {
    if (!sl_valid_date($istDate)) return ['rows' => [], 'error' => 'bad_date'];
    $file = sl_file($istDate);
    if (!is_file($file)) return ['rows' => []];

    $labels = [];
    $state = gs_read();
    foreach (($state['patterns'] ?? []) as $p) {
        if (!empty($p['outcome'])) {
            $k = (string)($p['id'] ?? ($p['ts'] ?? ''));
            if ($k !== '') $labels[$k] = $p;
        }
    }

    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $pid = (string)($r['patternId'] ?? '');
        if ($pid !== '' && isset($labels[$pid])) {
            $r['outcome']      = $labels[$pid]['outcome'];
            $r['outcomePnlPct']= $labels[$pid]['pnl_pct'] ?? null;
            $r['holdMinutes']  = $labels[$pid]['duration_min'] ?? null;
            $r['closedBy']     = $labels[$pid]['closed_by'] ?? null;
        } else {
            $r['outcome'] = $r['outcome'] ?? null;
        }
        $rows[] = $r;
    }
    usort($rows, fn($a, $b) => (int)($a['ts'] ?? 0) <=> (int)($b['ts'] ?? 0));
    return ['rows' => $rows];
}

/** Counts for the header strip. Kept descriptive — no win-rate claims on a handful of rows. */
function sl_summary(array $rows) {
    $s = ['total' => count($rows), 'buy' => 0, 'sell' => 0, 'byInstrument' => [],
          'bySource' => [], 'opened' => 0, 'refused' => 0, 'withOutcome' => 0,
          'wins' => 0, 'losses' => 0];
    foreach ($rows as $r) {
        if (($r['direction'] ?? '') === 'BUY') $s['buy']++;
        elseif (($r['direction'] ?? '') === 'SELL') $s['sell']++;
        $i = $r['instrument'] ?? '?';
        $s['byInstrument'][$i] = ($s['byInstrument'][$i] ?? 0) + 1;
        $src = $r['source'] ?? '?';
        $s['bySource'][$src] = ($s['bySource'][$src] ?? 0) + 1;
        if (($r['deskDecision'] ?? '') === 'OPENED') $s['opened']++;
        elseif (($r['deskDecision'] ?? '') === 'REFUSED') $s['refused']++;
        if (!empty($r['outcome'])) {
            $s['withOutcome']++;
            if ((float)($r['outcomePnlPct'] ?? 0) > 0) $s['wins']++; else $s['losses']++;
        }
    }
    return $s;
}
