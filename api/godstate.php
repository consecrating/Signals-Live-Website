<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * godstate.php — merge-only writes to brain-data/god-state.json
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS EXISTS
 * God Mode is built to learn from realised outcomes, and it was not learning. The live
 * store held 425 patterns and exactly 5 outcomes — 420 permanently unlabelled — so every
 * component that depends on evidence was inert: getCalibration() needs 20 completed
 * records and had 5, historicalWinRate needs 20 similar matches and returned 0 on every
 * signal, and the learned-weight table had zero buckets.
 *
 * Three separate holes caused it, all of them write-path problems:
 *
 *   1. api/cron.php closes positions server-side in PHP and never touched god-state.json,
 *      so every square-off it performed discarded the outcome. That was a regression
 *      introduced with the cron worker.
 *   2. godSaveState() in js/god-mode.js returns early unless the tab owns the executor
 *      lease. A desk tab without the lease would call recordOutcome(), mutate memory, and
 *      silently persist nothing. This is provable in the live data: the one virtual trade
 *      carrying a patternId (BANKNIFTY, MANUAL_WIN, +2%) matches a stored pattern whose
 *      outcome is still null.
 *   3. The ?action=god_state POST path is a REPLACE-ALL snapshot write, fenced behind that
 *      same lease precisely because it can clobber. Correct for snapshots, but it made
 *      the narrow act of labelling one pattern depend on tab ownership.
 *
 * So outcome recording is separated from snapshot writing. Everything here is a targeted
 * MERGE: locate one pattern by id, fill its outcome fields, update the learning counters.
 * It cannot erase another writer's work, which is what the lease fence protects against —
 * therefore it does not need the lease, and an outcome is never lost again for the want of
 * one.
 *
 * Pure functions, no output, safe to include from proxy.php and cron.php alike.
 */

if (!defined('GS_MAX_PATTERNS')) define('GS_MAX_PATTERNS', 2000);

