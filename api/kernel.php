<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 *  GOD MODE SAFETY KERNEL
 * ════════════════════════════════════════════════════════════════════════════
 *
 *  Deterministic, server-side control plane. Nothing in here asks a model
 *  anything — every decision is arithmetic or a state comparison, because a
 *  safety interlock that can hallucinate is not a safety interlock.
 *
 *  Lifecycle enforced:
 *      UNKNOWN -> HALTED -> RECONCILING -> RECOVERING -> VERIFYING -> RUNNING
 *
 *  The system refuses to act while it cannot prove its own state is correct.
 *  "Correct" here has a concrete meaning: the capital recorded in
 *  brain-data/paper/state.json must equal the initial budget plus the sum of
 *  realised P&L in the append-only brain-data/paper/trades.jsonl. That exact
 *  divergence has already bitten this project twice — once when the report was
 *  computed on a stale 20,000 base, and once when an unauthenticated POST
 *  replaced the whole state file.
 *
 *  Included by proxy.php. Requires: PAPER_INITIAL_CAPITAL, PAPER_MAX_PER_TRADE.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Paths
// ─────────────────────────────────────────────────────────────────────────────
function kDir()        { $d = __DIR__ . '/../brain-data'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function kSafetyFile() { return kDir() . '/safety-state.json'; }
function kAuditFile()  { return kDir() . '/audit/' . date('Y-m-d') . '.jsonl'; }
function kGuardFile()  { return kDir() . '/guardrails.json'; }
function kPaperState() { return kDir() . '/paper/state.json'; }
function kPaperTrades(){ return kDir() . '/paper/trades.jsonl'; }

function kAtomicWrite($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. AUDIT LOG  (spec item 30)
// ═════════════════════════════════════════════════════════════════════════════

/** Keys whose values must never reach the log. */
function kRedact(array $d) {
    $deny = ['pin','password','totp','totp_secret','api_key','apikey','admin_token',
             'write_salt','jwt','token','authorization','refreshToken','feedToken'];
    $out = [];
    foreach ($d as $k => $v) {
        $lk = strtolower((string)$k);
        $hit = false;
        foreach ($deny as $bad) if (strpos($lk, $bad) !== false) { $hit = true; break; }
        if ($hit)                 { $out[$k] = '[redacted]'; }
        elseif (is_array($v))     { $out[$k] = kRedact($v); }
        elseif (is_string($v) && strlen($v) > 600) { $out[$k] = substr($v, 0, 600) . '…[truncated]'; }
        else                      { $out[$k] = $v; }
    }
    return $out;
}

/**
 * Append one audit record. Append-only by design: an audit trail you can edit
 * is not evidence.
 *
 * @param string $category user|ai|tool|risk|order|broker|position|error|recovery|security|safety
 */
function auditLog($category, $event, array $data = [], $severity = 'info') {
    $rec = [
        'ts'        => round(microtime(true), 3),
        'iso'       => date('c'),
        'category'  => $category,
        'event'     => $event,
        'severity'  => $severity,
        'safety'    => kSafetyRead()['state'] ?? 'UNKNOWN',
        'ip_hash'   => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'salt'), 0, 12),
        'data'      => kRedact($data),
    ];
    $f = kAuditFile();
    if (!is_dir(dirname($f)) && !@mkdir(dirname($f), 0755, true) && !is_dir(dirname($f))) {
        return false;
    }
    $encoded = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false || @file_put_contents($f, $encoded . "\n", FILE_APPEND | LOCK_EX) === false) {
        return false;
    }
    return $rec;
}

/** Find a previously appended event identity so post-write recovery is idempotent. */
function auditLogHasIdentity($event, $decisionKey) {
    if (!is_string($decisionKey) || $decisionKey === '') return false;
    $files = glob(kDir() . '/audit/*.jsonl') ?: [];
    rsort($files, SORT_STRING);
    foreach ($files as $file) {
        $fh = @fopen($file, 'rb');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            $rec = json_decode($line, true);
            if (is_array($rec) && ($rec['event'] ?? null) === $event
                && ($rec['data']['decisionKey'] ?? null) === $decisionKey) {
                fclose($fh);
                return true;
            }
        }
        fclose($fh);
    }
    return false;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. SAFETY STATE MACHINE  (spec: Unknown -> STOP -> Reconcile -> Recover -> Verify -> Resume)
// ═════════════════════════════════════════════════════════════════════════════

const K_STATES = ['UNKNOWN','HALTED','RECONCILING','RECOVERING','VERIFYING','RUNNING'];

/** Only these moves are legal. You cannot jump from HALTED straight to RUNNING. */
const K_TRANSITIONS = [
    'UNKNOWN'     => ['HALTED'],
    'HALTED'      => ['RECONCILING'],
    'RECONCILING' => ['RECOVERING','VERIFYING','HALTED'],
    'RECOVERING'  => ['VERIFYING','HALTED'],
    'VERIFYING'   => ['RUNNING','HALTED'],
    'RUNNING'     => ['HALTED','UNKNOWN'],
];

/**
 * Shared in-request cache for the safety state.
 *
 * This is a single accessor rather than a `static` inside kSafetyRead() because a
 * per-function static is invisible to the writers: a full recovery cycle performs
 * several transitions inside ONE request, and the read side kept returning the
 * value memoised before the first transition. The cycle genuinely reached RUNNING
 * but reported UNKNOWN. Writes now update the same cache they read from.
 */
function kSafetyMem($set = null) {
    static $mem = null;
    if ($set !== null) $mem = $set;
    return $mem;
}

function kSafetyRead() {
    $m = kSafetyMem();
    if ($m !== null) return $m;
    $f = kSafetyFile();
    if (is_file($f)) {
        $s = json_decode(@file_get_contents($f), true);
        if (is_array($s) && in_array($s['state'] ?? '', K_STATES, true)) return kSafetyMem($s);
    }
    // No file => we genuinely do not know the system's condition.
    return kSafetyMem(['state' => 'UNKNOWN', 'reason' => 'No safety state on disk (fresh deploy or wiped file)',
                       'since' => date('c'), 'history' => []]);
}

function kSafetyWrite(array $s) {
    $s['updatedAt'] = date('c');
    kAtomicWrite(kSafetyFile(), json_encode($s));
    return kSafetyMem($s); // keep the read cache coherent within this request
}

/** Attempt a transition. Illegal moves are refused and audited. */
function kSafetyTransition($to, $reason, array $meta = []) {
    $cur = kSafetyRead();
    $from = $cur['state'];
    if (!in_array($to, K_STATES, true)) {
        return ['ok' => false, 'error' => "Unknown state $to", 'state' => $from];
    }
    if ($from === $to) return ['ok' => true, 'state' => $to, 'noop' => true];
    if (!in_array($to, K_TRANSITIONS[$from] ?? [], true)) {
        auditLog('safety', 'illegal_transition', ['from' => $from, 'to' => $to, 'reason' => $reason], 'warn');
        return ['ok' => false, 'error' => "Illegal transition $from -> $to",
                'allowed' => K_TRANSITIONS[$from] ?? [], 'state' => $from];
    }
    $hist = $cur['history'] ?? [];
    $hist[] = ['from' => $from, 'to' => $to, 'reason' => $reason, 'at' => date('c')];
    $s = kSafetyWrite([
        'state'   => $to,
        'reason'  => $reason,
        'since'   => date('c'),
        'meta'    => $meta,
        'history' => array_slice($hist, -60),
    ]);
    auditLog('safety', 'transition', ['from' => $from, 'to' => $to, 'reason' => $reason]);
    return ['ok' => true, 'state' => $to, 'from' => $from];
}

/** Force to HALTED (or UNKNOWN) from anywhere. Safety may always stop things. */
function kHalt($reason, array $meta = []) {
    $cur = kSafetyRead();
    if ($cur['state'] === 'HALTED') return $cur;
    $hist = $cur['history'] ?? [];
    $hist[] = ['from' => $cur['state'], 'to' => 'HALTED', 'reason' => $reason, 'at' => date('c')];
    $s = kSafetyWrite(['state' => 'HALTED', 'reason' => $reason, 'since' => date('c'),
                       'meta' => $meta, 'history' => array_slice($hist, -60)]);
    auditLog('safety', 'halt', ['reason' => $reason, 'from' => $cur['state']], 'critical');
    return $s;
}

/** Execution is only permitted in RUNNING. */
function kExecutionAllowed() {
    $s = kSafetyRead();
    return $s['state'] === 'RUNNING';
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. RECONCILE / RECOVER / VERIFY
// ═════════════════════════════════════════════════════════════════════════════

/** Ground truth: replay the append-only trade log. */
function kTruthFromTrades() {
    $f = kPaperTrades();
    $pnl = 0.0; $n = 0; $slip = 0.0;
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (!$r) continue;
            $n++; $pnl += (float)($r['pnl'] ?? 0); $slip += (float)($r['slippage'] ?? 0);
        }
    }
    return ['trades' => $n, 'realisedPnl' => round($pnl, 2), 'slippage' => round($slip, 2),
            'expectedCapital' => round(PAPER_INITIAL_CAPITAL + $pnl, 2)];
}

/** Compare recorded state against ground truth. Returns findings, never mutates. */
/**
 * @param bool $audit Log the outcome. False for read-only polling (see
 *                    handleSafetyState) so routine status checks do not flood the
 *                    audit trail and drown the events worth reading.
 */