function gs_dir()  { $d = __DIR__ . '/../brain-data'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function gs_file() { return gs_dir() . '/god-state.json'; }

/**
 * Serialise the read-modify-write.
 *
 * The lock is a SEPARATE file rather than god-state.json itself, because the write is
 * completed by rename() for atomicity — locking the original inode would release the
 * guard the moment it is replaced.
 */
function gs_with_lock(callable $fn) {
    $lock = gs_file() . '.lock';
    $fh = @fopen($lock, 'c');
    if (!$fh) return ['ok' => false, 'reason' => 'lock_unavailable'];
    if (!flock($fh, LOCK_EX)) { fclose($fh); return ['ok' => false, 'reason' => 'lock_timeout']; }
    try {
        return $fn();
    } finally {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

function gs_read() {
    $f = gs_file();
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return null;
    $s = json_decode($raw, true);
    return is_array($s) ? $s : null;
}

function gs_write(array $state) {
    $state['updatedAt'] = date('c');
    $f = gs_file();
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, json_encode($state)) === false) return false;
    return @rename($tmp, $f);
}

/**
 * Evict with a preference for keeping evidence.
 *
 * The old cap was a blind `array_slice(-500)`, which is the worst possible rule here: a
 * completed outcome is the scarcest thing in this system (5 of 425), while pending records
 * arrive constantly and 46% of them are NO_TRADE observations that can never complete at
 * all. Blind tail-slicing therefore evicted irreplaceable labelled records to make room
 * for unlabelled ones. Completed records are now kept first, and only pending ones are
 * trimmed — oldest first.
 */
function gs_prune_patterns(array $patterns, int $max = GS_MAX_PATTERNS) {
    if (count($patterns) <= $max) return $patterns;
    $done = []; $pending = [];
    foreach ($patterns as $p) {
        if (!empty($p['outcome'])) $done[] = $p; else $pending[] = $p;
    }
    if (count($done) >= $max) return array_slice($done, -$max);
    $room = $max - count($done);
    $kept = array_merge($done, array_slice($pending, -$room));
    // Restore chronological order so "last N" semantics elsewhere still hold.
    usort($kept, fn($a, $b) => (int)($a['ts'] ?? 0) <=> (int)($b['ts'] ?? 0));
    return $kept;
}

/**
 * Mirror of PatternMemory._learnFromOutcome() in js/god-mode.js.
 *
 * Reproduced rather than approximated (same dimension list, same ±0.02/−0.03 steps, same
 * 0.5–1.5 clamp, same totalAnalyzed increment) so a server-recorded outcome leaves the
 * store byte-comparable to a browser-recorded one. If the two drifted, the learning table
 * would depend on WHICH closer happened to fire, which is not a property anyone could
 * reason about later.
 *
 * Note this table is still diagnostic: getWeightAdjustment() is read for display only and
 * multiplies nothing in the score. Keeping it correct costs little and means the data is
 * trustworthy if it is ever wired in.
 */
function gs_learn_from_outcome(array &$state, array $record) {
    if (!isset($state['learnings']) || !is_array($state['learnings'])) {
        $state['learnings'] = ['weights' => [], 'vetoes' => [], 'totalAnalyzed' => 0];
    }
    if (!isset($state['learnings']['weights']) || !is_array($state['learnings']['weights'])) {
        $state['learnings']['weights'] = [];
    }
    $key = ($record['regime'] ?? '') . '|' . ($record['gex_regime'] ?? '');
    if (!isset($state['learnings']['weights'][$key]) || !is_array($state['learnings']['weights'][$key])) {
        $state['learnings']['weights'][$key] = [];
    }
    $isWin = (float)($record['pnl_pct'] ?? 0) > 0;
    foreach (['pcr_band', 'trend_dir', 'momentum_zone', 'gex_flip_zone', 'adx_band'] as $d) {
        $val = (float)($record[$d] ?? 0);
        $dir = $record['direction'] ?? '';
        $agreed = ($dir === 'BUY' && $val > 0) || ($dir === 'SELL' && $val < 0);
        if (!isset($state['learnings']['weights'][$key][$d])) $state['learnings']['weights'][$key][$d] = 1.0;
        if ($agreed && $isWin) {
            $state['learnings']['weights'][$key][$d] = min(1.5, $state['learnings']['weights'][$key][$d] + 0.02);
        } elseif ($agreed && !$isWin) {
            $state['learnings']['weights'][$key][$d] = max(0.5, $state['learnings']['weights'][$key][$d] - 0.03);
        }
    }
    $state['learnings']['totalAnalyzed'] = (int)($state['learnings']['totalAnalyzed'] ?? 0) + 1;
}

/**
 * Label one pattern with a realised outcome. Idempotent: re-recording the same pattern is
 * reported rather than double-counted, so a retrying cron tick cannot inflate the learning
 * counters.
 *
 * $id matches on `id` first and falls back to `ts`, because 292 of the 425 live records
 * predate stable ids and are only addressable by timestamp.
 */
function gs_record_outcome($id, $outcome, $pnlPct, $moveAtr = null, $durationMin = null, $closedBy = 'server') {
    if ($id === null || $id === '' || $outcome === null || $outcome === '') {
        return ['ok' => false, 'reason' => 'missing_id_or_outcome'];
    }
    return gs_with_lock(function () use ($id, $outcome, $pnlPct, $moveAtr, $durationMin, $closedBy) {
        $state = gs_read();
        if ($state === null) return ['ok' => false, 'reason' => 'no_state'];
        if (!isset($state['patterns']) || !is_array($state['patterns'])) {
            return ['ok' => false, 'reason' => 'no_patterns'];
        }
        $target = (string)$id;
        $foundAt = null;
        foreach ($state['patterns'] as $i => $p) {
            $pid = (string)($p['id'] ?? ($p['ts'] ?? ''));
            if ($pid === $target || (string)($p['ts'] ?? '') === $target) { $foundAt = $i; break; }
        }
        if ($foundAt === null) return ['ok' => false, 'reason' => 'pattern_not_found'];
        if (!empty($state['patterns'][$foundAt]['outcome'])) {
            return ['ok' => false, 'reason' => 'already_recorded',
                    'outcome' => $state['patterns'][$foundAt]['outcome']];
        }

        $state['patterns'][$foundAt]['outcome']      = (string)$outcome;
        $state['patterns'][$foundAt]['pnl_pct']      = $pnlPct === null ? null : (float)$pnlPct;
        $state['patterns'][$foundAt]['move_atr']     = $moveAtr === null ? null : (float)$moveAtr;
        $state['patterns'][$foundAt]['duration_min'] = $durationMin === null ? null : (int)$durationMin;
        // Provenance: which closer labelled this. Without it there is no way to tell later
        // whether the loop is being fed by the browser, by cron, or not at all.
        $state['patterns'][$foundAt]['closed_by']    = (string)$closedBy;
        $state['patterns'][$foundAt]['closed_at']    = date('c');

        gs_learn_from_outcome($state, $state['patterns'][$foundAt]);
        $state['patterns'] = gs_prune_patterns($state['patterns']);

        if (!gs_write($state)) return ['ok' => false, 'reason' => 'write_failed'];

        $completed = 0;
        foreach ($state['patterns'] as $p) if (!empty($p['outcome'])) $completed++;
        return ['ok' => true, 'patternId' => $target, 'outcome' => (string)$outcome,
                'completedTotal' => $completed, 'patterns' => count($state['patterns'])];
    });
}

/** Counts for dashboards and for verifying the loop is actually closing. */
function gs_outcome_stats() {
    $state = gs_read();
    $p = ($state && isset($state['patterns']) && is_array($state['patterns'])) ? $state['patterns'] : [];
    $completed = 0; $pending = 0; $noTrade = 0; $byCloser = [];
    foreach ($p as $r) {
        if (!empty($r['outcome'])) {
            $completed++;
            $c = (string)($r['closed_by'] ?? 'unknown');
            $byCloser[$c] = ($byCloser[$c] ?? 0) + 1;
        } else {
            $pending++;
            if (($r['direction'] ?? '') === 'NO_TRADE') $noTrade++;
        }
    }
    return [
        'patterns' => count($p),
        'completed' => $completed,
        'pending' => $pending,
        'pendingNoTrade' => $noTrade,
        'byCloser' => $byCloser,
        'calibrationNeeds' => 20,
        'calibrationActive' => $completed >= 20,
        'totalAnalyzed' => (int)(($state['learnings']['totalAnalyzed'] ?? 0)),
    ];
}


// ─── daily risk, derived from ground truth ────────────────────────────────────
/**
 * Today's REALISED P&L and the daily-loss circuit breaker state.
 *
 * WHY THIS IS COMPUTED FROM THE TRADE LOG
 * RiskShield in js/god-mode.js tracked this in memory: `this.dailyPnL` starting at 0, only
 * ever mutated by closeTrade(), and closeTrade() has no callers anywhere in the codebase.
 * So checkDailyLimit() compared 0 against the threshold on every call and returned false
 * forever — gm.dailyLimit was a hard risk stop that could not trip. A guardrail that cannot
 * fire is worse than none, because the UI reports it as active protection.
 *
 * In-memory accounting was the wrong shape for this regardless: it resets on every page
 * reload, it is per-tab, and it cannot see positions the server closed. The trade logs are
 * already the reconciliation ground truth for the safety kernel, so the limit is derived
 * from them instead — the same source, visible to browser and cron alike, surviving reloads.
 *
 * The capital base was also wrong: the default was a hardcoded 20000 while the paper desk
 * actually runs 100000, so even a working comparison would have used a 4% limit five times
 * smaller than intended. It now reads the desk's own capital.
 *
 * Day boundary is IST, not server-UTC — a 4% daily stop must reset when the trading day
 * does.
 */
function gs_daily_risk(float $lossLimitPct = 4.0) {
    $istToday = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $istDate = function ($ts) {
        if (!$ts) return null;
        $d = new DateTime('@' . (int)$ts);
        $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $d->format('Y-m-d');
    };

    $realised = 0.0; $trades = 0; $stops = 0; $wins = 0;

    // Paper desk — append-only JSONL, `ts` in unix seconds.
    $pf = gs_dir() . '/paper/trades.jsonl';
    if (is_file($pf)) {
        foreach (file($pf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $t = json_decode($line, true);
            if (!is_array($t)) continue;
            if ($istDate($t['ts'] ?? 0) !== $istToday) continue;
            $pnl = (float)($t['pnl'] ?? 0);
            $realised += $pnl; $trades++;
            if ($pnl > 0) $wins++;
            if (($t['outcome'] ?? '') === 'STOP_LOSS') $stops++;
        }
    }

    // Virtual desk — trades inside its state file, ISO exitDate.
    $vf = gs_dir() . '/virtual/state.json';
    if (is_file($vf)) {
        $vs = json_decode(@file_get_contents($vf), true);
        foreach (($vs['trades'] ?? []) as $t) {
            $ex = $t['exitDate'] ?? null;
            if (!$ex || $istDate(strtotime((string)$ex)) !== $istToday) continue;
            $pnl = (float)($t['pnl'] ?? 0);
            $realised += $pnl; $trades++;
            if ($pnl > 0) $wins++;
            if (in_array($t['outcome'] ?? '', ['STOP_LOSS', 'TRAIL_STOP'], true)) $stops++;
        }
    }

    // Capital base: the paper desk's own figure, falling back to its initial capital.
    $capital = 100000.0;
    $psf = gs_dir() . '/paper/state.json';
    if (is_file($psf)) {
        $ps = json_decode(@file_get_contents($psf), true);
        if (is_array($ps)) {
            $capital = (float)($ps['initialCapital'] ?? ($ps['capital'] ?? $capital));
            if ($capital <= 0) $capital = 100000.0;
        }
    }

    $limitAmount = -1 * $capital * ($lossLimitPct / 100.0);
    return [
        'istDate' => $istToday,
        'realisedPnl' => round($realised, 2),
        'trades' => $trades,
        'wins' => $wins,
        'stops' => $stops,
        'capital' => round($capital, 2),
        'lossLimitPct' => $lossLimitPct,
        'lossLimitAmount' => round($limitAmount, 2),
        'breached' => $realised <= $limitAmount,
        'remainingBeforeHalt' => round(max(0, $realised - $limitAmount), 2),
        'source' => 'trade-log',
    ];
}