function kReconcile($audit = true) {
    $truth = kTruthFromTrades();
    $state = is_file(kPaperState()) ? json_decode(@file_get_contents(kPaperState()), true) : null;
    $findings = [];

    if (!is_array($state)) {
        $findings[] = ['code' => 'STATE_MISSING', 'severity' => 'critical',
                       'detail' => 'No paper state file; cannot know open exposure.'];
        $state = [];
    }
    // Open positions have already had their cost DEDUCTED from state capital
    // (paper-trading.js: `s.capital -= cost` on open, `s.capital += cost + pnl` on
    // close). The replay only knows realised P&L, so capital must be compared as
    // cash + open exposure — otherwise every open position produced a false
    // CAPITAL_DRIFT equal to its own cost, which is exactly what the dashboard was
    // reporting ("run the recovery cycle") while nothing was actually wrong.
    $openExposure = 0.0;
    foreach ((is_array($state['active'] ?? null) ? $state['active'] : []) as $p) {
        $openExposure += (float)($p['cost'] ?? 0);
    }
    $openExposure = round($openExposure, 2);

    if (!isset($state['capital'])) {
        $findings[] = ['code' => 'CAPITAL_ABSENT', 'severity' => 'critical',
                       'detail' => 'State has no capital field (state file was replaced or truncated).'];
    } else {
        $drift = round(((float)$state['capital']) + $openExposure - $truth['expectedCapital'], 2);
        if (abs($drift) > 1.0) {
            $findings[] = ['code' => 'CAPITAL_DRIFT', 'severity' => 'critical',
                'detail' => "Recorded capital {$state['capital']} + open exposure {$openExposure} != replay {$truth['expectedCapital']} (drift {$drift})",
                'drift' => $drift];
        }
    }
    if (($state['initialCapital'] ?? PAPER_INITIAL_CAPITAL) != PAPER_INITIAL_CAPITAL) {
        $findings[] = ['code' => 'BUDGET_MISMATCH', 'severity' => 'high',
            'detail' => 'State budget ' . ($state['initialCapital'] ?? 'null') . ' != configured ' . PAPER_INITIAL_CAPITAL];
    }

    // Open positions must be sane and must not exceed the per-trade cap.
    $active = is_array($state['active'] ?? null) ? $state['active'] : [];
    $seen = [];
    foreach ($active as $p) {
        $key = strtoupper(($p['instrument'] ?? '?') . '|' . ($p['direction'] ?? '?') . '|' . ($p['strike'] ?? '?') . '|' . ($p['type'] ?? '?'));
        if (isset($seen[$key])) {
            $findings[] = ['code' => 'DUPLICATE_POSITION', 'severity' => 'critical',
                           'detail' => "Same contract held twice: $key"];
        }
        $seen[$key] = true;
        if ((float)($p['cost'] ?? 0) > PAPER_MAX_PER_TRADE * 1.02) {
            $findings[] = ['code' => 'POSITION_OVER_CAP', 'severity' => 'high',
                'detail' => "Position cost {$p['cost']} exceeds per-trade cap " . PAPER_MAX_PER_TRADE];
        }
    }
    if (count($active) > 3) {
        $findings[] = ['code' => 'TOO_MANY_POSITIONS', 'severity' => 'high',
                       'detail' => count($active) . ' open positions; ceiling is 3'];
    }

    $clean = count($findings) === 0;
    if ($audit) auditLog('recovery', 'reconcile', ['clean' => $clean, 'findings' => $findings, 'truth' => $truth]);
    return ['clean' => $clean, 'findings' => $findings, 'truth' => $truth, 'state' => $state];
}

/**
 * Repair what can be derived deterministically. Capital is rebuilt from the trade
 * log — never invented — and anything not derivable is left for a human.
 */
function kRecover() {
    $rec = kReconcile();
    $state = is_array($rec['state']) ? $rec['state'] : [];
    $truth = $rec['truth'];
    $actions = [];

    $state['initialCapital'] = PAPER_INITIAL_CAPITAL;
    $state['maxPerTrade']    = PAPER_MAX_PER_TRADE;
    // Rebuild CASH, not total equity: the cost of still-open positions has already
    // been deducted from capital and is returned by _closePosition when they close.
    // Assigning the raw replay figure here handed that cash back while the position
    // was still open, so closing it credited `cost + pnl` a second time and inflated
    // the ledger on every recovery cycle run with a position open.
    $openExposureR = 0.0;
    foreach ((is_array($state['active'] ?? null) ? $state['active'] : []) as $p) {
        $openExposureR += (float)($p['cost'] ?? 0);
    }
    $openExposureR = round($openExposureR, 2);
    $state['capital']        = round($truth['expectedCapital'] - $openExposureR, 2);
    $state['totalPnl']       = $truth['realisedPnl'];
    $actions[] = $openExposureR > 0
        ? 'Rebuilt cash and realised P&L by replaying trades.jsonl (held back ' . $openExposureR . ' of open exposure)'
        : 'Rebuilt capital and realised P&L by replaying trades.jsonl';

    if (!isset($state['peakCapital']) || $state['peakCapital'] < PAPER_INITIAL_CAPITAL) {
        $state['peakCapital'] = max(PAPER_INITIAL_CAPITAL, $truth['expectedCapital']);
        $actions[] = 'Reset peak capital floor to the configured budget';
    }
    if (!isset($state['startDate'])) { $state['startDate'] = date('Y-m-d'); $actions[] = 'Seeded start date'; }

    // De-duplicate open positions, keeping the first of each contract.
    if (is_array($state['active'] ?? null)) {
        $seen = []; $kept = []; $dropped = 0;
        foreach ($state['active'] as $p) {
            $key = strtoupper(($p['instrument'] ?? '?') . '|' . ($p['direction'] ?? '?') . '|' . ($p['strike'] ?? '?') . '|' . ($p['type'] ?? '?'));
            if (isset($seen[$key])) { $dropped++; continue; }
            $seen[$key] = true; $kept[] = $p;
        }
        if ($dropped) { $state['active'] = $kept; $actions[] = "Removed $dropped duplicate open position(s)"; }
    } else {
        $state['active'] = [];
    }

    $state['recoveredAt'] = date('c');
    kAtomicWrite(kPaperState(), json_encode($state));
    auditLog('recovery', 'recover', ['actions' => $actions, 'capital' => $state['capital']]);
    return ['ok' => true, 'actions' => $actions, 'state' => $state];
}

/** Prove the repair worked. Verification that cannot fail is worthless. */
function kVerify() {
    $rec = kReconcile();
    $checks = [
        ['name' => 'Capital matches trade-log replay', 'pass' => !kHasFinding($rec, 'CAPITAL_DRIFT') && !kHasFinding($rec, 'CAPITAL_ABSENT')],
        ['name' => 'Budget matches server config',     'pass' => !kHasFinding($rec, 'BUDGET_MISMATCH')],
        ['name' => 'No duplicate open positions',      'pass' => !kHasFinding($rec, 'DUPLICATE_POSITION')],
        ['name' => 'Position count within ceiling',    'pass' => !kHasFinding($rec, 'TOO_MANY_POSITIONS')],
        ['name' => 'No position exceeds per-trade cap', 'pass' => !kHasFinding($rec, 'POSITION_OVER_CAP')],
        ['name' => 'State file present and readable',  'pass' => !kHasFinding($rec, 'STATE_MISSING')],
    ];
    $passed = count(array_filter($checks, fn($c) => $c['pass']));
    $ok = $passed === count($checks);
    auditLog('recovery', 'verify', ['ok' => $ok, 'passed' => $passed, 'of' => count($checks), 'checks' => $checks]);
    return ['ok' => $ok, 'passed' => $passed, 'total' => count($checks), 'checks' => $checks, 'findings' => $rec['findings']];
}

function kHasFinding($rec, $code) {
    foreach ($rec['findings'] as $f) if ($f['code'] === $code) return true;
    return false;
}

/** Run the whole loop. This is what the dashboard button drives. */
function kRunRecoveryCycle() {
    $steps = [];
    $s = kSafetyRead()['state'];
    if ($s === 'UNKNOWN') { kSafetyTransition('HALTED', 'Unknown state: stopping before any action'); $steps[] = 'UNKNOWN -> HALTED'; }
    if (kSafetyRead()['state'] === 'HALTED') { kSafetyTransition('RECONCILING', 'Begin reconciliation'); $steps[] = 'HALTED -> RECONCILING'; }

    $rec = kReconcile();
    $steps[] = 'Reconciled: ' . ($rec['clean'] ? 'clean' : count($rec['findings']) . ' finding(s)');

    if (!$rec['clean']) {
        kSafetyTransition('RECOVERING', 'Findings require repair');
        $rc = kRecover();
        $steps[] = 'Recovered: ' . implode('; ', $rc['actions']);
        kSafetyTransition('VERIFYING', 'Verify repair');
    } else {
        kSafetyTransition('VERIFYING', 'Nothing to repair; verifying');
        $steps[] = 'No repair needed';
    }

    $v = kVerify();
    $steps[] = 'Verified: ' . $v['passed'] . '/' . $v['total'] . ' checks passed';
    if ($v['ok']) { kSafetyTransition('RUNNING', 'All verification checks passed'); $steps[] = 'VERIFYING -> RUNNING'; }
    else          { kHalt('Verification failed: ' . $v['passed'] . '/' . $v['total']); $steps[] = 'Verification FAILED -> HALTED'; }

    return ['steps' => $steps, 'verify' => $v, 'reconcile' => $rec, 'state' => kSafetyRead()['state']];
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. GUARDRAILS  (spec: no duplicate orders, runaway loops, stale prices, …)
// ═════════════════════════════════════════════════════════════════════════════

const K_MAX_TOOL_CALLS_PER_MIN = 30;   // runaway AI loop ceiling
const K_MAX_TOOL_DEPTH         = 5;    // recursion ceiling
const K_MAX_RETRIES            = 3;    // per idempotency key
const K_PRICE_MAX_AGE_SEC      = 20;   // stale-price execution guard
const K_MAX_OPEN_POSITIONS     = 3;
const K_DUP_WINDOW_SEC         = 600;  // identical intent cooldown

function kGuardRead()  { $f = kGuardFile(); $d = is_file($f) ? json_decode(@file_get_contents($f), true) : null; return is_array($d) ? $d : ['exec' => [], 'tools' => [], 'retries' => []]; }
function kGuardWrite($d){ kAtomicWrite(kGuardFile(), json_encode($d)); }

/** Stable fingerprint of an intent — the idempotency key. */
function kIntentKey(array $i) {
    return strtoupper(implode('|', [
        $i['instrument'] ?? '?', $i['direction'] ?? '?',
        $i['strike'] ?? '?', $i['type'] ?? '?', $i['expiry'] ?? '?',
    ]));
}

/**
 * The single gate every execution must pass. Returns allow/deny plus the exact
 * guardrail that fired, so the UI can explain itself and the audit log records why.
 *
 * @param array $intent instrument,direction,strike,type,expiry,priceTs,price,cost
 */
function kGuardCheck(array $intent) {
    $now = time();
    $g = kGuardRead();
    $key = kIntentKey($intent);
    $deny = function ($rail, $detail) use ($key, $intent) {
        auditLog('risk', 'guardrail_block', ['guardrail' => $rail, 'detail' => $detail, 'key' => $key, 'intent' => $intent], 'warn');
        return ['allow' => false, 'guardrail' => $rail, 'detail' => $detail, 'key' => $key];
    };

    // 0. Safety state outranks everything.
    if (!kExecutionAllowed()) {
        $s = kSafetyRead();
        return $deny('SAFETY_STATE', "System is {$s['state']}: {$s['reason']}. Execution is blocked until it reaches RUNNING.");
    }

    // 0b. State integrity. Even in RUNNING, refuse to execute while a CRITICAL
    // reconciliation finding stands (e.g. CAPITAL_DRIFT — recorded capital disagrees
    // with the append-only trade log). Previously the dashboard REPORTED the drift
    // but execution stayed permitted, so a trade could be sized against a capital
    // figure the system itself did not trust. A run-recovery-cycle clears it.
    $integ = kReconcile(false);
    if (!$integ['clean']) {
        $crit = array_filter($integ['findings'], fn($f) => ($f['severity'] ?? '') === 'critical');
        if ($crit) {
            $codes = implode(', ', array_map(fn($f) => $f['code'], $crit));
            return $deny('STATE_INTEGRITY', "Recorded state disagrees with the trade log ($codes). Run the recovery cycle before trading.");
        }
    }

    // 1. Stale-price execution.
    $pts = $intent['priceTs'] ?? null;
    if ($pts === null) return $deny('STALE_PRICE', 'No price timestamp supplied; refusing to execute on an unverifiable price.');
    $age = $now - (int)$pts;
    if ($age > K_PRICE_MAX_AGE_SEC || $age < -5) {
        return $deny('STALE_PRICE', "Price is {$age}s old (limit " . K_PRICE_MAX_AGE_SEC . "s).");
    }

    // 2. Duplicate order / identical intent cooldown.
    $prior = $g['exec'][$key] ?? null;
    if ($prior && ($now - (int)$prior['ts']) < K_DUP_WINDOW_SEC) {
        return $deny('DUPLICATE_ORDER', 'Identical intent executed ' . ($now - (int)$prior['ts']) . 's ago (cooldown ' . K_DUP_WINDOW_SEC . 's).');
    }

    // 3. Accidental position multiplication.
    $state = is_file(kPaperState()) ? json_decode(@file_get_contents(kPaperState()), true) : [];
    $active = is_array($state['active'] ?? null) ? $state['active'] : [];
    if (count($active) >= K_MAX_OPEN_POSITIONS) {
        return $deny('MAX_POSITIONS', count($active) . ' positions already open (ceiling ' . K_MAX_OPEN_POSITIONS . ').');
    }
    foreach ($active as $p) {
        if (kIntentKey($p) === $key) return $deny('POSITION_MULTIPLICATION', 'This exact contract is already open.');
        if (strtoupper($p['instrument'] ?? '') === strtoupper($intent['instrument'] ?? '')
            && strtoupper($p['direction'] ?? '') === strtoupper($intent['direction'] ?? '')) {
            return $deny('POSITION_MULTIPLICATION', 'Same instrument and direction already open; would multiply exposure.');
        }
    }

    // 4. Per-trade capital cap.
    if (isset($intent['cost']) && (float)$intent['cost'] > PAPER_MAX_PER_TRADE) {
        return $deny('PER_TRADE_CAP', 'Cost ' . round((float)$intent['cost']) . ' exceeds cap ' . PAPER_MAX_PER_TRADE . '.');
    }

    // 5. Uncontrolled retries.
    $r = $g['retries'][$key] ?? ['n' => 0, 'ts' => $now];
    if (($now - (int)$r['ts']) > 3600) $r = ['n' => 0, 'ts' => $now];
    if ((int)$r['n'] >= K_MAX_RETRIES) {
        return $deny('RETRY_LIMIT', 'Retry limit ' . K_MAX_RETRIES . ' reached for this intent within the hour.');
    }

    auditLog('risk', 'guardrail_pass', ['key' => $key, 'intent' => $intent]);
    return ['allow' => true, 'key' => $key];
}

/** Record that an execution actually happened, arming the dedup window. */
function kGuardCommit($key, array $meta = []) {
    $g = kGuardRead();
    $g['exec'][$key] = ['ts' => time(), 'meta' => $meta];
    // Prune anything older than a day so the file stays small.
    foreach ($g['exec'] as $k => $v) if ((time() - (int)$v['ts']) > 86400) unset($g['exec'][$k]);
    kGuardWrite($g);
    auditLog('order', 'commit', ['key' => $key, 'meta' => $meta]);
}

function kGuardRetry($key) {
    $g = kGuardRead();
    $r = $g['retries'][$key] ?? ['n' => 0, 'ts' => time()];
    $r['n'] = (int)$r['n'] + 1; $r['ts'] = time();
    $g['retries'][$key] = $r; kGuardWrite($g);
    return $r['n'];
}

/**
 * Runaway-loop / infinite-tool-call ceiling. Every AI tool invocation calls this.
 * Breaching it HALTS the system rather than merely returning an error, because a
 * loop that is told "no" and keeps going is exactly the failure mode.
 */
function kToolBudget($session, $depth = 0) {
    $now = time();
    $g = kGuardRead();
    $t = $g['tools'][$session] ?? ['hits' => []];
    $t['hits'] = array_values(array_filter($t['hits'], fn($x) => ($now - (int)$x) < 60));

    if ((int)$depth > K_MAX_TOOL_DEPTH) {
        kHalt('Tool recursion depth ' . $depth . ' exceeded ceiling ' . K_MAX_TOOL_DEPTH, ['session' => $session]);
        return ['allow' => false, 'guardrail' => 'TOOL_DEPTH', 'detail' => 'Recursion depth exceeded; system halted.'];
    }
    if (count($t['hits']) >= K_MAX_TOOL_CALLS_PER_MIN) {
        kHalt('Tool call rate ' . count($t['hits']) . '/min exceeded ceiling ' . K_MAX_TOOL_CALLS_PER_MIN, ['session' => $session]);
        return ['allow' => false, 'guardrail' => 'TOOL_RATE', 'detail' => 'Runaway loop suspected; system halted.'];
    }
    $t['hits'][] = $now;
    $g['tools'][$session] = $t;
    kGuardWrite($g);
    return ['allow' => true, 'used' => count($t['hits']), 'limit' => K_MAX_TOOL_CALLS_PER_MIN, 'depth' => $depth];
}

/**
 * Silent broker failure detection. An ambiguous response is treated as UNKNOWN,
 * never as success — the dangerous case is an order that may or may not exist.
 */
function kBrokerAck($resp, array $ctx = []) {
    if ($resp === null || $resp === false) {
        kHalt('Broker call returned nothing; order status is indeterminate', $ctx);
        return ['ok' => false, 'indeterminate' => true, 'detail' => 'No response from broker; halted for reconciliation.'];
    }
    if (is_array($resp) && array_key_exists('status', $resp) && $resp['status'] === false) {
        auditLog('broker', 'explicit_failure', ['resp' => $resp, 'ctx' => $ctx], 'warn');
        return ['ok' => false, 'indeterminate' => false, 'detail' => $resp['message'] ?? 'Broker rejected the request.'];
    }
    auditLog('broker', 'ack', ['ctx' => $ctx]);
    return ['ok' => true];
}

// ═════════════════════════════════════════════════════════════════════════════
// 5. UNIFIED SKILL REGISTRY  (spec item 28)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Every capability the orchestrator may use, with the metadata the spec requires.
 * risk_level drives whether confirmation is demanded; permissions drive whether a
 * skill may run at all in the current safety state.
 */
function kSkillRegistry() {
    return [
      [ 'name' => 'market.snapshot', 'description' => 'Index LTP, trend, ATR, ADX regime from live candles.',
        'inputs' => ['symbol'], 'outputs' => ['ltp','trend','adx','atr','regime'],
        'permissions' => ['read:market'], 'risk_level' => 'none', 'data_requirements' => ['candles'],
        'latency' => 'fast', 'cost' => 'free', 'availability' => 'market-hours+', 'kind' => 'deterministic',
        'endpoint' => '?action=candles' ],

      [ 'name' => 'options.chain', 'description' => 'Full option chain with OI, ΔOI, IV, volume and liquidity per strike.',
        'inputs' => ['symbol','expiry'], 'outputs' => ['strikes','oi','iv','volume'],
        'permissions' => ['read:options'], 'risk_level' => 'none', 'data_requirements' => ['option_chain'],
        'latency' => 'medium', 'cost' => 'free', 'availability' => 'market-hours', 'kind' => 'deterministic',
        'endpoint' => '?action=option_chain' ],

      [ 'name' => 'options.gex', 'description' => 'Dealer gamma exposure: regime, zero-gamma flip, call/put walls, max pain, PCR.',
        'inputs' => ['symbol'], 'outputs' => ['regime','flip','callWall','putWall','maxPain','pcr','coverage','reliable'],
        'permissions' => ['read:options'], 'risk_level' => 'none', 'data_requirements' => ['option_chain','greeks'],
        'latency' => 'slow', 'cost' => 'free', 'availability' => 'market-hours', 'kind' => 'deterministic',
        'endpoint' => '?action=gex',
        'notes' => 'Publishes a coverage/reliable flag; consumers must not act when reliable=false.' ],

      [ 'name' => 'signal.generate', 'description' => 'Multi-factor directional signal with evidence chain and vetoes.',
        'inputs' => ['snapshot'], 'outputs' => ['direction','confidence','vetoes','evidence'],
        'permissions' => ['read:market','read:options'], 'risk_level' => 'low', 'data_requirements' => ['candles','option_chain','mtf'],
        'latency' => 'fast', 'cost' => 'free', 'availability' => 'market-hours', 'kind' => 'deterministic',
        'endpoint' => 'js/live-engine.js:generateSignal' ],

      [ 'name' => 'risk.guardrail_check', 'description' => 'Deterministic pre-trade gate: duplicates, staleness, caps, position multiplication.',
        'inputs' => ['intent'], 'outputs' => ['allow','guardrail','detail'],
        'permissions' => ['risk:evaluate'], 'risk_level' => 'none', 'data_requirements' => ['paper_state'],
        'latency' => 'fast', 'cost' => 'free', 'availability' => 'always', 'kind' => 'deterministic',
        'endpoint' => '?action=guard_check' ],

      [ 'name' => 'paper.execute', 'description' => 'Execute a simulated trade against the paper book.',
        'inputs' => ['intent'], 'outputs' => ['executed','reason'],
        'permissions' => ['write:paper'], 'risk_level' => 'medium', 'data_requirements' => ['paper_state','live_premium'],
        'latency' => 'fast', 'cost' => 'free', 'availability' => 'RUNNING-only', 'kind' => 'deterministic',
        'endpoint' => 'js/paper-trading.js:autoExecute',
        'requires_confirmation' => false, 'requires_safety_state' => 'RUNNING' ],

      [ 'name' => 'safety.recover', 'description' => 'Run reconcile -> recover -> verify and resume if all checks pass.',
        'inputs' => [], 'outputs' => ['steps','verify'],
        'permissions' => ['admin:safety'], 'risk_level' => 'high', 'data_requirements' => ['paper_state','trades'],
        'latency' => 'medium', 'cost' => 'free', 'availability' => 'always', 'kind' => 'deterministic',
        'endpoint' => '?action=safety_action&op=cycle', 'requires_confirmation' => true ],

      [ 'name' => 'ai.interpret', 'description' => 'LLM reasoning over an already-computed, deterministic context bundle.',
        'inputs' => ['context','question'], 'outputs' => ['analysis'],
        'permissions' => ['ai:invoke'], 'risk_level' => 'low', 'data_requirements' => ['precomputed_context'],
        'latency' => 'slow', 'cost' => 'metered', 'availability' => 'on-demand', 'kind' => 'llm',
        'endpoint' => '?action=ai_analyze',
        'notes' => 'Never used for arithmetic. Receives numbers already computed by deterministic skills.' ],
    ];
}

/**
 * Deterministic tool selection. Filters by permission, availability and current
 * safety state, then ranks by risk (low first) and latency. No model involved in
 * deciding what is allowed to run.
 */
function kSelectSkills($need, array $granted, $maxRisk = 'medium') {
    $order = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
    $lat   = ['fast' => 0, 'medium' => 1, 'slow' => 2];
    $state = kSafetyRead()['state'];
    $out = [];
    foreach (kSkillRegistry() as $s) {
        if ($need && stripos($s['name'] . ' ' . $s['description'], $need) === false) continue;
        if (($order[$s['risk_level']] ?? 9) > ($order[$maxRisk] ?? 2)) continue;
        $missing = array_diff($s['permissions'], $granted);
        if ($missing) { $s['blocked'] = 'missing permission: ' . implode(',', $missing); }
        if (($s['requires_safety_state'] ?? null) && $state !== $s['requires_safety_state']) {
            $s['blocked'] = "requires safety state {$s['requires_safety_state']}, system is $state";
        }
        $out[] = $s;
    }
    usort($out, fn($a, $b) =>
        (isset($a['blocked']) <=> isset($b['blocked'])) ?:
        (($order[$a['risk_level']] ?? 9) <=> ($order[$b['risk_level']] ?? 9)) ?:
        (($lat[$a['latency']] ?? 9) <=> ($lat[$b['latency']] ?? 9)));
    return $out;
}

// ═════════════════════════════════════════════════════════════════════════════
// 6. HTTP HANDLERS
// ═════════════════════════════════════════════════════════════════════════════

function handleSafetyState() {
    $s = kSafetyRead();
    // Read-only poll: do NOT write an audit entry. The dashboard polls this every
    // 15s, and every poll was appending a 'recovery/reconcile' record — 200+ rows a
    // day of pure noise that buried the entries that actually matter (guardrail
    // blocks, halts, denied writes) and grew the log for nothing.
    $rec = kReconcile(false);
    echo json_encode(['status' => true,
        'safety' => [
            'state' => $s['state'], 'reason' => $s['reason'] ?? '', 'since' => $s['since'] ?? null,
            'executionAllowed' => kExecutionAllowed(),
            'allowedTransitions' => K_TRANSITIONS[$s['state']] ?? [],
            'history' => array_slice($s['history'] ?? [], -12),
        ],
        'integrity' => ['clean' => $rec['clean'], 'findings' => $rec['findings'], 'truth' => $rec['truth']],
        'limits' => [
            'maxToolCallsPerMin' => K_MAX_TOOL_CALLS_PER_MIN, 'maxToolDepth' => K_MAX_TOOL_DEPTH,
            'maxRetries' => K_MAX_RETRIES, 'priceMaxAgeSec' => K_PRICE_MAX_AGE_SEC,
            'maxOpenPositions' => K_MAX_OPEN_POSITIONS, 'dupWindowSec' => K_DUP_WINDOW_SEC,
        ],
    ]);
}

function handleSafetyAction() {
    $op = strtolower($_GET['op'] ?? '');
    // Reading is free; changing the safety state is not.
    if (in_array($op, ['cycle','halt','resume','reconcile','recover','verify'], true)) {
        requireWriteAuth('safety', 20, 60);
    }
    switch ($op) {
        case 'reconcile': echo json_encode(['status' => true] + kReconcile()); return;
        case 'verify':    echo json_encode(['status' => true] + kVerify()); return;
        case 'recover':   echo json_encode(['status' => true] + kRecover()); return;
        case 'cycle':     echo json_encode(['status' => true] + kRunRecoveryCycle()); return;
        case 'halt':
            $r = kHalt($_GET['reason'] ?? 'Manual halt from dashboard');
            echo json_encode(['status' => true, 'safety' => $r]); return;
        case 'resume':
            // Deliberately NOT a shortcut to RUNNING: resuming without proof is
            // how a bad state gets blessed. Force the full cycle.
            echo json_encode(['status' => true] + kRunRecoveryCycle()); return;
        default:
            http_response_code(400);
            echo json_encode(['status' => false, 'error' => 'op must be one of: cycle, halt, resume, reconcile, recover, verify']);
    }
}

function handleAuditLog() {
    $day = preg_replace('/[^0-9\-]/', '', $_GET['day'] ?? date('Y-m-d'));
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 120)));
    $cat = strtolower($_GET['category'] ?? '');
    $f = kDir() . '/audit/' . $day . '.jsonl';
    $rows = [];
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (!$r) continue;
            if ($cat && strtolower($r['category'] ?? '') !== $cat) continue;
            $rows[] = $r;
        }
    }
    $counts = [];
    foreach ($rows as $r) { $c = $r['category'] ?? '?'; $counts[$c] = ($counts[$c] ?? 0) + 1; }
    echo json_encode(['status' => true, 'day' => $day, 'total' => count($rows),
        'counts' => $counts, 'entries' => array_slice($rows, -$limit)]);
}

function handleSkills() {
    $granted = array_filter(explode(',', $_GET['permissions'] ?? 'read:market,read:options,risk:evaluate,ai:invoke,write:paper'));
    echo json_encode(['status' => true,
        'count' => count(kSkillRegistry()),
        'safetyState' => kSafetyRead()['state'],
        'skills' => kSelectSkills($_GET['need'] ?? '', $granted, $_GET['maxRisk'] ?? 'high')]);
}

function handleGuardCheck() {
    requireWriteAuth('guard', 240, 60);
    $raw = file_get_contents('php://input');
    $intent = json_decode($raw, true);
    if (!is_array($intent)) { http_response_code(400); echo json_encode(['status' => false, 'error' => 'Send a JSON intent']); return; }
    $r = kGuardCheck($intent);
    if (!empty($_GET['commit']) && $r['allow']) kGuardCommit($r['key'], ['via' => 'guard_check']);
    echo json_encode(['status' => true] + $r);
}

function handleToolBudget() {
    requireWriteAuth('tool', 240, 60);
    $s = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['session'] ?? 'default');
    echo json_encode(['status' => true] + kToolBudget($s, (int)($_GET['depth'] ?? 0)));
}

function handleWriteToken() {
    // Only issued to a browser on this site; this is what binds writes to origin.
    if (!requestIsSameSite()) { http_response_code(403); echo json_encode(['status' => false, 'error' => 'Same-origin required']); return; }
    echo json_encode(['status' => true, 'token' => issueWriteToken(), 'ttlSec' => 1800]);
}
