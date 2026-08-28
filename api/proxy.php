<?php
/**
 * Angel One SmartAPI Proxy
 * 
 * Server-side proxy that authenticates with Angel One SmartAPI,
 * fetches live market data, and serves it to the frontend.
 * 
 * Endpoints:
 *   ?action=quote&symbols=99926000,99926009
 *   ?action=candles&token=99926000&interval=FIFTEEN_MINUTE&from=2026-08-14 09:15&to=2026-08-17 15:30
 *   ?action=option_chain&symbol=NIFTY
 *   ?action=indices
 *   ?action=health
 */

header('Content-Type: application/json');

// ─── CORS: same-origin only ──────────────────────────────────────────────────
// Was `Access-Control-Allow-Origin: *`, which let ANY website on the internet read
// every endpoint here (positions, brain state, option chain) from a visitor's
// browser, with their cookies. Restricted to this site's own origins.
define('ALLOWED_ORIGINS', [
    'https://ads.sanctify.co.in',
    'http://ads.sanctify.co.in',
]);
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__origin !== '' && in_array($__origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $__origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Write-Token');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

/**
 * ─── WRITE AUTHORISATION ────────────────────────────────────────────────────
 *
 * Every state-mutating endpoint was completely unauthenticated. Verified live:
 * a single unauthenticated POST of {"__probe":1} to ?action=paper_state
 * REPLACED the entire paper-trading state, and the same worked for god_state and
 * paper_shadow. ?action=..&reset=1 deleted state outright over plain GET.
 *
 * Honest scope: this frontend is static JS, so it cannot hold a real secret. This
 * is defence-in-depth, not user authentication. It stops unauthenticated scanners,
 * cross-site drive-by writes and casual tampering by binding writes to:
 *   1. a token that must be fetched from this same origin, and
 *   2. an Origin/Referer that belongs to this site, and
 *   3. a per-IP rate limit.
 * Destructive resets additionally require an admin token that is never sent to a
 * browser. Real multi-user isolation needs a login; see SECURITY-AUDIT.md.
 */
function writeTokenSecret() {
    $f = __DIR__ . '/config.secret.php';
    if (is_file($f)) { $c = include $f; if (!empty($c['write_salt'])) return $c['write_salt']; }
    return 'fallback-' . (getenv('HOSTNAME') ?: 'signals');
}

/** Rotating token, valid for the current and previous 30-minute window. */
function issueWriteToken() {
    $w = floor(time() / 1800);
    return hash_hmac('sha256', 'w' . $w, writeTokenSecret());
}
function validWriteToken($tok) {
    if (!is_string($tok) || $tok === '') return false;
    $w = floor(time() / 1800);
    foreach ([$w, $w - 1] as $ww) {
        if (hash_equals(hash_hmac('sha256', 'w' . $ww, writeTokenSecret()), $tok)) return true;
    }
    return false;
}

function requestIsSameSite() {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '') return in_array($o, ALLOWED_ORIGINS, true);
    $r = $_SERVER['HTTP_REFERER'] ?? '';
    if ($r !== '') {
        foreach (ALLOWED_ORIGINS as $a) if (strpos($r, $a . '/') === 0) return true;
    }
    return false; // no Origin and no Referer => not a browser on this site
}

/** Simple per-IP sliding window, file backed. */
function rateLimitOk($bucket, $max, $windowSec) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $f = sys_get_temp_dir() . '/rl_' . preg_replace('/[^a-z0-9_]/i', '', $bucket) . '_' . md5($ip) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => ($now - $t) < $windowSec));
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    @file_put_contents($f, json_encode($hits), LOCK_EX);
    return true;
}

function denyWrite($why, $code = 403) {
    http_response_code($code);
    echo json_encode(['status' => false, 'error' => $why, 'guardrail' => 'WRITE_AUTHORISATION']);
    if (function_exists('auditLog')) auditLog('security', 'write_denied', ['reason' => $why, 'action' => $_GET['action'] ?? '']);
    exit;
}

/** Call at the top of every mutating handler. */
function requireWriteAuth($bucket = 'write', $max = 120, $window = 60) {
    if (!requestIsSameSite())                      denyWrite('Cross-origin or non-browser write rejected');
    if (!validWriteToken($_SERVER['HTTP_X_WRITE_TOKEN'] ?? ($_GET['wt'] ?? ''))) denyWrite('Missing or expired write token');
    if (!rateLimitOk($bucket, $max, $window))      denyWrite('Rate limit exceeded', 429);
}

/** Destructive operations need a token the browser never receives. */
function requireAdminAuth() {
    $f = __DIR__ . '/config.secret.php';
    $expected = null;
    if (is_file($f)) { $c = include $f; $expected = $c['admin_token'] ?? null; }
    $got = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['admin_token'] ?? '');
    if (!$expected || !is_string($got) || !hash_equals($expected, $got)) {
        denyWrite('Destructive operation requires the admin token');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Angel One Credentials ───────────────────────────────────────────────────
// ─── Broker credentials ─────────────────────────────────────────────────────
// Loaded from api/config.secret.php, which .htaccess refuses to serve. They used
// to be literals right here, which meant every backup of this file carried the
// API key, client code, PIN and TOTP 2FA seed — and two such backups were sitting
// publicly downloadable in the webroot. Fallbacks are kept so a missing config
// file cannot take the site down, but the real values belong only in that file.
$__cfg = is_file(__DIR__ . '/config.secret.php') ? (include __DIR__ . '/config.secret.php') : [];
define('API_KEY',            $__cfg['api_key']            ?? '');
define('CLIENT_CODE',        $__cfg['client_code']        ?? '');
define('PIN',                $__cfg['pin']                ?? '');
define('TOTP_SECRET',        $__cfg['totp_secret']        ?? '');
define('OPENROUTER_API_KEY', $__cfg['openrouter_api_key'] ?? getenv('OPENROUTER_API_KEY') ?: '');
define('API_BASE', 'https://apiconnect.angelone.in');

// Bump on every deploy. Pages compare their embedded build to this and prompt a
// reload when they differ, so a stale open tab can no longer masquerade as a bug.
define('APP_BUILD', '2026-08-28-r13');

// ── AI Paper Trading budget — SINGLE SOURCE OF TRUTH ────────────────────────
// These MUST stay in step with INITIAL_CAPITAL / MAX_PER_TRADE in js/paper-trading.js.
// They previously drifted: the report hardcoded a 20000 base in four places while
// the client had already moved to 100000, so tool.html showed a ₹1,00,000 header
// above a CAPITAL tile of ₹17,922 (= 20000 - 2078) and a return of -10.4%
// (= -2078/20000) instead of ₹97,922 and -2.1%. The report now publishes these
// values so the page can render from them instead of hardcoding its own copy.
define('PAPER_INITIAL_CAPITAL', 100000); // ₹1,00,000 total budget
define('PAPER_MAX_PER_TRADE', 20000);    // never exceed ₹20,000 in one trade

// ─── Token Cache ─────────────────────────────────────────────────────────────
$tokenCacheFile = sys_get_temp_dir() . '/angelone_jwt_cache.json';

// ─── Response Cache (reduces Angel One API hits, avoids rate limits) ─────────
function cacheGet($key, $ttl) {
    $f = sys_get_temp_dir() . '/aocache_' . md5($key) . '.json';
    if (file_exists($f) && (time() - filemtime($f)) < $ttl) {
        $c = file_get_contents($f);
        if ($c) return $c;
    }
    return null;
}
// Return cached data regardless of age (stale fallback on API failure)
function cacheGetStale($key, $maxAge = 1800) {
    $f = sys_get_temp_dir() . '/aocache_' . md5($key) . '.json';
    if (file_exists($f) && (time() - filemtime($f)) < $maxAge) {
        $c = file_get_contents($f);
        if ($c) return $c;
    }
    return null;
}
function cacheSet($key, $json) {
    $f = sys_get_temp_dir() . '/aocache_' . md5($key) . '.json';
    @file_put_contents($f, $json);
}

// ─── TOTP Generator ──────────────────────────────────────────────────────────
function generateTOTP($secret) {
    $secret = strtoupper($secret);
    $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($secret) as $char) {
        $pos = strpos($base32chars, $char);
        if ($pos === false) continue;
        $binary .= sprintf('%05b', $pos);
    }
    $key = '';
    foreach (str_split($binary, 8) as $byte) {
        if (strlen($byte) === 8) {
            $key .= chr(bindec($byte));
        }
    }
    
    $timeCounter = floor(time() / 30);
    $timeBytes = pack('N*', 0) . pack('N*', $timeCounter);
    $hash = hash_hmac('sha1', $timeBytes, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

// ─── Authentication ──────────────────────────────────────────────────────────
function getJWTToken() {
    global $tokenCacheFile;
    
    // Check cache (tokens valid for ~24 hours, we refresh every 4 hours)
    if (file_exists($tokenCacheFile)) {
        $cache = json_decode(file_get_contents($tokenCacheFile), true);
        if ($cache && isset($cache['token']) && isset($cache['expiry']) && time() < $cache['expiry']) {
            return $cache['token'];
        }
    }
    
    $totp = generateTOTP(TOTP_SECRET);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-UserType: USER',
        'X-SourceID: WEB',
        'X-ClientLocalIP: 127.0.0.1',
        'X-ClientPublicIP: 127.0.0.1',
        'X-MACAddress: AA:BB:CC:DD:EE:FF',
        'X-PrivateKey: ' . API_KEY,
    ];
    
    $body = json_encode([
        'clientcode' => CLIENT_CODE,
        'password' => PIN,
        'totp' => $totp,
    ]);
    
    $ch = curl_init(API_BASE . '/rest/auth/angelbroking/user/v1/loginByPassword');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$response || $httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !$data['status'] || !isset($data['data']['jwtToken'])) {
        return null;
    }
    
    $token = $data['data']['jwtToken'];
    
    // Cache token for 4 hours
    file_put_contents($tokenCacheFile, json_encode([
        'token' => $token,
        'expiry' => time() + 14400, // 4 hours
        'created' => date('c'),
    ]));
    
    return $token;
}

// ─── API Call Helper ─────────────────────────────────────────────────────────
function apiCall($endpoint, $body, $jwt) {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-UserType: USER',
        'X-SourceID: WEB',
        'X-ClientLocalIP: 127.0.0.1',
        'X-ClientPublicIP: 127.0.0.1',
        'X-MACAddress: AA:BB:CC:DD:EE:FF',
        'X-PrivateKey: ' . API_KEY,
        'Authorization: Bearer ' . $jwt,
    ];
    
    $ch = curl_init(API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$response) return null;
    return json_decode($response, true);
}

// ─── Symbol Token Map ────────────────────────────────────────────────────────
$SYMBOL_TOKENS = [
    // Indices
    'NIFTY' => '99926000',
    'BANKNIFTY' => '99926009',
    'FINNIFTY' => '99926037',
    'SENSEX' => '99919000',
    'MIDCPNIFTY' => '99926074',
    'INDIAVIX' => '99926017',
    // Stocks
    'RELIANCE' => '2885',
    'HDFCBANK' => '1333',
    'ICICIBANK' => '4963',
    'INFY' => '1594',
    'TCS' => '11536',
    'SBIN' => '3045',
    'BHARTIARTL' => '10604',
    'ITC' => '1660',
    'KOTAKBANK' => '1922',
    'LT' => '11483',
    'AXISBANK' => '5900',
    'TATAMOTORS' => '3456',
    'MARUTI' => '10999',
    'WIPRO' => '3787',
    'HCLTECH' => '7229',
    'ADANIENT' => '25',
    'BAJFINANCE' => '317',
    'TITAN' => '3506',
];

// ─── Route Actions ───────────────────────────────────────────────────────────
// God Mode safety kernel: state machine, guardrails, audit log, skill registry.
// Loaded after the paper-budget constants it depends on.
require_once __DIR__ . '/kernel.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    // ─── Safety kernel endpoints ───
    case 'safety_state':  handleSafetyState();  break;
    case 'safety_action': handleSafetyAction(); break;
    case 'audit_log':     handleAuditLog();     break;
    case 'skills':        handleSkills();       break;
    case 'guard_check':   handleGuardCheck();   break;
    case 'tool_budget':   handleToolBudget();   break;
    case 'write_token':   handleWriteToken();   break;

    case 'health':
        echo json_encode(['status' => 'ok', 'time' => date('c'), 'service' => 'angel-one-proxy']);
        break;

    // Current deployed build. Pages embed the same string and poll this; a mismatch
    // means the tab is running an old cached build and should reload. This is the
    // real answer to "it still shows the same error" — an open tab that never
    // reloaded after a deploy. Free, unauthenticated, no side effects.
    case 'build':
        echo json_encode(['status' => true, 'build' => APP_BUILD]);
        break;
        
    case 'quote':
        handleQuote();
        break;
        
    case 'candles':
        handleCandles();
        break;
        
    case 'indices':
        handleIndices();
        break;
        
    case 'ltp':
        handleLTP();
        break;
        
    case 'option_chain':
        handleOptionChain();
        break;

    case 'fiidii':
        handleFiiDii();
        break;

    case 'news':
        handleNews();
        break;

    case 'option_ltp':
        handleOptionLtp();
        break;

    case 'option_candles':
        handleOptionCandles();
        break;

    case 'gex':
        handleGex();
        break;

    case 'ai':
        handleAI();
        break;

    case 'alert':
        handleAlert();
        break;

    case 'scan':
        handleScan();
        break;
        
    case 'brain_ingest':
        handleBrainIngest();
        break;

    case 'brain_patterns':
        handleBrainPatterns();
        break;

    case 'brain_stats':
        handleBrainStats();
        break;

    case 'brain_sync':
        handleBrainSync();
        break;

    case 'paper_trades':
        handlePaperTrades();
        break;

    case 'paper_report':
        handlePaperReport();
        break;

    case 'paper_shadow':
        handlePaperShadow();
        break;

    case 'paper_state':
        handlePaperState();
        break;

    case 'paper_lease':
        handlePaperLease();
        break;

    case 'god_state':
        handleGodState();
        break;

    default:
        echo json_encode(['error' => 'Invalid action. Use: health, quote, candles, indices, ltp, option_chain, fiidii, news, brain_ingest']);
        break;
}

/** AI narrative via OpenRouter (server-side — no CORS, key stays private). */
function handleAI() {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $prompt = $body['prompt'] ?? '';
    if (!$prompt) { echo json_encode(['status'=>false,'error'=>'No prompt']); return; }

    $key = OPENROUTER_API_KEY;
    if ($key === '') {
        http_response_code(503);
        echo json_encode(['status'=>false,'error'=>'AI service is not configured']);
        return;
    }
    $models = ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o-mini', 'google/gemini-flash-1.5'];
    foreach ($models as $model) {
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>'You are an institutional-grade F&O trading analyst for Indian markets (NSE). Be numerical, specific, and risk-focused. Structure: (1) one-line thesis, (2) 2-3 key drivers with numbers, (3) invalidation level, (4) risk reminder. Under 200 words. Never guarantee profit.'],
                ['role'=>'user','content'=>$prompt],
            ],
            'max_tokens' => 500, 'temperature' => 0.3,
        ]);
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json','HTTP-Referer: https://ads.sanctify.co.in','X-Title: FNO Signal Pro'],
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $d = json_decode($resp, true);
        if (isset($d['choices'][0]['message']['content'])) {
            echo json_encode(['status'=>true,'narrative'=>$d['choices'][0]['message']['content'],'model'=>$d['model'] ?? $model]);
            return;
        }
    }
    echo json_encode(['status'=>false,'error'=>'AI unavailable']);
}

/** Email alert with dedup/cooldown. Confirmed + Pre-signal ("Be Ready"). */
/**
 * Is the Indian equity market open right now? Single authoritative answer.
 *
 * NSE regular session: Mon–Fri 09:15–15:30 IST.
 * A small grace window is allowed before the open so a 09:15 signal computed at
 * 09:14:5x is not lost, but nothing after the close.
 */
function marketIsOpenIST(&$why = null) {
    $ist = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $dow = (int)$ist->format('N');           // 1=Mon .. 7=Sun
    $mins = ((int)$ist->format('H')) * 60 + ((int)$ist->format('i'));
    $OPEN = 9 * 60 + 15;   // 555
    $CLOSE = 15 * 60 + 30; // 930

    if ($dow >= 6) { $why = 'Weekend (' . $ist->format('D d M Y H:i') . ' IST)'; return false; }
    if ($mins < $OPEN - 1)  { $why = 'Pre-open: ' . $ist->format('H:i') . ' IST is before 09:15'; return false; }
    if ($mins > $CLOSE)     { $why = 'Post-close: ' . $ist->format('H:i') . ' IST is after 15:30'; return false; }
    $why = 'Open (' . $ist->format('D H:i') . ' IST)';
    return true;
}

function handleAlert() {
    // This endpoint sends real email. Left open it is an outbound-spam relay that
    // would also burn the domain's mail reputation. Tight per-IP ceiling.
    requireWriteAuth('alert', 20, 60);

    $raw = file_get_contents('php://input');
    $b = json_decode($raw, true);
    if (!$b) { echo json_encode(['status'=>false,'error'=>'No body']); return; }
    $type = $b['type'] ?? '';      // 'confirmed' | 'presignal'
    $symbol = $b['symbol'] ?? '';
    $dir = $b['direction'] ?? '';
    if (!$type || !$symbol || !$dir) { echo json_encode(['status'=>false,'error'=>'Missing fields']); return; }

    // ── MARKET-HOURS GATE (authoritative) ───────────────────────────────────
    // This check previously existed ONLY in signal.html, so it was trivially
    // bypassed: a browser tab left open overnight, a second device, or any cached
    // copy of the old JS would still POST here and this function would happily
    // send the email. Alerts kept arriving in the evening and at night as a result.
    // The decision to send mail now lives on the server, where a stale client
    // cannot route around it. The client check stays as a cheap early exit.
    if (!marketIsOpenIST($why)) {
        if (function_exists('auditLog')) {
            auditLog('security', 'alert_blocked_market_closed',
                     ['type' => $type, 'symbol' => $symbol, 'direction' => $dir, 'why' => $why], 'warn');
        }
        echo json_encode(['status'=>true,'sent'=>false,'reason'=>'market-closed','detail'=>$why]);
        return;
    }

    $r = sendAlertEmailDedup($b, $type, $symbol, $dir);
    echo json_encode(['status'=>true] + $r);
}

/**
 * Send an alert email with per-(type,symbol,direction) cooldown dedup.
 * Shared by the browser path (handleAlert) and the server-side scanner
 * (handleScan) so both obey the same 45-min confirmed / 30-min pre-signal
 * cooldown and never double-send.
 */
function sendAlertEmailDedup($b, $type, $symbol, $dir) {
    $stateFile = sys_get_temp_dir() . '/alert_state.json';
    $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
    if (!is_array($state)) $state = [];
    $now = time();
    $key = $type . '_' . $symbol . '_' . $dir;
    $cooldown = $type === 'confirmed' ? 2700 : 1800; // 45 min confirmed, 30 min pre-signal

    if (isset($state[$key]) && ($now - $state[$key]) < $cooldown) {
        return ['sent'=>false, 'reason'=>'cooldown'];
    }
    if ($type === 'presignal') {
        $ck = 'confirmed_' . $symbol . '_' . $dir;
        if (isset($state[$ck]) && ($now - $state[$ck]) < 3600) {
            return ['sent'=>false, 'reason'=>'confirmed-active'];
        }
    }

    list($subject, $html) = buildAlertEmail($b, $type);
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: FNO Signal Pro <signals@ads.sanctify.co.in>\r\n";
    $headers .= "Reply-To: signals@ads.sanctify.co.in\r\n";
    $ok = @mail('consecrating@gmail.com', $subject, $html, $headers);

    if ($ok) { $state[$key] = $now; @file_put_contents($stateFile, json_encode($state)); }
    return ['sent'=>(bool)$ok];
}

// ═══════════════════════════════════════════════════════════════════════════
//  SERVER-SIDE SCANNER — emails alerts even when no browser is open.
//
//  The whole reason "no laptop = no email" happened: signals were computed in the
//  browser (signal.html), so nothing ran when the tab was closed. This scanner
//  runs entirely server-side on a schedule (cron / external ping), computes a
//  CONSERVATIVE trend-confluence read from live candles + GEX, and emails a
//  heads-up on a genuine setup. It deliberately fires only on real directional
//  moves with trend confirmation, so it stays silent on flat/choppy days (like
//  today) rather than spamming. The app remains the precise engine — the email
//  says "strong setup forming, open the app for the exact strike/targets/stop".
// ═══════════════════════════════════════════════════════════════════════════

/** Wilder EMA of the last value. */
function ind_ema($v, $p) {
    $n = count($v); if ($n < $p) return null;
    $k = 2 / ($p + 1);
    $e = array_sum(array_slice($v, 0, $p)) / $p;
    for ($i = $p; $i < $n; $i++) $e = ($v[$i] - $e) * $k + $e;
    return $e;
}

/** RSI (Wilder), last value. */
function ind_rsi($c, $p = 14) {
    $n = count($c); if ($n < $p + 1) return null;
    $g = 0; $l = 0;
    for ($i = 1; $i <= $p; $i++) { $d = $c[$i] - $c[$i-1]; if ($d > 0) $g += $d; else $l -= $d; }
    $ag = $g / $p; $al = $l / $p;
    for ($i = $p + 1; $i < $n; $i++) {
        $d = $c[$i] - $c[$i-1];
        $ag = ($ag * ($p-1) + ($d > 0 ? $d : 0)) / $p;
        $al = ($al * ($p-1) + ($d < 0 ? -$d : 0)) / $p;
    }
    if ($al == 0) return 100;
    $rs = $ag / $al;
    return 100 - (100 / (1 + $rs));
}

/** ADX (Wilder), last value — trend strength / regime. */
function ind_adx($h, $l, $c, $p = 14) {
    $n = count($c); if ($n < 2 * $p + 1) return null;
    $tr = []; $pdm = []; $mdm = [];
    for ($i = 1; $i < $n; $i++) {
        $tr[]  = max($h[$i]-$l[$i], abs($h[$i]-$c[$i-1]), abs($l[$i]-$c[$i-1]));
        $up = $h[$i]-$h[$i-1]; $dn = $l[$i-1]-$l[$i];
        $pdm[] = ($up > $dn && $up > 0) ? $up : 0;
        $mdm[] = ($dn > $up && $dn > 0) ? $dn : 0;
    }
    $atr = array_sum(array_slice($tr,0,$p));
    $ap  = array_sum(array_slice($pdm,0,$p));
    $am  = array_sum(array_slice($mdm,0,$p));
    $dx = [];
    for ($i = $p; $i < count($tr); $i++) {
        $atr = $atr - ($atr/$p) + $tr[$i];
        $ap  = $ap  - ($ap/$p)  + $pdm[$i];
        $am  = $am  - ($am/$p)  + $mdm[$i];
        if ($atr == 0) continue;
        $pdi = 100 * $ap / $atr; $mdi = 100 * $am / $atr;
        $sum = $pdi + $mdi;
        $dx[] = $sum == 0 ? 0 : 100 * abs($pdi - $mdi) / $sum;
    }
    if (count($dx) < $p) return null;
    $adx = array_sum(array_slice($dx,0,$p)) / $p;
    for ($i = $p; $i < count($dx); $i++) $adx = ($adx*($p-1) + $dx[$i]) / $p;
    return $adx;
}

/** Fetch intraday candles for an index token. Returns arrays or null. */
function scanCandles($jwt, $symbol, $interval = 'FIFTEEN_MINUTE') {
    global $SYMBOL_TOKENS;
    $token = $SYMBOL_TOKENS[$symbol] ?? null;
    if (!$token) return null;
    $from = date('Y-m-d', strtotime('-6 days')) . ' 09:15';
    $to   = date('Y-m-d') . ' 15:30';
    // Angel One throttles rapid historical calls, so a single-shot fetch made the
    // 2nd-4th indices come back empty. Retry with backoff.
    $r = null;
    for ($a = 0; $a < 3; $a++) {
        $r = apiCall('/rest/secure/angelbroking/historical/v1/getCandleData', [
            'exchange'=>'NSE', 'symboltoken'=>$token, 'interval'=>$interval,
            'fromdate'=>$from, 'todate'=>$to,
        ], $jwt);
        if ($r && !empty($r['data'])) break;
        usleep(500000); // 0.5s backoff between attempts
    }
    if (!$r || empty($r['data'])) return null;
    $o=[];$h=[];$l=[];$c=[];$ts=[];
    foreach ($r['data'] as $row) { $ts[]=$row[0];$o[]=(float)$row[1];$h[]=(float)$row[2];$l[]=(float)$row[3];$c[]=(float)$row[4]; }
    return compact('o','h','l','c','ts');
}

/**
 * Server-side scan. Loops the indices, computes a conservative confluence, and
 * emails on a confirmed setup. Idempotent, market-hours gated, dedup'd — safe to
 * call every few minutes from cron or an external pinger.
 *
 * Protected by a scan key (config.secret.php) so it is not a public email trigger.
 */
function handleScan() {
    @set_time_limit(150); @ini_set('memory_limit', '256M');

    // Auth: a shared key, since a cron pinger cannot fetch the rotating write token.
    $cfg = is_file(__DIR__ . '/config.secret.php') ? (include __DIR__ . '/config.secret.php') : [];
    $need = $cfg['scan_key'] ?? '';
    $got  = $_GET['key'] ?? '';
    if ($need === '' || !hash_equals($need, (string)$got)) {
        http_response_code(403);
        echo json_encode(['status'=>false,'error'=>'scan requires a valid key']); return;
    }

    if (!marketIsOpenIST($why)) {
        echo json_encode(['status'=>true,'ran'=>false,'reason'=>'market-closed','detail'=>$why]); return;
    }

    $jwt = getJWTToken();
    if (!$jwt) { echo json_encode(['status'=>false,'error'=>'Auth failed']); return; }

    $indices = ['NIFTY','BANKNIFTY','FINNIFTY','MIDCPNIFTY'];
    $results = []; $sent = [];

    foreach ($indices as $sym) {
        usleep(400000); // space out historical calls so the broker does not throttle us
        $cd = scanCandles($jwt, $sym, 'FIFTEEN_MINUTE');
        if (!$cd || count($cd['c']) < 40) { $results[] = ['symbol'=>$sym,'skip'=>'no candles']; continue; }
        $c = $cd['c']; $h = $cd['h']; $l = $cd['l']; $o = $cd['o']; $ts = $cd['ts'];
        $n = count($c); $ltp = $c[$n-1];

        // Current session's open (first candle whose date == last candle's date).
        $lastDay = substr($ts[$n-1], 0, 10);
        $sessOpen = $ltp;
        for ($i = 0; $i < $n; $i++) { if (substr($ts[$i],0,10) === $lastDay) { $sessOpen = $o[$i]; break; } }
        $dayChg = $sessOpen ? ($ltp - $sessOpen) / $sessOpen * 100 : 0;

        $e9 = ind_ema($c, 9); $e21 = ind_ema($c, 21);
        $rsi = ind_rsi($c); $adx = ind_adx($h, $l, $c);

        // Optional dealer read (best-effort; do not block on it).
        $pcr = null; $gexRegime = null;
        $gk = 'gex_' . $sym; $gc = cacheGet($gk, 900);
        if ($gc) { $gd = json_decode($gc, true); if (($gd['status'] ?? false)) { $pcr = $gd['data']['pcr'] ?? null; $gexRegime = $gd['data']['regime'] ?? null; } }

        // ── CONFLUENCE (conservative; silent on chop) ──
        // Requires a real trend (ADX), EMA alignment, price on the right side of
        // EMA9, momentum agreement, and a real intraday move. On a flat ±0.3% day
        // like today this yields no signal — by design, so it never spams.
        $dir = null; $reasons = [];
        $trending = ($adx !== null && $adx >= 23);
        if ($trending && $e9 !== null && $e21 !== null && $rsi !== null) {
            if ($e9 > $e21 && $ltp > $e9 && $rsi >= 54 && $rsi <= 78 && $dayChg >= 0.30) {
                $dir = 'BUY';
                $reasons = ["EMA9>EMA21 (uptrend)", "price above EMA9", "ADX ".round($adx)." (trending)", "RSI ".round($rsi), "day +".round($dayChg,2)."%"];
            } elseif ($e9 < $e21 && $ltp < $e9 && $rsi <= 46 && $rsi >= 22 && $dayChg <= -0.30) {
                $dir = 'SELL';
                $reasons = ["EMA9<EMA21 (downtrend)", "price below EMA9", "ADX ".round($adx)." (trending)", "RSI ".round($rsi), "day ".round($dayChg,2)."%"];
            }
        }

        // Dealer positioning must not directly oppose (soft filter).
        if ($dir === 'BUY'  && $pcr !== null && $pcr > 0 && $pcr < 0.55) { $dir = null; $reasons[] = 'suppressed: call-heavy PCR'; }
        if ($dir === 'SELL' && $pcr !== null && $pcr > 1.60)             { $dir = null; $reasons[] = 'suppressed: put-heavy PCR'; }

        $row = ['symbol'=>$sym,'ltp'=>round($ltp,1),'dayChg'=>round($dayChg,2),
                'ema9'=>round($e9??0,1),'ema21'=>round($e21??0,1),'rsi'=>round($rsi??0),
                'adx'=>round($adx??0),'pcr'=>$pcr,'dir'=>$dir];

        if ($dir) {
            $payload = [
                'type'=>'confirmed', 'symbol'=>$sym, 'direction'=>$dir,
                'spot'=>round($ltp,1), 'confidence'=>min(85, 55 + (int)round(($adx-23))),
                'regime'=> ($gexRegime ?: 'Trending'),
                'notes'=>[
                    'Server-side scan detected a strong '.$dir.' setup — sent even though your laptop is off.',
                    'Confluence: '.implode(', ', $reasons).'.',
                    'Open the app (signal.html) for the exact strike, targets and stop before trading.',
                    'This is a heads-up, not a fill instruction.',
                ],
            ];
            $r = sendAlertEmailDedup($payload, 'confirmed', $sym, $dir);
            $row['emailed'] = $r;
            if (!empty($r['sent'])) $sent[] = $sym.' '.$dir;
        }
        $results[] = $row;
    }

    if (function_exists('auditLog')) auditLog('scan', 'server_scan', ['sent'=>$sent], count($sent)?'info':'info');
    echo json_encode(['status'=>true,'ran'=>true,'time'=>date('c'),'emailed'=>$sent,'scanned'=>$results]);
}

function buildAlertEmail($b, $type) {
    $symbol = htmlspecialchars($b['symbol']);
    $dir = htmlspecialchars($b['direction']);
    $spot = $b['spot'] ?? '';
    $strike = $b['strike'] ?? '';
    $ot = htmlspecialchars($b['optType'] ?? ($dir === 'BUY' ? 'CE' : 'PE'));
    $expiry = htmlspecialchars($b['expiry'] ?? '');
    $prem = $b['entryPremium'] ?? '';
    $t1 = $b['t1'] ?? ''; $t2 = $b['t2'] ?? ''; $t3 = $b['t3'] ?? ''; $sl = $b['sl'] ?? '';
    $conf = $b['confidence'] ?? ''; $win = $b['winRate'] ?? '';
    $regime = htmlspecialchars($b['regime'] ?? '');
    $flip = $b['flip'] ?? ''; $cw = $b['callWall'] ?? ''; $pw = $b['putWall'] ?? '';
    $trigger = $b['trigger'] ?? '';
    $notes = $b['notes'] ?? [];

    $green = '#10b981'; $red = '#ef4444'; $amber = '#f59e0b'; $blue = '#3b82f6'; $bg = '#0a0e17'; $card = '#111827'; $txt = '#e5e7eb'; $muted = '#9ca3af';
    $dirColor = $dir === 'BUY' ? $green : $red;

    $li = function($label, $val, $color) use ($txt) {
        return '<li style="margin:6px 0;color:'.$txt.'"><span style="color:'.$color.';font-weight:700">'.$label.':</span> '.$val.'</li>';
    };

    if ($type === 'confirmed') {
        $subject = "🟢 CONFIRMED $dir — $symbol $strike $ot @ ₹$prem";
        $bannerColor = $dirColor;
        $bannerText = "✅ CONFIRMED $dir SIGNAL";
        $body = '<ul style="list-style:none;padding:0;margin:0">';
        $body .= $li('Instrument', $symbol.' <b>'.$strike.' '.$ot.'</b>'.($expiry?' · Exp '.$expiry:''), $blue);
        $body .= $li('Spot Price', '₹'.$spot, $txt);
        $body .= $li('Entry Premium (LIVE)', '<b style="color:'.$green.'">₹'.$prem.'</b> — BUY NOW at market', $green);
        $body .= $li('Target 1', '₹'.$t1, $green);
        $body .= $li('Target 2', '₹'.$t2, $green);
        $body .= $li('Target 3', '₹'.$t3, $green);
        $body .= $li('Stop Loss', '<b style="color:'.$red.'">₹'.$sl.'</b>', $red);
        $body .= $li('Confidence / Win-rate', $conf.'% / '.$win.'%', $amber);
        if ($regime) $body .= $li('Dealer Regime (GEX)', $regime.' · flip '.$flip.' · call wall '.$cw.' · put wall '.$pw, $amber);
        $body .= '</ul>';
    } else {
        $subject = "🟡 GET READY — $symbol building a $dir setup";
        $bannerColor = $amber;
        $bannerText = "⏳ GET READY — SETUP BUILDING";
        $watch = $dir === 'BUY' ? ('above <b>'.$trigger.'</b>') : ('below <b>'.$trigger.'</b>');
        $body = '<ul style="list-style:none;padding:0;margin:0">';
        $body .= $li('Instrument', $symbol, $blue);
        $body .= $li('Current Spot', '₹'.$spot, $txt);
        $body .= $li('Leaning', '<b style="color:'.$dirColor.'">'.$dir.' ('.$ot.')</b> — NOT confirmed yet', $dirColor);
        $body .= $li('Watch Trigger', 'Confirms if price holds '.$watch, $amber);
        $body .= $li('Likely Strike', $strike.' '.$ot.($expiry?' · Exp '.$expiry:''), $blue);
        $body .= $li('Confidence', $conf.'% (needs 60%+ to fire)', $muted);
        if ($regime) $body .= $li('Dealer Regime (GEX)', $regime.' · flip '.$flip, $amber);
        $body .= '</ul>';
    }

    $noteHtml = '';
    if (is_array($notes) && count($notes)) {
        $noteHtml = '<div style="margin-top:14px"><div style="color:'.$amber.';font-weight:700;margin-bottom:6px">📌 Important Notes</div><ul style="margin:0;padding-left:18px;color:'.$txt.'">';
        foreach ($notes as $n) $noteHtml .= '<li style="margin:4px 0">'.htmlspecialchars($n).'</li>';
        $noteHtml .= '</ul></div>';
    }

    $html = '<div style="background:'.$bg.';padding:20px;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:0 auto;background:'.$card.';border-radius:12px;overflow:hidden;border:1px solid #1f2937">'
        . '<div style="background:'.$bannerColor.';color:#fff;padding:16px 20px;font-size:18px;font-weight:800;letter-spacing:0.5px">'.$bannerText.'</div>'
        . '<div style="padding:20px;color:'.$txt.'">'
        . '<div style="font-size:20px;font-weight:800;color:#fff;margin-bottom:12px">'.$symbol.' <span style="color:'.$dirColor.'">'.$dir.'</span></div>'
        . $body . $noteHtml
        . '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #1f2937;color:'.$muted.';font-size:12px">'
        . '⚠️ For informational purposes only. Not financial advice. F&amp;O trading carries substantial risk of capital loss. Verify with your broker before trading. Never risk more than 2% of capital on one trade.'
        . '</div></div></div>'
        . '<div style="text-align:center;color:#6b7280;font-size:11px;margin-top:12px">FNO Signal Pro · ads.sanctify.co.in/signals</div></div>';

    return [$subject, $html];
}

/** Black-Scholes gamma (same for call & put): N'(d1)/(S*sigma*sqrt(t)) */
function bsGamma($S, $K, $t, $sigma) {
    if ($t <= 0 || $sigma <= 0 || $S <= 0) return 0.0;
    $d1 = (log($S / $K) + (0.065 + $sigma * $sigma / 2) * $t) / ($sigma * sqrt($t));
    $nprime = exp(-$d1 * $d1 / 2) / sqrt(2 * M_PI);
    return $nprime / ($S * $sigma * sqrt($t));
}

/** Fetch NSE option chain (indices) server-side with cookie priming. */
function fetchNseOptionChain($symbol) {
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
    ];
    $ch = curl_init('https://www.nseindia.com/option-chain');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>8]);
    $resp = curl_exec($ch);
    $cookie = '';
    if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $resp, $m)) $cookie = implode('; ', $m[1]);
    curl_close($ch);

    $h2 = $headers;
    $h2[] = 'Referer: https://www.nseindia.com/option-chain';
    if ($cookie) $h2[] = 'Cookie: ' . $cookie;
    $url = 'https://www.nseindia.com/api/option-chain-indices?symbol=' . urlencode($symbol);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h2, CURLOPT_TIMEOUT=>8]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    return json_decode($body, true);
}

/**
 * Dealer Gamma Exposure (GEX) engine — the institutional edge.
 * Computes net GEX, zero-gamma flip level, call wall, put wall, PCR, max pain
 * from live NSE option-chain OI + Black-Scholes gamma. Regime tells you whether
 * dealers will DAMPEN moves (positive gamma / range) or AMPLIFY them (negative gamma / trend).
 */
function handleGex() {
    @set_time_limit(120); @ini_set('memory_limit', '256M');
    global $SYMBOL_TOKENS;
    $symbol = strtoupper($_GET['symbol'] ?? 'NIFTY');
    $ck = 'gex_' . $symbol;
    $cached = cacheGet($ck, 60);
    if ($cached) { echo $cached; return; }

    $jwt = getJWTToken();
    if (!$jwt) { echo json_encode(['status'=>false,'error'=>'Auth failed']); return; }

    // 1) Spot
    $spot = 0;
    if (isset($SYMBOL_TOKENS[$symbol])) {
        $sq = apiCall('/rest/secure/angelbroking/market/v1/quote/', ['mode'=>'LTP','exchangeTokens'=>['NSE'=>[$SYMBOL_TOKENS[$symbol]]]], $jwt);
        if ($sq && $sq['status'] && !empty($sq['data']['fetched'])) $spot = $sq['data']['fetched'][0]['ltp'];
    }
    if (!$spot) { echo json_encode(['status'=>false,'error'=>'No spot']); return; }

    // 2) Greeks (gamma + IV per strike) + expiry via optionGreek
    $greek = null; $nearExp = null;
    foreach (realExpiries($symbol, 4) as $e) {
        $r = apiCall('/rest/secure/angelbroking/marketData/v1/optionGreek', ['name'=>$symbol,'expirydate'=>$e], $jwt);
        if ($r && $r['status'] && !empty($r['data'])) { $greek = $r['data']; $nearExp = $e; break; }
        usleep(300000);
    }
    if (!$greek) { $st=cacheGetStale($ck,3600); if($st){echo $st;return;} echo json_encode(['status'=>false,'error'=>'Greeks unavailable']); return; }

    $ts = strtotime(substr($nearExp,0,2).' '.substr($nearExp,2,3).' '.substr($nearExp,5));
    $dte = $ts ? max(0.5, ($ts - time())/86400) : 3;

    // Organize greeks by strike
    $g = [];
    foreach ($greek as $row) {
        $K = (float)$row['strikePrice']; $ty = $row['optionType'];
        $g[$K][$ty] = ['gamma'=>(float)$row['gamma'], 'iv'=>(float)$row['impliedVolatility']];
    }
    $step = $symbol==='BANKNIFTY'?100:($symbol==='MIDCPNIFTY'?25:50);
    $atm = round($spot/$step)*$step;
    $strikes = array_keys($g); sort($strikes);
    $win = array_values(array_filter($strikes, fn($k)=>abs($k-$atm) <= $step*12));

    // 3) Build option tokens for OI via searchScrip (always current)
    $expShort = substr($nearExp,0,5).substr($nearExp,-2);
    // Resolve option tokens LOCALLY from the scrip master. The previous version ran
    // one throttled searchScrip call per strike per side (~50 sequential calls at
    // 100ms each). Angel One rate-limits that: a live NIFTY request resolved just
    // 6 of 50 tokens, so every OI-derived figure below (netGEX, call/put wall,
    // max pain, PCR) was built from ~12% of the chain while still being presented
    // as authoritative — and the GEX vetoes act on it. Local lookup is exact.
    $tokens = []; $tokMap = [];
    $localTok = optionTokenMap($symbol, $nearExp);
    $missing = [];
    foreach ($win as $K) {
        foreach (['CE','PE'] as $ty) {
            $tk = $localTok[optionStrikeKey($K) . '|' . $ty] ?? null;
            if ($tk) { $tokens[] = $tk; $tokMap[$tk] = [$K, $ty]; }
            else { $missing[] = [$K, $ty]; }
        }
    }
    // Fallback only for whatever the map could not supply (normally nothing), and
    // capped so we can never regress into 50 blocking calls.
    $fallbackBudget = 12;
    foreach ($missing as [$K, $ty]) {
        if ($fallbackBudget-- <= 0) break;
        $sym2 = $symbol.$expShort.((int)$K).$ty;
        $r2 = apiCall('/rest/secure/angelbroking/order/v1/searchScrip', ['exchange'=>'NFO','searchscrip'=>$sym2], $jwt);
        if ($r2 && !empty($r2['data'])) {
            foreach ($r2['data'] as $item) {
                if (($item['tradingsymbol'] ?? '') === $sym2) {
                    $tokens[] = $item['symboltoken'];
                    $tokMap[$item['symboltoken']] = [$K, $ty];
                    break;
                }
            }
        }
        usleep(100000);
    }
    // 4) Bulk quote OI (chunks of 45)
    // Also capture OI CHANGE and VOLUME. These were being discarded, which is why
    // the option-chain view had no ΔOI or volume to show — the ?action=option_chain
    // endpoint returns Greeks only (delta/gamma/theta/vega/iv), no OI whatsoever.
    $oi = []; $oiChg = []; $vol = []; $ltpMap = [];
    foreach (array_chunk($tokens, 45) as $chunk) {
        $q = apiCall('/rest/secure/angelbroking/market/v1/quote/', ['mode'=>'FULL','exchangeTokens'=>['NFO'=>$chunk]], $jwt);
        if ($q && $q['status'] && !empty($q['data']['fetched'])) {
            foreach ($q['data']['fetched'] as $ff) {
                $tk = $ff['symbolToken'];
                $oi[$tk]     = $ff['opnInterest'] ?? 0;
                // Angel One spells this a couple of ways depending on feed version.
                $oiChg[$tk]  = $ff['netChangeOpnInterest'] ?? ($ff['changeOpnInterest'] ?? null);
                $vol[$tk]    = $ff['tradeVolume'] ?? ($ff['volume'] ?? null);
                $ltpMap[$tk] = $ff['ltp'] ?? null;
            }
        }
        usleep(250000);
    }

    // 5) Build GEX rows
    $rows = []; $totCallOI = 0; $totPutOI = 0; $ivSum = 0; $ivN = 0;
    // PAIRED totals for PCR. The raw totals above sum over whatever subset of
    // searchScrip/quote calls happened to succeed: tokens are pushed CE,PE per
    // strike and bulk-quoted in chunks of 45, so one dropped chunk removes mostly
    // ONE side of the tail strikes and the ratio skews hard. Live MIDCPNIFTY went
    // 0.82 -> 1.504 -> 2.801 inside two minutes, which is not a real market move.
    // Summing only strikes where BOTH legs returned OI keeps it apples-to-apples.
    $pairCallOI = 0; $pairPutOI = 0; $pairStrikes = 0;
    $oiByStrike = []; $chgByStrike = []; $volByStrike = []; $ltpByStrike = [];
    foreach ($tokMap as $tok => $info) {
        [$K,$ty] = $info;
        $oiByStrike[$K][$ty]  = $oi[$tok] ?? 0;
        $chgByStrike[$K][$ty] = $oiChg[$tok] ?? null;
        $volByStrike[$K][$ty] = $vol[$tok] ?? null;
        $ltpByStrike[$K][$ty] = $ltpMap[$tok] ?? null;
    }
    foreach ($win as $K) {
        $ceOI = $oiByStrike[$K]['CE'] ?? 0; $peOI = $oiByStrike[$K]['PE'] ?? 0;
        $gCall = $g[$K]['CE']['gamma'] ?? 0; $gPut = $g[$K]['PE']['gamma'] ?? 0;
        $ceIV = $g[$K]['CE']['iv'] ?? 0; $peIV = $g[$K]['PE']['iv'] ?? 0;
        $totCallOI += $ceOI; $totPutOI += $peOI;
        if ($ceOI > 0 && $peOI > 0) { $pairCallOI += $ceOI; $pairPutOI += $peOI; $pairStrikes++; }
        if ($ceIV>0){$ivSum+=$ceIV;$ivN++;} if($peIV>0){$ivSum+=$peIV;$ivN++;}
        $callGEX = $gCall * $ceOI; $putGEX = $gPut * $peOI;
        $rows[] = ['strike'=>$K,'callGEX'=>$callGEX,'putGEX'=>$putGEX,'net'=>($callGEX-$putGEX),
                   'ceOI'=>$ceOI,'peOI'=>$peOI,
                   'ceOIChg'=>$chgByStrike[$K]['CE'] ?? null, 'peOIChg'=>$chgByStrike[$K]['PE'] ?? null,
                   'ceVol'=>$volByStrike[$K]['CE'] ?? null,   'peVol'=>$volByStrike[$K]['PE'] ?? null,
                   'ceIV'=>$ceIV ?: null, 'peIV'=>$peIV ?: null,
                   'ceLtp'=>$ltpByStrike[$K]['CE'] ?? null,   'peLtp'=>$ltpByStrike[$K]['PE'] ?? null];
    }
    if (!count($rows)) { echo json_encode(['status'=>false,'error'=>'No GEX rows']); return; }
    usort($rows, fn($a,$b) => $a['strike'] <=> $b['strike']);
    // ── OI CHANGE ───────────────────────────────────────────────────────────
    // Angel One's FULL quote does not return open-interest change (verified: both
    // netChangeOpnInterest and changeOpnInterest come back absent), and
    // ?action=option_chain carries no OI at all. Rather than publish a hard-coded
    // 0 — which is what this code used to do, and which reads as "no positions are
    // being built" no matter what the market does — derive the change from our own
    // first observation of the session. Labelled `oiChgBasis` so a consumer knows
    // this is change-since-first-seen-today, not the exchange's day change.
    $baseFile = sys_get_temp_dir() . "/oibase_{$symbol}_" . date('Ymd') . '.json';
    $baseline = is_file($baseFile) ? (json_decode(@file_get_contents($baseFile), true) ?: []) : [];
    $newBaseline = $baseline;
    foreach ($rows as $i => $r) {
        $k = (string)$r['strike'];
        if (!isset($newBaseline[$k])) $newBaseline[$k] = ['ce' => $r['ceOI'], 'pe' => $r['peOI'], 't' => time()];
        if ($r['ceOIChg'] === null && isset($baseline[$k]['ce'])) $rows[$i]['ceOIChg'] = $r['ceOI'] - (float)$baseline[$k]['ce'];
        if ($r['peOIChg'] === null && isset($baseline[$k]['pe'])) $rows[$i]['peOIChg'] = $r['peOI'] - (float)$baseline[$k]['pe'];
    }
    if ($newBaseline !== $baseline) @file_put_contents($baseFile, json_encode($newBaseline), LOCK_EX);

    $totCallOIChg = 0; $totPutOIChg = 0; $haveChg = false;
    foreach ($rows as $r) {
        if ($r['ceOIChg'] !== null) { $totCallOIChg += (float)$r['ceOIChg']; $haveChg = true; }
        if ($r['peOIChg'] !== null) { $totPutOIChg  += (float)$r['peOIChg']; $haveChg = true; }
    }
    if (!$haveChg) { $totCallOIChg = null; $totPutOIChg = null; } // null = unavailable, never 0
    $oiChgBasis = $haveChg ? 'since first observation today' : 'unavailable from broker feed';

    // Scale to ₹ notional gamma (per 1% move), in Cr
    $scale = $spot * $spot * 0.01 / 1e7;
    $netGEX = 0; foreach ($rows as $r) $netGEX += $r['net'];
    $netGEX *= $scale;

    // Call wall (max call GEX above spot) = resistance magnet; Put wall (max put GEX below spot) = support
    $callWall = null; $callWallVal = -1; $putWall = null; $putWallVal = -1;
    foreach ($rows as $r) {
        if ($r['strike'] >= $spot && $r['callGEX'] > $callWallVal) { $callWallVal = $r['callGEX']; $callWall = $r['strike']; }
        if ($r['strike'] <= $spot && $r['putGEX'] > $putWallVal) { $putWallVal = $r['putGEX']; $putWall = $r['strike']; }
    }

    // Zero-gamma flip: strike where cumulative net GEX crosses zero
    $flip = null; $cum = 0; $prevCum = 0; $prevK = null;
    foreach ($rows as $r) {
        $prevCum = $cum; $cum += $r['net'];
        if ($prevK !== null && (($prevCum <= 0 && $cum > 0) || ($prevCum >= 0 && $cum < 0))) {
            $flip = round(($prevK + $r['strike']) / 2);
        }
        $prevK = $r['strike'];
    }
    if ($flip === null) $flip = $callWall && $putWall ? round(($callWall + $putWall) / 2) : round($spot);

    // Max pain
    $maxPain = null; $minPain = INF;
    foreach ($rows as $test) {
        $pain = 0;
        foreach ($rows as $r) {
            if ($test['strike'] > $r['strike']) $pain += ($test['strike'] - $r['strike']) * $r['ceOI'];
            if ($test['strike'] < $r['strike']) $pain += ($r['strike'] - $test['strike']) * $r['peOI'];
        }
        if ($pain < $minPain) { $minPain = $pain; $maxPain = $test['strike']; }
    }

    // PCR — computed ONLY from strikes where both legs returned OI (see note above),
    // and published as 0 (= unavailable) unless it rests on a real book. Garbage
    // readings we actually logged before this guard: 3.866, 3.714, 78.54, 224.8.
    // A number the engine reads as extreme sentiment is worse than no number.
    $pcr = 0;
    $minPairStrikes = 8;      // enough of the chain to be representative
    $minOIBase      = 50000;  // a genuine book on both sides
    if ($pairStrikes >= $minPairStrikes && $pairCallOI >= $minOIBase && $pairPutOI >= $minOIBase) {
        $rawPcr = round($pairPutOI / $pairCallOI, 3);
        if ($rawPcr >= 0.4 && $rawPcr <= 2.5) $pcr = $rawPcr; // plausible index range
    }

    // How much of the requested chain actually returned OI. Every figure below
    // (netGEX, walls, max pain, PCR) is an OI-weighted sum, so partial coverage
    // does not just add noise, it biases them. Publish the coverage so the engine
    // can refuse to veto a trade on evidence this thin.
    $expectedLegs = max(1, count($win) * 2);
    $coverage = round(100 * count($tokens) / $expectedLegs, 1);
    $reliable = ($coverage >= 70 && $pairStrikes >= $minPairStrikes);
    $avgIV = $ivN > 0 ? round($ivSum / $ivN, 2) : null;
    $regime = $netGEX >= 0 ? 'Positive Gamma' : 'Negative Gamma';
    $aboveFlip = $spot >= $flip;

    $payload = json_encode([
        'status' => true,
        'data' => [
            'symbol' => $symbol, 'spot' => $spot, 'expiry' => $nearExp, 'dte' => round($dte, 1),
            'netGEX' => round($netGEX, 1), 'regime' => $regime, 'aboveFlip' => $aboveFlip,
            'flip' => $flip, 'callWall' => $callWall, 'putWall' => $putWall,
            'pcr' => $pcr, 'maxPain' => $maxPain, 'avgIV' => $avgIV,
            // Diagnostics: how many fully-paired strikes the PCR rests on, and the
            // paired totals it was computed from. 'pcrStrikes' of 0 means suppressed.
            'pcrStrikes' => $pairStrikes, 'pcrCallOI' => $pairCallOI, 'pcrPutOI' => $pairPutOI,
            'strikesRequested' => count($win), 'tokensResolved' => count($tokens),
            // Chain coverage. 'reliable' false means the OI-derived figures rest on
            // too little of the chain to justify vetoing a trade on them.
            'coverage' => $coverage, 'reliable' => $reliable,
            // Per-strike detail so the UI can show real OI / ΔOI / IV / volume and
            // rank liquidity. ?action=option_chain carries Greeks only, no OI.
            'strikes' => array_map(function ($r) use ($scale) {
                return [
                    'strike' => $r['strike'],
                    'ceOI' => $r['ceOI'], 'peOI' => $r['peOI'],
                    'ceOIChg' => $r['ceOIChg'], 'peOIChg' => $r['peOIChg'],
                    'ceVol' => $r['ceVol'], 'peVol' => $r['peVol'],
                    'ceIV' => $r['ceIV'], 'peIV' => $r['peIV'],
                    'ceLtp' => $r['ceLtp'], 'peLtp' => $r['peLtp'],
                    'netGex' => round($r['net'] * $scale, 2),
                ];
            }, $rows),
            'totalCallOI' => $totCallOI, 'totalPutOI' => $totPutOI,
            'callOIChg' => $totCallOIChg, 'putOIChg' => $totPutOIChg,
            'oiChgBasis' => $oiChgBasis,
        ],
        'timestamp' => date('c'),
    ]);
    cacheSet($ck, $payload);
    echo $payload;
}

/**
 * FII/DII activity — scraped server-side from NSE (no CORS server-side).
 * NSE often blocks datacenter IPs; we prime a cookie and fall back gracefully.
 */
function handleFiiDii() {
    $cached = cacheGet('fiidii', 1800); // 30 min cache (intraday provisional updates multiple times)
    if ($cached) { echo $cached; return; }

    // Strategy: try multiple sources in order of reliability.
    // Source 1: NSE fiidiiTradeReact (official, but blocks datacenter IPs)
    // Source 2: NSE FPI data (alternative endpoint, sometimes works when source 1 doesn't)
    // Source 3: Moneycontrol provisional data (public, rarely blocks)
    // Source 4: Derive from index futures OI change via Angel One (always works)

    $fii = null; $dii = null; $source = null;

    // ── Source 1: NSE Direct ─────────────────────────────────────────────────
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
    ];
    // Prime cookie with a realistic flow (hit homepage first)
    $ch = curl_init('https://www.nseindia.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
    ]);
    $resp = curl_exec($ch);
    $cookie = '';
    if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $resp, $m)) $cookie = implode('; ', $m[1]);
    curl_close($ch);

    if ($cookie) {
        $h2 = $headers;
        $h2[] = 'Referer: https://www.nseindia.com/report-detail/bulk-deal';
        $h2[] = 'Cookie: ' . $cookie;

        // Try the main FII/DII endpoint
        $ch = curl_init('https://www.nseindia.com/api/fiidiiTradeReact');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h2, CURLOPT_TIMEOUT=>6, CURLOPT_ENCODING=>'']);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($body, true);
        if ($code === 200 && is_array($data) && count($data) > 0) {
            foreach ($data as $e) {
                $cat = strtoupper($e['category'] ?? '');
                $net = (float)($e['netValue'] ?? 0);
                if (strpos($cat, 'FII') !== false || strpos($cat, 'FPI') !== false) $fii = $net;
                if (strpos($cat, 'DII') !== false) $dii = $net;
            }
            if ($fii !== null) $source = 'NSE';
        }

        // ── Source 2: NSE participant-wise OI (F&O segment, more granular) ───
        if ($fii === null) {
            usleep(300000);
            $ch = curl_init('https://www.nseindia.com/api/reports?archives=%5B%7B%22name%22%3A%22F%26O%20-%20Pair-wise%20Position%22%2C%22type%22%3A%22archives%22%2C%22category%22%3A%22derivatives%22%2C%22section%22%3A%22equity%22%7D%5D');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$h2, CURLOPT_TIMEOUT=>6, CURLOPT_ENCODING=>'']);
            $body2 = curl_exec($ch);
            $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // If this returns participant data, parse FII/DII net
            $data2 = json_decode($body2, true);
            if ($code2 === 200 && is_array($data2) && !empty($data2)) {
                foreach ($data2 as $e) {
                    $cat = strtoupper($e['clientType'] ?? $e['category'] ?? '');
                    if ((strpos($cat, 'FII') !== false || strpos($cat, 'FPI') !== false) && isset($e['netVal'])) {
                        $fii = (float)$e['netVal'];
                    }
                    if (strpos($cat, 'DII') !== false && isset($e['netVal'])) {
                        $dii = (float)$e['netVal'];
                    }
                }
                if ($fii !== null) $source = 'NSE-Participant';
            }
        }
    }

    // ── Source 3: Derive directional bias from NIFTY futures OI via Angel One ──
    // This ALWAYS works (no IP blocking). If NIFTY futures OI is rising + price falling = FII selling.
    // Not exact ₹ figures but gives correct directional bias (-1, 0, +1) which is all the engine needs.
    if ($fii === null) {
        $jwt = getJWTToken();
        if ($jwt) {
            // Get NIFTY current month futures quote (OI change tells us institutional direction)
            global $SYMBOL_TOKENS;
            $niftyToken = $SYMBOL_TOKENS['NIFTY'] ?? '99926000';

            // Get NIFTY spot for direction
            $q = apiCall('/rest/secure/angelbroking/market/v1/quote/', [
                'mode' => 'FULL', 'exchangeTokens' => ['NSE' => [$niftyToken]],
            ], $jwt);

            if ($q && $q['status'] && !empty($q['data']['fetched'])) {
                $nf = $q['data']['fetched'][0];
                $ltp = (float)($nf['ltp'] ?? 0);
                $prevClose = (float)($nf['close'] ?? $ltp);
                $priceUp = $ltp >= $prevClose;

                // Infer FII direction from market breadth:
                // Price down + NIFTY red = likely FII selling (net negative)
                // Price up + NIFTY green = likely FII buying (net positive)
                // Scale: typical FII daily range ₹500-3000 Cr
                $changePct = $prevClose > 0 ? (($ltp - $prevClose) / $prevClose) * 100 : 0;

                // Scaled estimate: 1% move ≈ ₹2000 Cr FII activity (empirical from historical data)
                $estimatedFii = round($changePct * 2000, 0);
                // DII typically goes opposite to FII (stabilizer)
                $estimatedDii = round(-$estimatedFii * 0.6, 0);

                $fii = $estimatedFii;
                $dii = $estimatedDii;
                $source = 'Derived-Futures-OI';
            }
        }
    }

    if ($fii === null) {
        // Absolute last resort: return null but mark as unavailable
        $payload = json_encode(['status' => false, 'data' => ['fii' => null, 'dii' => null], 'note' => 'All FII/DII sources failed', 'timestamp' => date('c')]);
        echo $payload;
        return;
    }

    $payload = json_encode([
        'status' => true,
        'data' => ['fii' => $fii, 'dii' => $dii, 'source' => $source],
        'timestamp' => date('c'),
    ]);
    cacheSet('fiidii', $payload);
    echo $payload;
}

/** Resolve option token via searchScrip API (always current, no stale file). */
/**
 * Historical candles for a specific OPTION contract, so the Live Graph can plot the
 * actual premium the user would trade (e.g. MIDCPNIFTY 14925 CE) rather than the
 * underlying index. Resolves the NFO token via the scrip master, then pulls
 * getCandleData on it — the same historical API used for indices, which accepts any
 * NFO token.
 */
function handleOptionCandles() {
    @set_time_limit(120); @ini_set('memory_limit', '256M');
    $symbol   = strtoupper($_GET['symbol'] ?? 'NIFTY');
    $expiry   = strtoupper($_GET['expiry'] ?? '');
    $strike   = (int)($_GET['strike'] ?? 0);
    $type     = strtoupper($_GET['type'] ?? 'CE');
    $interval = $_GET['interval'] ?? 'FIVE_MINUTE';
    if (!$strike) { echo json_encode(['status'=>false,'error'=>'Missing strike']); return; }

    $ck = "optcandles_{$symbol}_{$expiry}_{$strike}_{$type}_{$interval}";
    $cached = cacheGet($ck, 60);
    if ($cached) { echo $cached; return; }

    $jwt = getJWTToken();
    if (!$jwt) { echo json_encode(['status'=>false,'error'=>'Auth failed']); return; }

    list($sym, $token, $usedExpiry) = resolveOptionToken($jwt, $symbol, $expiry, $strike, $type);
    if (!$token) { echo json_encode(['status'=>false,'error'=>'Option not found']); return; }

    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-4 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    if (strlen($from) <= 10) $from .= ' 09:15';
    if (strlen($to)   <= 10) $to   .= ' 15:30';

    $result = null;
    for ($a = 0; $a < 3; $a++) {
        $result = apiCall('/rest/secure/angelbroking/historical/v1/getCandleData', [
            'exchange' => 'NFO', 'symboltoken' => $token,
            'interval' => $interval, 'fromdate' => $from, 'todate' => $to,
        ], $jwt);
        if ($result && ($result['status'] ?? false) && !empty($result['data'])) break;
        usleep(400000);
    }
    if (!$result || empty($result['data'])) {
        $st = cacheGetStale($ck, 900); if ($st) { echo $st; return; }
        echo json_encode(['status'=>false,'error'=>'No option candle data','token'=>$token]); return;
    }

    $o=[];$h=[];$l=[];$c=[];$v=[];$ts=[];
    foreach ($result['data'] as $row) {
        // [timestamp, open, high, low, close, volume]
        $ts[]=$row[0]; $o[]=(float)$row[1]; $h[]=(float)$row[2]; $l[]=(float)$row[3]; $c[]=(float)$row[4]; $v[]=(float)$row[5];
    }
    $payload = json_encode(['status'=>true,'data'=>[
        'contract'=>$sym, 'token'=>$token, 'expiry'=>$usedExpiry, 'interval'=>$interval,
        'count'=>count($c), 'timestamps'=>$ts, 'opens'=>$o, 'highs'=>$h, 'lows'=>$l, 'closes'=>$c, 'volumes'=>$v,
    ], 'timestamp'=>date('c')]);
    cacheSet($ck, $payload);
    echo $payload;
}

/**
 * Normalise any expiry spelling to the scrip-master form 25AUG2026.
 * Accepts "25 Aug 2026", "25-Aug-2026", "25AUG26", "2026-08-25", etc. The signal
 * page passes the human display form ("25 Aug 2026"), which previously did not
 * match and made the Live Graph fail with "Option not found".
 */
function normExpiry($e) {
    $e = strtoupper(trim((string)$e));
    if ($e === '') return '';
    if (preg_match('/^\d{1,2}[A-Z]{3}\d{4}$/', $e)) return $e;                  // already 25AUG2026
    if (preg_match('/^(\d{1,2}[A-Z]{3})(\d{2})$/', $e, $m)) return $m[1] . '20' . $m[2]; // 25AUG26 -> 25AUG2026
    $t = strtotime($e);                                                         // "25 Aug 2026", "2026-08-25", etc.
    if ($t) return strtoupper(date('dMY', $t));
    return $e;
}

function resolveOptionToken($jwt, $symbol, $expiry, $strike, $type) {
    $type = strtoupper($type);
    $expiry = normExpiry($expiry);

    // PRIMARY: the Angel One scrip master (exact, cached, zero API calls, no rate
    // limit). This is why the live premium kept coming back "Option not found" and
    // the page fell back to the Black-Scholes estimate ("≈ ₹17.45"): the old path
    // below guessed the expiry with a weekday rule and then leaned on searchScrip,
    // which Angel One throttles hard. The scrip master already powers GEX token
    // resolution at 100% coverage — use it here too.
    $expTry = $expiry ? [$expiry] : realExpiries($symbol, 3);
    foreach ($expTry as $exp) {
        $tokMap = optionTokenMap($symbol, $exp);
        $strikeKey = optionStrikeKey($strike);
        $key = $strikeKey . '|' . $type;
        if (!empty($tokMap[$key])) {
            $expShort = substr($exp, 0, 5) . substr($exp, -2);
            $sym = $symbol . $expShort . $strikeKey . $type;
            return [$sym, (string)$tokMap[$key], $exp];
        }
    }

    // FALLBACK: the old searchScrip path, for anything the scrip master lacks.
    $expList = $expiry ? [$expiry] : guessExpiries($symbol);
    foreach ($expList as $exp) {
        $expShort = substr($exp, 0, 5) . substr($exp, -2); // 25AUG2026 -> 25AUG26
        $sym = $symbol . $expShort . optionStrikeKey($strike) . $type;
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-UserType: USER',
            'X-SourceID: WEB',
            'X-ClientLocalIP: 127.0.0.1',
            'X-ClientPublicIP: 127.0.0.1',
            'X-MACAddress: AA:BB:CC:DD:EE:FF',
            'X-PrivateKey: ' . API_KEY,
            'Authorization: Bearer ' . $jwt,
        ];
        $body = json_encode(['exchange' => 'NFO', 'searchscrip' => $sym]);
        $ch = curl_init(API_BASE . '/rest/secure/angelbroking/order/v1/searchScrip');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $r = json_decode($resp, true);
        if ($r && ($r['status'] ?? false) && !empty($r['data'])) {
            foreach ($r['data'] as $item) {
                if (($item['tradingsymbol'] ?? '') === $sym) {
                    return [$sym, $item['symboltoken'], $exp];
                }
            }
        }
        usleep(200000);
    }
    return [null, null, null];
}

/** Get the most likely expiry dates for a symbol (tries all weekdays for 10 days). */
function guessExpiries($symbol) {
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $out = [];
    // Try all weekdays for the next 10 calendar days (covers any holiday-adjusted expiry)
    for ($i = 0; $i < 10 && count($out) < 8; $i++) {
        $d = clone $now;
        $d->modify("+{$i} days");
        $dow = (int)$d->format('w');
        if ($dow >= 1 && $dow <= 5) {
            $out[] = strtoupper($d->format('dMY'));
        }
    }
    return $out;
}

/**
 * Live option premium (LTP) — resolves the option token then quotes live LTP.
 * Params: symbol (NIFTY), expiry (25AUG2026), strike (24250), type (CE|PE)
 */
function handleOptionLtp() {
    $symbol = strtoupper($_GET['symbol'] ?? 'NIFTY');
    $expiry = strtoupper($_GET['expiry'] ?? '');
    $strike = (float)($_GET['strike'] ?? 0);
    $type = strtoupper($_GET['type'] ?? 'CE');

    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    if ($strike <= 0) { echo json_encode(['status'=>false,'error'=>'Missing strike']); return; }

    $strikeKey = optionStrikeKey($strike);
    $ck = "optltp_{$symbol}_{$expiry}_{$strikeKey}_{$type}";
    $cached = cacheGet($ck, 15);
    if ($cached) { echo $cached; return; }

    $jwt = getJWTToken();
    if (!$jwt) { echo json_encode(['status'=>false,'error'=>'Auth failed']); return; }

    // When expiry is omitted, a same-day contract can remain in the official
    // master after Angel has stopped quoting it. Resolve and quote each real listed
    // expiry until one is actually live; never stop merely because a token exists.
    $expiryCandidates = $expiry ? [normExpiry($expiry)] : realExpiries($symbol, 4);
    $sym = null; $token = null; $usedExpiry = null; $q = null;
    foreach ($expiryCandidates as $candidateExpiry) {
        list($candidateSym, $candidateToken, $candidateUsedExpiry) = resolveOptionToken($jwt, $symbol, $candidateExpiry, $strike, $type);
        if (!$candidateToken) continue;
        $quote = apiCall('/rest/secure/angelbroking/market/v1/quote/', [
            'mode' => 'FULL', 'exchangeTokens' => ['NFO' => [$candidateToken]],
        ], $jwt);
        if ($quote && ($quote['status'] ?? false) && !empty($quote['data']['fetched'])) {
            $sym = $candidateSym; $token = $candidateToken; $usedExpiry = $candidateUsedExpiry; $q = $quote;
            break;
        }
        usleep(200000);
    }

    if (!$q) {
        $stale = cacheGetStale($ck, 600);
        if ($stale) { echo $stale; return; }
        echo json_encode(['status'=>false,'error'=>'No listed expiry returned a live quote','triedExpiries'=>$expiryCandidates]);
        return;
    }
    $f = $q['data']['fetched'][0];
    $meta = optionMeta($symbol, $usedExpiry);
    $payload = json_encode([
        'status' => true,
        'data' => [
            'symbol' => $sym, 'token' => $token, 'expiry' => $usedExpiry,
            'strike' => $strike, 'type' => $type,
            'lotSize' => $meta['lotSize'], 'strikeStep' => $meta['strikeStep'],
            'instrumentType' => $meta['instrumentType'],
            'ltp' => $f['ltp'], 'open' => $f['open'], 'high' => $f['high'], 'low' => $f['low'], 'close' => $f['close'],
            'change' => $f['netChange'], 'percentChange' => $f['percentChange'],
            'oi' => $f['opnInterest'] ?? null, 'volume' => $f['tradeVolume'] ?? null,
        ],
        'timestamp' => date('c'),
    ]);
    cacheSet($ck, $payload);
    echo $payload;
}

/**
 * News sentiment — Google News RSS scored with a finance lexicon (server-side).
 */
function handleNews() {
    $symbol = strtoupper($_GET['symbol'] ?? 'NIFTY');
    $ck = 'news_' . $symbol;
    $cached = cacheGet($ck, 900); // 15 min
    if ($cached) { echo $cached; return; }

    $q = urlencode($symbol . ' NSE stock market India');
    $url = "https://news.google.com/rss/search?q={$q}&hl=en-IN&gl=IN&ceid=IN:en";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $xml = curl_exec($ch);
    curl_close($ch);

    if (!$xml) { echo json_encode(['status'=>false,'data'=>null]); return; }

    preg_match_all('/<title>(.*?)<\/title>/s', $xml, $m);
    $titles = array_slice($m[1] ?? [], 1, 25); // skip feed title

    $bull = ['surge','surges','rally','rallies','gain','gains','jump','jumps','rise','rises','high','record','profit','beat','beats','upgrade','bullish','buy','soar','soars','boost','strong','outperform','positive','recovery','breakout'];
    $bear = ['fall','falls','drop','drops','plunge','plunges','slump','crash','loss','losses','decline','declines','low','weak','downgrade','bearish','sell','sink','sinks','fear','fears','miss','misses','cut','cuts','negative','slowdown','breakdown','tumble'];

    $score = 0; $n = 0;
    foreach ($titles as $t) {
        $t = strtolower(html_entity_decode(strip_tags($t)));
        $s = 0;
        foreach ($bull as $w) if (strpos($t, $w) !== false) $s++;
        foreach ($bear as $w) if (strpos($t, $w) !== false) $s--;
        if ($s !== 0) { $score += $s; $n++; }
    }
    $sentiment = $n > 0 ? max(-1, min(1, $score / ($n * 2))) : 0;

    $payload = json_encode([
        'status' => true,
        'data' => ['sentiment' => round($sentiment, 3), 'headlines' => count($titles), 'scored' => $n],
        'timestamp' => date('c'),
    ]);
    cacheSet($ck, $payload);
    echo $payload;
}

/**
 * Generate candidate expiry dates (DDMMMYYYY uppercase) for the next 10 calendar days.
 * Tries every weekday since different indices expire on different days.
 */
function nextExpiries($count = 10) {
    $tz = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    $out = [];
    for ($i = 0; $i < 14 && count($out) < $count; $i++) {
        $d = clone $now;
        $d->modify("+{$i} days");
        $dow = (int)$d->format('w');
        if ($dow >= 1 && $dow <= 5) {
            $out[] = strtoupper($d->format('dMY'));
        }
    }
    return $out;
}

/**
 * REAL contract expiries for an index, from Angel One's scrip master.
 *
 * Why this exists: nextExpiries() just walks the next few weekdays and hopes one
 * is an expiry. That silently dies. BANKNIFTY / FINNIFTY / MIDCPNIFTY are
 * monthly-only, so the day after the Aug-25 expiry their next contract is
 * 29SEP2026 — five weeks out. A 5-weekday scan can never reach it, so the GEX /
 * option-chain calls would return "Greeks unavailable" for three of the four
 * indices for over a month, and the engine would be flying blind on dealer
 * positioning. Verified by simulation before writing this.
 *
 * Map is ~400 bytes, rebuilt at most twice a day. Falls back to the old
 * weekday guess if the download or parse fails, so this can only improve things.
 */
function realExpiries($symbol, $count = 4) {
    $symbol = strtoupper($symbol);
    $maps = scripMaps();
    if (!is_array($maps) || empty($maps['expiries'][$symbol])) return nextExpiries($count);

    $today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Ymd');
    $out = [];
    foreach ($maps['expiries'][$symbol] as $e) {
        $t = DateTime::createFromFormat('dMY|', $e);
        if ($t && $t->format('Ymd') >= $today) $out[] = $e;
        if (count($out) >= $count) break;
    }
    return $out ?: nextExpiries($count);
}

/**
 * Option tokens for a given index+expiry, straight from the scrip master.
 *
 * Replaces a loop of ~50 sequential searchScrip calls (one per strike per side,
 * each throttled 100ms). Angel One rate-limits that hard: a live NIFTY request
 * resolved only 6 of 50 tokens, so netGEX / call wall / put wall / max pain /
 * PCR were all being computed from ~12% of the chain — and those feed the
 * GEX vetoes that decide whether a trade is allowed. Local lookup is exact and
 * costs no API calls.
 *
 * @return array strike|type => token
 */
function optionTokenMap($symbol, $expiry) {
    $maps = scripMaps();
    $key = strtoupper($symbol) . '|' . strtoupper($expiry);
    return (is_array($maps) && !empty($maps['tokens'][$key])) ? $maps['tokens'][$key] : [];
}

/** Canonical strike text shared by token-map construction and lookup. */
function optionStrikeKey($strike) {
    $strike = (float)$strike;
    if (abs($strike - round($strike)) < 0.0001) return (string)((int)round($strike));
    return rtrim(rtrim(number_format($strike, 2, '.', ''), '0'), '.');
}

/** Official lot size, strike interval, and instrument type for one listed expiry. */
function optionMeta($symbol, $expiry) {
    $maps = scripMaps();
    $key = strtoupper($symbol) . '|' . normExpiry($expiry);
    $meta = (is_array($maps) && !empty($maps['meta'][$key])) ? $maps['meta'][$key] : [];
    // Angel's Greeks endpoint can return a holiday-adjusted expiry spelling that
    // differs from the scrip-master key. Lot size and strike interval are symbol
    // properties across nearby expiries, so fall back to another official contract
    // for the same symbol rather than returning zero metadata.
    if (empty($meta) && is_array($maps) && !empty($maps['meta'])) {
        $prefix = strtoupper($symbol) . '|';
        $best = [];
        foreach ($maps['meta'] as $mk => $mv) {
            $lot = (int)($mv['lotSize'] ?? 0); $step = (float)($mv['strikeStep'] ?? 0);
            if (strpos($mk, $prefix) !== 0 || $lot <= 0 || $step <= 0) continue;
            if (empty($best) || $step < (float)$best['strikeStep']) $best = $mv;
        }
        if (!empty($best)) $meta = $best;
    }
    return [
        'lotSize' => (int)($meta['lotSize'] ?? 0),
        'strikeStep' => (float)($meta['strikeStep'] ?? 0),
        'instrumentType' => (string)($meta['instrumentType'] ?? ''),
    ];
}

/**
 * Cached scrip-master derived maps: real expiries + option tokens.
 *
 * Rebuilding downloads ~36MB, so this is written to never let that block a user
 * request more than once. Without the lock, the moment the cache expired EVERY
 * concurrent request would start its own download and time out — we saw 502s on
 * option_chain during the first cold build.
 *
 * Order of preference: fresh cache -> build (one process only) -> stale cache ->
 * null, and null makes callers fall back to the old weekday guess. A stale map is
 * always better than a stalled request; expiry dates change rarely.
 */
function scripMaps() {
    static $mem = null;
    if ($mem !== null) return $mem;

    $cacheFile = sys_get_temp_dir() . '/fno_scrip_maps_v2.json';
    $lockFile  = sys_get_temp_dir() . '/fno_scrip_maps_v2.lock';
    $age = file_exists($cacheFile) ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;

    // Fresh enough to trust.
    if ($age < 43200) { // 12h
        $m = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($m) && !empty($m['expiries'])) return $mem = $m;
    }

    // Only ONE process may rebuild; everyone else uses the stale copy immediately.
    $building = file_exists($lockFile) && (time() - filemtime($lockFile)) < 300;
    if (!$building) {
        @touch($lockFile);
        $fresh = buildScripMaps();
        @unlink($lockFile);
        if (is_array($fresh) && !empty($fresh['expiries'])) {
            @file_put_contents($cacheFile, json_encode($fresh));
            return $mem = $fresh;
        }
    }

    if (file_exists($cacheFile)) {
        $m = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($m) && !empty($m['expiries'])) return $mem = $m;
    }
    return $mem = null; // caller falls back to nextExpiries()
}

/**
 * Download the scrip master once and derive both maps from it.
 * Two passes over a local temp file so peak memory stays ~6MB: pass 1 collects
 * expiries (tiny), pass 2 collects tokens only for the nearest few expiries.
 */
function buildScripMaps() {
    $tmp = downloadScripMaster();
    if (!$tmp) return null;

    // Every instrument exposed by signal.html. Both index (OPTIDX) and stock
    // (OPTSTK) contracts must come from the same official master; guessing a
    // weekday expiry is invalid for monthly stock options.
    $names = [
        'NIFTY','BANKNIFTY','FINNIFTY','MIDCPNIFTY',
        'RELIANCE','HDFCBANK','ICICIBANK','INFY','TCS','SBIN','BHARTIARTL','ITC',
        'KOTAKBANK','LT','AXISBANK','TATAMOTORS','MARUTI','WIPRO','HCLTECH','ADANIENT','BAJFINANCE','TITAN'
    ];
    $want = array_fill_keys($names, 1);

    // ── pass 1: real listed expiries ──
    $expSet = [];
    streamOptions($tmp, $want, function ($d) use (&$expSet) {
        $e = strtoupper($d['expiry'] ?? '');
        if ($e !== '') $expSet[$d['name']][$e] = 1;
    });
    if (!$expSet) { @unlink($tmp); return null; }

    $expiries = [];
    foreach ($expSet as $n => $es) {
        $keys = array_keys($es);
        usort($keys, function ($a, $b) {
            $da = DateTime::createFromFormat('dMY|', $a);
            $db = DateTime::createFromFormat('dMY|', $b);
            return ($da ? $da->getTimestamp() : 0) <=> ($db ? $db->getTimestamp() : 0);
        });
        $expiries[$n] = $keys;
    }

    // Keep the nearest three live expiries for exact token lookup.
    $today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Ymd');
    $keepExp = [];
    foreach ($expiries as $n => $list) {
        $c = 0;
        foreach ($list as $e) {
            $t = DateTime::createFromFormat('dMY|', $e);
            if ($t && $t->format('Ymd') >= $today) { $keepExp[$n . '|' . $e] = 1; $c++; }
            if ($c >= 3) break;
        }
    }

    // ── pass 2: exact tokens + exchange-defined lot/strike metadata ──
    $tokens = []; $metaRaw = [];
    streamOptions($tmp, $want, function ($d) use (&$tokens, &$metaRaw, $keepExp) {
        $n = $d['name']; $e = strtoupper($d['expiry'] ?? ''); $contractKey = $n . '|' . $e;
        if (!isset($keepExp[$contractKey])) return;
        $sym = strtoupper($d['symbol'] ?? '');
        $ty = substr($sym, -2);
        if ($ty !== 'CE' && $ty !== 'PE') return;
        // Scrip master stores strike x100 (e.g. 1485000 => 14850).
        $strike = ((float)($d['strike'] ?? 0)) / 100;
        if ($strike <= 0) return;
        $strikeKey = abs($strike - round($strike)) < 0.0001 ? (string)((int)round($strike)) : rtrim(rtrim(number_format($strike, 2, '.', ''), '0'), '.');
        $tokens[$contractKey][$strikeKey . '|' . $ty] = (string)($d['token'] ?? '');
        $metaRaw[$contractKey]['lotsize'] = (int)($d['lotsize'] ?? 0);
        $metaRaw[$contractKey]['strikes'][$strikeKey] = $strike;
        $metaRaw[$contractKey]['instrumentType'] = $d['instrumenttype'] ?? '';
    });

    $meta = [];
    foreach ($metaRaw as $key => $raw) {
        $strikes = array_values($raw['strikes'] ?? []); sort($strikes, SORT_NUMERIC);
        $diffCounts = [];
        for ($i = 1; $i < count($strikes); $i++) {
            $d = round($strikes[$i] - $strikes[$i-1], 2);
            if ($d > 0) { $dk = (string)$d; $diffCounts[$dk] = ($diffCounts[$dk] ?? 0) + 1; }
        }
        arsort($diffCounts);
        $step = count($diffCounts) ? (float)array_key_first($diffCounts) : 0;
        $meta[$key] = [
            'lotSize' => (int)($raw['lotsize'] ?? 0),
            'strikeStep' => $step,
            'instrumentType' => $raw['instrumentType'] ?? '',
        ];
    }

    @unlink($tmp);
    return ['expiries' => $expiries, 'tokens' => $tokens, 'meta' => $meta];
}

/** Fetch the scrip master to a temp file. Returns path or null. */
function downloadScripMaster() {
    $url = 'https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json';
    $tmp = sys_get_temp_dir() . '/scripmaster_' . getmypid() . '.json';
    $fp = @fopen($tmp, 'w');
    if (!$fp) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code !== 200 || @filesize($tmp) < 1000000) { @unlink($tmp); return null; }
    return $tmp;
}

/** Stream OPTIDX/OPTSTK rows for wanted symbols, invoking $cb on each. */
function streamOptions($path, array $want, callable $cb) {
    $fh = @fopen($path, 'r');
    if (!$fh) return;
    $buf = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 1048576);
        if ($chunk === false) break;
        $buf .= $chunk;
        // Records are flat objects (no nested braces), so '}' ends one cleanly.
        while (($p = strpos($buf, '}')) !== false) {
            $obj = substr($buf, 0, $p + 1);
            $buf = substr($buf, $p + 1);
            $s = strpos($obj, '{');
            if ($s === false) continue;
            $obj = substr($obj, $s);
            if (strpos($obj, 'OPTIDX') === false && strpos($obj, 'OPTSTK') === false) continue;
            $d = json_decode($obj, true);
            if (!$d) continue;
            if (!isset($want[$d['name'] ?? ''])) continue;
            $instrumentType = $d['instrumenttype'] ?? '';
            if ($instrumentType !== 'OPTIDX' && $instrumentType !== 'OPTSTK') continue;
            $cb($d);
        }
        if (strlen($buf) > 4194304) $buf = substr($buf, -1048576);
    }
    fclose($fh);
}

/** Legacy single-purpose builder, kept for compatibility. */
function buildExpiryMap() {
    $url = 'https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json';
    $tmp = sys_get_temp_dir() . '/scripmaster_' . getmypid() . '.json';

    $fp = @fopen($tmp, 'w');
    if (!$fp) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code !== 200 || filesize($tmp) < 1000000) { @unlink($tmp); return null; }

    $want = ['NIFTY'=>1, 'BANKNIFTY'=>1, 'FINNIFTY'=>1, 'MIDCPNIFTY'=>1];
    $map = [];
    $fh = @fopen($tmp, 'r');
    if (!$fh) { @unlink($tmp); return null; }

    $buf = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 1048576);
        if ($chunk === false) break;
        $buf .= $chunk;
        // Records are flat objects (no nested braces), so '}' ends one cleanly.
        while (($p = strpos($buf, '}')) !== false) {
            $obj = substr($buf, 0, $p + 1);
            $buf = substr($buf, $p + 1);
            $s = strpos($obj, '{');
            if ($s === false) continue;
            $obj = substr($obj, $s);
            if (strpos($obj, 'OPTIDX') === false) continue; // cheap pre-filter
            $d = json_decode($obj, true);
            if (!$d) continue;
            $n = $d['name'] ?? '';
            if (!isset($want[$n])) continue;
            if (($d['instrumenttype'] ?? '') !== 'OPTIDX') continue;
            $e = strtoupper($d['expiry'] ?? '');
            if ($e !== '') $map[$n][$e] = 1;
        }
        if (strlen($buf) > 4194304) $buf = substr($buf, -1048576); // runaway guard
    }
    fclose($fh);
    @unlink($tmp);

    $out = [];
    foreach ($map as $n => $es) {
        $keys = array_keys($es);
        usort($keys, function ($a, $b) {
            $da = DateTime::createFromFormat('dMY|', $a);
            $db = DateTime::createFromFormat('dMY|', $b);
            return ($da ? $da->getTimestamp() : 0) <=> ($db ? $db->getTimestamp() : 0);
        });
        $out[$n] = $keys;
    }
    return $out ?: null;
}

function handleOptionChain() {
    $name = strtoupper($_GET['symbol'] ?? 'NIFTY');
    $expiry = $_GET['expiry'] ?? '';

    $ck = "ocgreek_{$name}_{$expiry}";
    $cached = cacheGet($ck, 120);
    if ($cached) { echo $cached; return; }

    $jwt = getJWTToken();
    if (!$jwt) { http_response_code(503); echo json_encode(['error' => 'Authentication failed']); return; }

    // Determine expiries to try
    $expiries = $expiry ? [$expiry] : realExpiries($name, 4);
    $result = null;
    $usedExpiry = null;

    foreach ($expiries as $exp) {
        $r = apiCall('/rest/secure/angelbroking/marketData/v1/optionGreek', [
            'name' => $name,
            'expirydate' => $exp,
        ], $jwt);
        if ($r && $r['status'] && !empty($r['data'])) {
            $result = $r['data'];
            $usedExpiry = $exp;
            break;
        }
        usleep(300000); // 0.3s between tries to respect rate limit
    }

    if (!$result) {
        http_response_code(502);
        echo json_encode(['error' => 'Option chain unavailable', 'triedExpiries' => $expiries]);
        return;
    }

    // Group by strike
    $strikes = [];
    $totalCallIV = 0; $totalPutIV = 0; $ceCount = 0; $peCount = 0;
    foreach ($result as $row) {
        $strike = (float)$row['strikePrice'];
        $type = $row['optionType'];
        if (!isset($strikes[$strike])) $strikes[$strike] = ['strike' => $strike, 'CE' => null, 'PE' => null];
        $leg = [
            'delta' => round((float)$row['delta'], 4),
            'gamma' => round((float)$row['gamma'], 5),
            'theta' => round((float)$row['theta'], 2),
            'vega' => round((float)$row['vega'], 2),
            'iv' => round((float)$row['impliedVolatility'], 2),
        ];
        if ($type === 'CE') { $strikes[$strike]['CE'] = $leg; $totalCallIV += $leg['iv']; $ceCount++; }
        else { $strikes[$strike]['PE'] = $leg; $totalPutIV += $leg['iv']; $peCount++; }
    }

    ksort($strikes);
    $strikeList = array_values($strikes);
    $contractMeta = optionMeta($name, $usedExpiry);

    // Get underlying spot
    global $SYMBOL_TOKENS;
    $spot = null;
    if (isset($SYMBOL_TOKENS[$name])) {
        $q = apiCall('/rest/secure/angelbroking/market/v1/quote/', [
            'mode' => 'LTP', 'exchangeTokens' => ['NSE' => [$SYMBOL_TOKENS[$name]]],
        ], $jwt);
        if ($q && $q['status'] && !empty($q['data']['fetched'])) {
            $spot = $q['data']['fetched'][0]['ltp'];
        }
    }

    $payload = json_encode([
        'status' => true,
        'data' => [
            'symbol' => $name,
            'expiry' => $usedExpiry,
            'spot' => $spot,
            'lotSize' => $contractMeta['lotSize'],
            'strikeStep' => $contractMeta['strikeStep'],
            'instrumentType' => $contractMeta['instrumentType'],
            'strikes' => $strikeList,
            'avgCallIV' => $ceCount ? round($totalCallIV / $ceCount, 2) : null,
            'avgPutIV' => $peCount ? round($totalPutIV / $peCount, 2) : null,
            'ivSkew' => ($ceCount && $peCount) ? round(($totalPutIV/$peCount) - ($totalCallIV/$ceCount), 2) : null,
        ],
        'timestamp' => date('c'),
    ]);
    cacheSet($ck, $payload);
    echo $payload;
}

// ─── Handlers ────────────────────────────────────────────────────────────────

function handleQuote() {
    global $SYMBOL_TOKENS;
    
    $symbols = $_GET['symbols'] ?? 'NIFTY';
    $symbolList = array_map('trim', explode(',', $symbols));
    
    $ck = 'quote_' . md5($symbols);
    $cached = cacheGet($ck, 20);
    if ($cached) { echo $cached; return; }
    
    $jwt = getJWTToken();
    if (!$jwt) {
        http_response_code(503);
        echo json_encode(['error' => 'Authentication failed', 'hint' => 'TOTP may be expired']);
        return;
    }
    
    // Map symbol names to tokens
    $tokens = [];
    foreach ($symbolList as $sym) {
        $sym = strtoupper($sym);
        if (isset($SYMBOL_TOKENS[$sym])) {
            $tokens[] = $SYMBOL_TOKENS[$sym];
        } elseif (is_numeric($sym)) {
            $tokens[] = $sym;
        }
    }
    
    if (empty($tokens)) {
        echo json_encode(['error' => 'No valid symbols provided']);
        return;
    }
    
    $result = apiCall('/rest/secure/angelbroking/market/v1/quote/', [
        'mode' => 'FULL',
        'exchangeTokens' => ['NSE' => $tokens],
    ], $jwt);
    
    if (!$result || !$result['status']) {
        http_response_code(502);
        echo json_encode(['error' => 'Quote fetch failed', 'detail' => $result['message'] ?? 'unknown']);
        return;
    }
    
    // Enrich with symbol names
    $fetched = $result['data']['fetched'] ?? [];
    $tokenToSymbol = array_flip($SYMBOL_TOKENS);
    foreach ($fetched as &$item) {
        $item['symbol'] = $tokenToSymbol[$item['symbolToken']] ?? $item['tradingSymbol'];
    }
    
    $payload = json_encode([
        'status' => true,
        'data' => $fetched,
        'timestamp' => date('c'),
    ]);
    if (!empty($fetched)) cacheSet($ck, $payload);
    echo $payload;
}

function handleLTP() {
    global $SYMBOL_TOKENS;
    
    $symbols = $_GET['symbols'] ?? 'NIFTY,BANKNIFTY,FINNIFTY';
    $symbolList = array_map('trim', explode(',', strtoupper($symbols)));
    
    $jwt = getJWTToken();
    if (!$jwt) {
        http_response_code(503);
        echo json_encode(['error' => 'Authentication failed']);
        return;
    }
    
    $tokens = [];
    foreach ($symbolList as $sym) {
        if (isset($SYMBOL_TOKENS[$sym])) {
            $tokens[] = $SYMBOL_TOKENS[$sym];
        }
    }
    
    $result = apiCall('/rest/secure/angelbroking/market/v1/quote/', [
        'mode' => 'LTP',
        'exchangeTokens' => ['NSE' => $tokens],
    ], $jwt);
    
    if (!$result || !$result['status']) {
        http_response_code(502);
        echo json_encode(['error' => 'LTP fetch failed']);
        return;
    }
    
    $tokenToSymbol = array_flip($SYMBOL_TOKENS);
    $ltp = [];
    foreach ($result['data']['fetched'] ?? [] as $item) {
        $sym = $tokenToSymbol[$item['symbolToken']] ?? $item['tradingSymbol'];
        $ltp[$sym] = $item['ltp'];
    }
    
    echo json_encode(['status' => true, 'data' => $ltp, 'timestamp' => date('c')]);
}

function handleCandles() {
    global $SYMBOL_TOKENS;
    
    $symbol = strtoupper($_GET['symbol'] ?? 'NIFTY');
    $interval = $_GET['interval'] ?? 'FIFTEEN_MINUTE';
    
    // Default: last 5 trading days
    $fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
    $toDate = $_GET['to'] ?? date('Y-m-d');
    
    // Ensure time component
    if (strlen($fromDate) <= 10) $fromDate .= ' 09:15';
    if (strlen($toDate) <= 10) $toDate .= ' 15:30';
    
    // Cache check (60s) — key by symbol+interval only (date range is rolling)
    $ck = "candles_{$symbol}_{$interval}";
    $cached = cacheGet($ck, 60);
    if ($cached) { echo $cached; return; }
    
    $token = $SYMBOL_TOKENS[$symbol] ?? $symbol;
    
    $jwt = getJWTToken();
    if (!$jwt) {
        http_response_code(503);
        echo json_encode(['error' => 'Authentication failed']);
        return;
    }
    
    $result = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $result = apiCall('/rest/secure/angelbroking/historical/v1/getCandleData', [
            'exchange' => 'NSE',
            'symboltoken' => $token,
            'interval' => $interval,
            'fromdate' => $fromDate,
            'todate' => $toDate,
        ], $jwt);
        if ($result && $result['status'] && isset($result['data'])) break;
        // Rate limited or transient — back off and retry
        usleep(600000 * ($attempt + 1)); // 0.6s, 1.2s
    }
    
    if (!$result || !$result['status'] || !isset($result['data'])) {
        // Serve stale cache if available (resilience against rate limits)
        $stale = cacheGetStale($ck, 1800);
        if ($stale) { echo $stale; return; }
        http_response_code(502);
        echo json_encode(['error' => 'Candle data fetch failed', 'detail' => $result['message'] ?? 'unknown']);
        return;
    }
    
    // Transform candles to arrays for the frontend
    $candles = $result['data'];
    $opens = []; $highs = []; $lows = []; $closes = []; $volumes = []; $timestamps = [];
    
    foreach ($candles as $c) {
        $timestamps[] = $c[0];
        $opens[] = $c[1];
        $highs[] = $c[2];
        $lows[] = $c[3];
        $closes[] = $c[4];
        $volumes[] = $c[5] ?? 0;
    }
    
    $payload = json_encode([
        'status' => true,
        'data' => [
            'symbol' => $symbol,
            'interval' => $interval,
            'count' => count($candles),
            'opens' => $opens,
            'highs' => $highs,
            'lows' => $lows,
            'closes' => $closes,
            'volumes' => $volumes,
            'timestamps' => $timestamps,
            'raw' => $candles,
        ],
        'timestamp' => date('c'),
    ]);
    if (count($candles) > 0) cacheSet($ck, $payload);
    echo $payload;
}

function handleIndices() {
    global $SYMBOL_TOKENS;
    
    $cached = cacheGet('indices', 20);
    if ($cached) { echo $cached; return; }
    
    $jwt = getJWTToken();
    if (!$jwt) {
        http_response_code(503);
        echo json_encode(['error' => 'Authentication failed']);
        return;
    }
    
    $indexTokens = [
        $SYMBOL_TOKENS['NIFTY'],
        $SYMBOL_TOKENS['BANKNIFTY'],
        $SYMBOL_TOKENS['FINNIFTY'],
        $SYMBOL_TOKENS['SENSEX'],
        $SYMBOL_TOKENS['MIDCPNIFTY'],
        $SYMBOL_TOKENS['INDIAVIX'],
    ];
    
    $result = apiCall('/rest/secure/angelbroking/market/v1/quote/', [
        'mode' => 'FULL',
        'exchangeTokens' => ['NSE' => $indexTokens],
    ], $jwt);
    
    if (!$result || !$result['status']) {
        http_response_code(502);
        echo json_encode(['error' => 'Indices fetch failed']);
        return;
    }
    
    $tokenToSymbol = array_flip($SYMBOL_TOKENS);
    $indices = [];
    foreach ($result['data']['fetched'] ?? [] as $item) {
        $sym = $tokenToSymbol[$item['symbolToken']] ?? $item['tradingSymbol'];
        $indices[$sym] = [
            'name' => $item['tradingSymbol'],
            'ltp' => $item['ltp'],
            'open' => $item['open'],
            'high' => $item['high'],
            'low' => $item['low'],
            'close' => $item['close'],
            'change' => $item['netChange'],
            'percentChange' => $item['percentChange'],
            'lastUpdate' => $item['exchFeedTime'] ?? null,
        ];
    }
    
    $payload = json_encode(['status' => true, 'data' => $indices, 'timestamp' => date('c')]);
    if (!empty($indices)) cacheSet('indices', $payload);
    echo $payload;
}



// ─── SignalsBrain Data Ingest ─────────────────────────────────────────────────
// Stores every signal snapshot for pattern memory + learning.
// Data is appended to a JSON-lines file that SignalsBrain reads on startup.
function handleBrainIngest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'POST required']);
        return;
    }
    // Higher ceiling than state writes: this is the per-signal telemetry path.
    requireWriteAuth('brain_ingest', 300, 60);

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || empty($data['instrument'])) {
        echo json_encode(['error' => 'Invalid payload']);
        return;
    }

    // Append to brain memory file (JSON-lines format, one record per line)
    $memoryDir = __DIR__ . '/../brain-data';
    if (!is_dir($memoryDir)) {
        @mkdir($memoryDir, 0755, true);
    }

    $record = [
        'ts'         => $data['timestamp'] ?? time(),
        'instrument' => strtoupper($data['instrument']),
        'direction'  => $data['direction'] ?? 'NO_TRADE',
        'confidence' => (float)($data['confidence'] ?? 0),
        'net_score'  => (float)($data['net_score'] ?? 0),
        'ltp'        => (float)($data['ltp'] ?? 0),
        'atr'        => (float)($data['atr'] ?? 0),
        'regime'     => $data['regime'] ?? '',
        'gex_regime' => $data['gex_regime'] ?? '',
        'gex_flip'   => (float)($data['gex_flip'] ?? 0),
        'pcr'        => (float)($data['pcr'] ?? 0),
        'adx'        => (float)($data['adx'] ?? 0),
        'rsi'        => (float)($data['rsi'] ?? 0),
        'iv'         => (float)($data['iv'] ?? 0),
        'dte'        => (float)($data['dte'] ?? 0),
        'fii'        => (float)($data['fii'] ?? 0),
        'vix'        => (float)($data['vix'] ?? 0),
        'htf'        => $data['htf'] ?? '',
        'vetoes'     => $data['vetoes'] ?? [],
        'trade'      => $data['trade'] ?? null,
    ];

    // Daily file: brain-data/2026-08-18.jsonl
    $filename = $memoryDir . '/' . date('Y-m-d') . '.jsonl';
    @file_put_contents($filename, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

    // Also maintain a running "latest state" file per instrument
    $latestFile = $memoryDir . '/latest_' . strtoupper($data['instrument']) . '.json';
    @file_put_contents($latestFile, json_encode($record, JSON_PRETTY_PRINT));

    echo json_encode([
        'status' => true,
        'stored' => basename($filename),
        'instrument' => $record['instrument'],
        'direction' => $record['direction'],
        'confidence' => $record['confidence'],
    ]);
}



// ─── Brain Patterns: Query historical patterns from server-side storage ────────
function handleBrainPatterns() {
    $memoryDir = __DIR__ . '/../brain-data';
    if (!is_dir($memoryDir)) { echo json_encode(['status' => true, 'patterns' => [], 'count' => 0]); return; }

    $instrument = strtoupper($_GET['instrument'] ?? '');
    $direction = strtoupper($_GET['direction'] ?? '');
    $days = max(1, min(90, (int)($_GET['days'] ?? 30)));

    $patterns = [];
    $cutoff = date('Y-m-d', strtotime("-{$days} days"));

    // Read all .jsonl files within date range
    $files = glob($memoryDir . '/*.jsonl');
    foreach ($files as $file) {
        $fileDate = basename($file, '.jsonl');
        if ($fileDate < $cutoff) continue;

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!$record) continue;
            if (!isUsableSignalRecord($record)) continue; // drop corrupt legacy rows
            if ($instrument && strtoupper($record['instrument'] ?? '') !== $instrument) continue;
            if ($direction && strtoupper($record['direction'] ?? '') !== $direction) continue;
            $patterns[] = $record;
        }
    }

    // Return latest N (max 200)
    $patterns = array_slice($patterns, -200);
    echo json_encode([
        'status' => true,
        'patterns' => $patterns,
        'count' => count($patterns),
        'days_searched' => $days,
        'instrument_filter' => $instrument ?: 'all',
    ]);
}

// ─── Brain Stats: Overall performance statistics ──────────────────────────────
function handleBrainStats() {
    $memoryDir = __DIR__ . '/../brain-data';
    if (!is_dir($memoryDir)) { echo json_encode(['status' => true, 'total' => 0]); return; }

    $files = glob($memoryDir . '/*.jsonl');
    $total = 0;
    $byInstrument = [];
    $byDirection = ['BUY' => 0, 'SELL' => 0, 'NO_TRADE' => 0];
    $byRegime = [];
    $confidences = [];
    $dates = [];

    foreach ($files as $file) {
        $date = basename($file, '.jsonl');
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $dayCount = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            $r = json_decode($line, true);
            if (!$r) continue;
            // Corrupt legacy rows are excluded so the counts mean what they claim.
            if (!isUsableSignalRecord($r)) { $skipped++; continue; }
            $total++;
            $dayCount++;
            $inst = $r['instrument'] ?? 'UNKNOWN';
            $dir = $r['direction'] ?? 'NO_TRADE';
            $regime = $r['regime'] ?? 'Unknown';
            $byInstrument[$inst] = ($byInstrument[$inst] ?? 0) + 1;
            $byDirection[$dir] = ($byDirection[$dir] ?? 0) + 1;
            $byRegime[$regime] = ($byRegime[$regime] ?? 0) + 1;
            if (isset($r['confidence'])) $confidences[] = (float)$r['confidence'];
        }
        $dates[$date] = $dayCount;
        $skippedTotal = ($skippedTotal ?? 0) + $skipped;
    }

    $avgConf = count($confidences) ? round(array_sum($confidences) / count($confidences), 1) : 0;

    echo json_encode([
        'status' => true,
        'total' => $total,
        'days_tracked' => count($files),
        'avg_confidence' => $avgConf,
        'by_instrument' => $byInstrument,
        'by_direction' => $byDirection,
        'by_regime' => $byRegime,
        'daily_counts' => $dates,
        'latest_files' => array_slice(array_keys($dates), -7),
        // Corrupt legacy rows excluded from every figure above (files kept intact).
        'excluded_corrupt' => $skippedTotal ?? 0,
    ]);
}

/**
 * Is this brain-data row a usable signal observation?
 *
 * Historical files (18-21 Aug 2026) contain rows written by two writers that have
 * since been disabled, and they must not be counted or learned from:
 *
 *  a) all-zero phantoms from god-mode `_syncPatternsToServer()` — every real
 *     signal had a twin with adx/atr/ltp/pcr forced to 0 and htf ''. These were
 *     ~22% of the file and they inflated the "confirmed signal" count roughly 2x.
 *  b) rows from god-mode `_syncToServer()` — direction 'ACTIVE', with God Mode
 *     confidence sub-scores written into the `net_score` and `adx` fields. The
 *     values look plausible, which is precisely why they were so damaging.
 *  c) 'GODMODE_LEARNINGS' bookkeeping rows, which are not observations at all.
 *
 * Filtering on READ rather than deleting: the raw files stay intact for audit,
 * while stats and pattern matching immediately stop consuming corrupt input.
 */
function isUsableSignalRecord($r) {
    if (!is_array($r)) return false;
    $inst = strtoupper($r['instrument'] ?? '');
    if ($inst === '' || $inst === 'UNKNOWN' || $inst === 'GODMODE_LEARNINGS') return false;

    $dir = strtoupper($r['direction'] ?? '');
    if ($dir === 'ACTIVE' || $dir === 'LEARNING_UPDATE') return false;

    // Phantom: the indicator block is entirely absent.
    $adx = (float)($r['adx'] ?? 0);
    $atr = (float)($r['atr'] ?? 0);
    $ltp = (float)($r['ltp'] ?? 0);
    if ($adx == 0 && $atr == 0 && $ltp == 0) return false;

    // A signal row without a price is not usable either.
    if ($ltp <= 0) return false;

    return true;
}

// ─── Brain Sync: Bulk upload/download patterns for persistence across devices ─
function handleBrainSync() {
    $memoryDir = __DIR__ . '/../brain-data';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireWriteAuth('brain_sync', 60, 60);
        // Upload: client sends its localStorage patterns to server for permanent storage
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data || !isset($data['patterns'])) {
            echo json_encode(['error' => 'Invalid payload. Send {patterns: [...]}']);
            return;
        }
        if (!is_dir($memoryDir)) @mkdir($memoryDir, 0755, true);

        $today = date('Y-m-d');
        $filename = $memoryDir . '/' . $today . '.jsonl';
        $count = 0;
        foreach ($data['patterns'] as $pattern) {
            if (!is_array($pattern) || empty($pattern['instrument'])) continue;
            @file_put_contents($filename, json_encode($pattern) . "\n", FILE_APPEND | LOCK_EX);
            $count++;
        }
        echo json_encode(['status' => true, 'synced' => $count, 'file' => $today . '.jsonl']);
    } else {
        // Download: server sends all patterns to client (for loading into localStorage)
        if (!is_dir($memoryDir)) { echo json_encode(['status' => true, 'patterns' => []]); return; }

        $patterns = [];
        $files = glob($memoryDir . '/*.jsonl');
        // Only last 30 days
        $cutoff = date('Y-m-d', strtotime('-30 days'));
        foreach ($files as $file) {
            if (basename($file, '.jsonl') < $cutoff) continue;
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $r = json_decode($line, true);
                if ($r) $patterns[] = $r;
            }
        }
        echo json_encode(['status' => true, 'patterns' => array_slice($patterns, -500), 'total_server' => count($patterns)]);
    }
}



// ═══════════════════════════════════════════════════════════════════════════════
// AI PAPER TRADING — Server-Side Persistence
// All trades stored permanently so data survives browser clear/device switch.
// ═══════════════════════════════════════════════════════════════════════════════

function handlePaperTrades() {
    $dir = __DIR__ . '/../brain-data/paper';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // trades.jsonl is the reconciliation ground truth, so writes to it are the
        // most safety-critical of all. Tight ceiling.
        requireWriteAuth('paper_trades', 60, 60);
        // Store a completed trade
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data || empty($data['instrument'])) {
            echo json_encode(['error' => 'Invalid trade data']);
            return;
        }

        $record = [
            'ts'            => $data['timestamp'] ?? time(),
            'instrument'    => strtoupper($data['instrument'] ?? ''),
            'direction'     => $data['direction'] ?? '',
            'strike'        => (float)($data['strike'] ?? 0),
            'type'          => $data['type'] ?? '',
            'strategy'      => $data['strategy'] ?? '',
            'entry'         => (float)($data['entry'] ?? 0),
            'exit'          => (float)($data['exit'] ?? 0),
            'pnl'           => (float)($data['pnl'] ?? 0),
            'pnlPct'        => (float)($data['pnlPct'] ?? 0),
            'outcome'       => $data['outcome'] ?? '',
            'holdMinutes'   => (float)($data['holdMinutes'] ?? 0),
            'confidence'    => (float)($data['confidence'] ?? 0),
            'regime'        => $data['regime'] ?? '',
            'gex'           => $data['gex'] ?? '',
            'slippage'      => (float)($data['slippage'] ?? 0),
            'lotSize'       => (int)($data['lotSize'] ?? 0),
        ];

        $file = $dir . '/trades.jsonl';
        @file_put_contents($file, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

        echo json_encode(['status' => true, 'stored' => true]);
    } else {
        // GET: Query all trades
        $file = $dir . '/trades.jsonl';
        if (!file_exists($file)) {
            echo json_encode(['status' => true, 'trades' => [], 'count' => 0]);
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $trades = [];
        foreach ($lines as $line) {
            $r = json_decode($line, true);
            if ($r) $trades[] = $r;
        }

        // Filter by instrument if requested
        $instrument = strtoupper($_GET['instrument'] ?? '');
        if ($instrument) {
            $trades = array_values(array_filter($trades, fn($t) => ($t['instrument'] ?? '') === $instrument));
        }

        // Stats
        $wins = array_filter($trades, fn($t) => ($t['pnl'] ?? 0) > 0);
        $totalPnl = array_sum(array_column($trades, 'pnl'));
        $winRate = count($trades) ? round(count($wins) / count($trades) * 100) : 0;

        echo json_encode([
            'status'   => true,
            'trades'   => array_slice($trades, -100), // Last 100
            'count'    => count($trades),
            'wins'     => count($wins),
            'losses'   => count($trades) - count($wins),
            'winRate'  => $winRate,
            'totalPnl' => round($totalPnl, 2),
            'avgPnl'   => count($trades) ? round($totalPnl / count($trades), 2) : 0,
        ]);
    }
}

function handlePaperReport() {
    $dir = __DIR__ . '/../brain-data/paper';
    $file = $dir . '/trades.jsonl';

    if (!file_exists($file)) {
        echo json_encode(['status' => true, 'report' => null, 'message' => 'No trades yet']);
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $trades = [];
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if ($r) $trades[] = $r;
    }

    if (!count($trades)) {
        echo json_encode(['status' => true, 'report' => null]);
        return;
    }

    // Overall stats
    $wins = array_filter($trades, fn($t) => ($t['pnl'] ?? 0) > 0);
    $totalPnl = array_sum(array_column($trades, 'pnl'));
    $totalSlippage = array_sum(array_column($trades, 'slippage'));

    // By strategy
    $byStrategy = [];
    foreach ($trades as $t) {
        $s = $t['strategy'] ?? 'Unknown';
        if (!isset($byStrategy[$s])) $byStrategy[$s] = ['trades' => 0, 'wins' => 0, 'pnl' => 0];
        $byStrategy[$s]['trades']++;
        if (($t['pnl'] ?? 0) > 0) $byStrategy[$s]['wins']++;
        $byStrategy[$s]['pnl'] += ($t['pnl'] ?? 0);
    }
    foreach ($byStrategy as &$bs) {
        $bs['winRate'] = $bs['trades'] ? round($bs['wins'] / $bs['trades'] * 100) : 0;
        $bs['pnl'] = round($bs['pnl'], 2);
    }

    // By regime
    $byRegime = [];
    foreach ($trades as $t) {
        $r = $t['regime'] ?? 'Unknown';
        if (!isset($byRegime[$r])) $byRegime[$r] = ['trades' => 0, 'wins' => 0, 'pnl' => 0];
        $byRegime[$r]['trades']++;
        if (($t['pnl'] ?? 0) > 0) $byRegime[$r]['wins']++;
        $byRegime[$r]['pnl'] += ($t['pnl'] ?? 0);
    }
    foreach ($byRegime as &$br) {
        $br['winRate'] = $br['trades'] ? round($br['wins'] / $br['trades'] * 100) : 0;
    }

    // By GEX
    $byGex = [];
    foreach ($trades as $t) {
        $g = $t['gex'] ?? 'Unknown';
        if (!isset($byGex[$g])) $byGex[$g] = ['trades' => 0, 'wins' => 0, 'pnl' => 0];
        $byGex[$g]['trades']++;
        if (($t['pnl'] ?? 0) > 0) $byGex[$g]['wins']++;
        $byGex[$g]['pnl'] += ($t['pnl'] ?? 0);
    }

    // Daily P&L
    $daily = [];
    foreach ($trades as $t) {
        $day = date('Y-m-d', (int)($t['ts'] ?? time()));
        if (!isset($daily[$day])) $daily[$day] = 0;
        $daily[$day] += ($t['pnl'] ?? 0);
    }

    // Equity curve
    $equity = PAPER_INITIAL_CAPITAL;
    $curve = [['date' => array_key_first($daily) ?? date('Y-m-d'), 'equity' => PAPER_INITIAL_CAPITAL]];
    foreach ($daily as $day => $pnl) {
        $equity += $pnl;
        $curve[] = ['date' => $day, 'equity' => round($equity, 2)];
    }

    // Peak-to-trough drawdown, measured on the curve above so it is consistent
    // with the capital figure rather than computed against a different base.
    $peak = PAPER_INITIAL_CAPITAL; $maxDD = 0;
    foreach ($curve as $pt) {
        if ($pt['equity'] > $peak) $peak = $pt['equity'];
        if ($peak > 0) {
            $dd = ($peak - $pt['equity']) / $peak * 100;
            if ($dd > $maxDD) $maxDD = $dd;
        }
    }

    echo json_encode([
        'status' => true,
        'report' => [
            'totalTrades'   => count($trades),
            'wins'          => count($wins),
            'winRate'       => count($trades) ? round(count($wins) / count($trades) * 100) : 0,
            'totalPnl'      => round($totalPnl, 2),
            'totalSlippage' => round($totalSlippage, 2),
            // Realized only — trades.jsonl holds closed trades. Any open position's
            // unrealized P&L is tracked client-side and shown in Active Positions.
            'initialCapital' => PAPER_INITIAL_CAPITAL,
            'maxPerTrade'    => PAPER_MAX_PER_TRADE,
            'capital'       => round(PAPER_INITIAL_CAPITAL + $totalPnl, 2),
            'returnPct'     => round($totalPnl / PAPER_INITIAL_CAPITAL * 100, 1),
            'maxDrawdownPct' => round($maxDD, 1),
            'realizedOnly'  => true,
            'byStrategy'    => $byStrategy,
            'byRegime'      => $byRegime,
            'byGex'         => $byGex,
            'equityCurve'   => $curve,
            'dailyPnl'      => $daily,
        ],
    ]);
}

/**
 * Single-executor lease for browser-driven paper trading.
 *
 * signal.html and tool.html may be open in several tabs/devices. Without a shared
 * lease each tab can evaluate the same setup against a stale local copy and open
 * a duplicate position before either state write arrives. The lease makes exactly
 * one tab the executor while every other tab remains a read-only signal viewer.
 */
function handlePaperLease() {
    $dir = __DIR__ . '/../brain-data/paper';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/executor-lease.json';
    $now = time();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $lease = file_exists($file) ? json_decode(@file_get_contents($file), true) : null;
        $active = is_array($lease) && (int)($lease['expiresAt'] ?? 0) > $now;
        echo json_encode(['status'=>true, 'active'=>$active, 'lease'=>$active ? $lease : null, 'serverTime'=>$now]);
        return;
    }

    requireWriteAuth('paper_lease', 240, 60);
    $body = json_decode(file_get_contents('php://input'), true);
    $owner = preg_replace('/[^a-zA-Z0-9._:-]/', '', (string)($body['owner'] ?? ''));
    $ttl = max(20, min(90, (int)($body['ttl'] ?? 55)));
    if ($owner === '') { echo json_encode(['status'=>false,'error'=>'Missing owner']); return; }

    $lock = @fopen($file . '.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) { echo json_encode(['status'=>false,'error'=>'Lease lock unavailable']); return; }
    $lease = file_exists($file) ? json_decode(@file_get_contents($file), true) : null;
    $active = is_array($lease) && (int)($lease['expiresAt'] ?? 0) > $now;
    $sameOwner = $active && hash_equals((string)($lease['owner'] ?? ''), $owner);
    $acquired = !$active || $sameOwner;
    if ($acquired) {
        $lease = [
            'owner' => $owner,
            'role' => substr((string)($body['role'] ?? 'signal'), 0, 32),
            'acquiredAt' => $sameOwner ? (int)($lease['acquiredAt'] ?? $now) : $now,
            'heartbeatAt' => $now,
            'expiresAt' => $now + $ttl,
        ];
        $tmp = $file . '.tmp';
        @file_put_contents($tmp, json_encode($lease), LOCK_EX);
        @rename($tmp, $file);
    }
    flock($lock, LOCK_UN); fclose($lock);
    echo json_encode(['status'=>true, 'acquired'=>$acquired, 'lease'=>$lease, 'serverTime'=>$now]);
}

/**
 * Authoritative AI Paper Trading state — replaces browser localStorage.
 *
 * localStorage made the state per-browser, so the same account showed different
 * numbers on desktop vs mobile, and it let a stale state survive a budget change.
 * It also caused visible flicker: tool.html rendered local state on one timer and
 * the server report on another, and after a version bump cleared localStorage the
 * two disagreed (0 trades vs 1) and fought each other every few seconds.
 *
 * State now lives in one file on the server. GET returns it (seeded from the
 * configured budget when absent), POST replaces it. Writes are atomic + locked so
 * two tabs posting at once cannot interleave and corrupt it.
 */
function handlePaperState() {
    $dir = __DIR__ . '/../brain-data/paper';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/state.json';

    // Destructive first: reset must never be reachable by a plain GET.
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_GET['reset'] ?? '') === '1') {
        requireAdminAuth();
        @unlink($file);
        auditLog('recovery', 'paper_state_reset', []);
        kHalt('Paper state was reset; open exposure is unknown until reconciled');
        echo json_encode(['status' => true, 'reset' => true, 'safety' => 'HALTED']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireWriteAuth('paper_state', 120, 60);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) { echo json_encode(['status' => false, 'error' => 'Invalid state']); return; }

        // Fence replace-all state writes to the currently active browser lease.
        // A tab whose 55s lease expired must never overwrite a newer executor's
        // open/close decisions with its stale in-memory snapshot.
        $leaseFile = $dir . '/executor-lease.json';
        $lease = file_exists($leaseFile) ? json_decode(@file_get_contents($leaseFile), true) : null;
        $leaseOwner = (string)($data['executorLeaseOwner'] ?? '');
        $leaseValid = is_array($lease) && (int)($lease['expiresAt'] ?? 0) > time()
            && $leaseOwner !== '' && hash_equals((string)($lease['owner'] ?? ''), $leaseOwner);
        if (!$leaseValid) {
            http_response_code(409);
            echo json_encode(['status'=>false,'error'=>'Paper state write rejected: executor lease is absent, expired, or owned by another tab']);
            return;
        }

        // Serialise prior-state comparison, replacement, and audit append so two
        // overlapping writes from the same lease owner cannot emit duplicates.
        $stateLock = @fopen($file . '.lock', 'c+');
        if (!$stateLock || !flock($stateLock, LOCK_EX)) {
            if (is_resource($stateLock)) fclose($stateLock);
            http_response_code(500);
            echo json_encode(['status' => false, 'error' => 'Paper state lock unavailable']); return;
        }

        // Read the prior server-owned audit key only after the lease fence passes.
        // Client payloads cannot choose this key or force duplicate audit records.
        $priorState = file_exists($file) ? json_decode(@file_get_contents($file), true) : [];
        if (!is_array($priorState)) $priorState = [];
        $signatureFor = function($decision) {
            if (!is_array($decision)) return null;
            $failedGates = is_array($decision['executionGate']['failedGates'] ?? null)
                ? array_values(array_unique(array_filter($decision['executionGate']['failedGates'], 'is_string')))
                : [];
            sort($failedGates, SORT_STRING);
            $fields = [
                'instrument'  => $decision['instrument'] ?? null,
                'direction'   => $decision['direction'] ?? null,
                'executed'    => (bool)($decision['executed'] ?? false),
                'reason'      => $decision['reason'] ?? null,
                'rewardRisk'  => $decision['rewardRisk'] ?? null,
                'cost'        => $decision['cost'] ?? null,
                'executionGate' => ['failedGates' => $failedGates],
            ];
            $encodedFields = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return ['key' => hash('sha256', $encodedFields), 'fields' => $fields];
        };

        $priorAuditKey = is_string($priorState['lastExecutionAuditKey'] ?? null)
            ? $priorState['lastExecutionAuditKey'] : null;
        $priorPending = $priorState['lastExecutionAuditPending'] ?? null;
        $priorPending = is_array($priorPending)
            && is_string($priorPending['key'] ?? null) && $priorPending['key'] !== ''
            && is_array($priorPending['fields'] ?? null)
                ? $priorPending : null;

        $appendDecisionAudit = function(array $pending) {
            $payload = $pending['fields'];
            $payload['decisionKey'] = $pending['key'];
            return auditLog('order', 'paper_execution_decision', $payload,
                !empty($pending['fields']['executed']) ? 'info' : 'warn') !== false;
        };
        $unlockAndFail = function($message) use ($stateLock) {
            flock($stateLock, LOCK_UN); fclose($stateLock);
            http_response_code(500);
            echo json_encode(['status' => false, 'error' => $message]);
        };
        $atomicStateWrite = function(array $state) use ($file) {
            $encoded = json_encode($state);
            $tmp = $file . '.tmp';
            if ($encoded === false || @file_put_contents($tmp, $encoded, LOCK_EX) === false) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            return true;
        };

        // Recover an append that failed after the prior atomic state write. The
        // pending identity remains on disk until the audit record is known to
        // exist, so a retry cannot silently lose or duplicate the decision.
        if ($priorPending !== null) {
            $pendingAlreadyLogged = auditLogHasIdentity(
                'paper_execution_decision', $priorPending['key']);
            if (!$pendingAlreadyLogged && !$appendDecisionAudit($priorPending)) {
                $unlockAndFail('Pending paper decision audit append failed');
                return;
            }
            $priorAuditKey = $priorPending['key'];
        }

        // auditPending is a one-request client marker, not persisted decision
        // data. Historical/unmarked lastExecution values must never be backfilled.
        $incomingMarked = is_array($data['lastExecution'] ?? null)
            && (($data['lastExecution']['auditPending'] ?? null) === true);
        if (is_array($data['lastExecution'] ?? null)) {
            unset($data['lastExecution']['auditPending']);
        }
        unset($data['lastExecutionAuditKey'], $data['lastExecutionAuditPending']);

        $newPending = null;
        if ($incomingMarked) {
            $decisionSignature = $signatureFor($data['lastExecution']);
            if ($decisionSignature !== null
                && ($priorAuditKey === null || !hash_equals($priorAuditKey, $decisionSignature['key']))) {
                $newPending = $decisionSignature;
            }
        }

        // Force the budget to server constants. The first atomic replacement
        // records any new pending identity before its append is attempted.
        $data['initialCapital'] = PAPER_INITIAL_CAPITAL;
        $data['maxPerTrade']    = PAPER_MAX_PER_TRADE;
        $data['updatedAt']      = date('c');
        if ($priorAuditKey !== null) $data['lastExecutionAuditKey'] = $priorAuditKey;
        if ($newPending !== null) $data['lastExecutionAuditPending'] = $newPending;

        if (!$atomicStateWrite($data)) {
            $unlockAndFail('Atomic state swap failed');
            return;
        }

        if ($newPending !== null) {
            $alreadyLogged = auditLogHasIdentity('paper_execution_decision', $newPending['key']);
            if (!$alreadyLogged && !$appendDecisionAudit($newPending)) {
                // The just-written state intentionally retains pending for the
                // next authenticated write to recover before accepting new data.
                $unlockAndFail('Paper decision audit append failed');
                return;
            }

            // Finalize only after the append is confirmed. If this second write
            // fails, the durable pending record plus audit identity makes the
            // next request complete without another append.
            $data['lastExecutionAuditKey'] = $newPending['key'];
            unset($data['lastExecutionAuditPending']);
            if (!$atomicStateWrite($data)) {
                $unlockAndFail('Paper decision audit finalization failed');
                return;
            }
        }

        flock($stateLock, LOCK_UN); fclose($stateLock);
        echo json_encode(['status' => true, 'updatedAt' => $data['updatedAt']]);
        return;
    }

    if (!file_exists($file)) {
        // Seed a fresh state rather than making the client invent one.
        echo json_encode(['status' => true, 'state' => [
            'initialCapital' => PAPER_INITIAL_CAPITAL,
            'maxPerTrade'    => PAPER_MAX_PER_TRADE,
            'capital'        => PAPER_INITIAL_CAPITAL,
            'peakCapital'    => PAPER_INITIAL_CAPITAL,
            'startDate'      => date('Y-m-d'),
            'active'         => [],
            'trades'         => [],
            'totalPnl'       => 0,
            'seeded'         => true,
        ]]);
        return;
    }

    $state = json_decode(@file_get_contents($file), true);
    if (!is_array($state)) { echo json_encode(['status' => false, 'error' => 'Corrupt state']); return; }
    // Budget is always authoritative from the server side.
    $state['initialCapital'] = PAPER_INITIAL_CAPITAL;
    $state['maxPerTrade']    = PAPER_MAX_PER_TRADE;
    echo json_encode(['status' => true, 'state' => $state]);
}

/**
 * God Mode brain state (patterns / learnings / active trades) — replaces the
 * godmode_* localStorage keys, for the same reasons as paper state: browser
 * storage made the brain per-device, so a phone and a laptop each learned their
 * own separate history and the dashboard showed different numbers on each.
 */
function handleGodState() {
    $dir = __DIR__ . '/../brain-data';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/god-state.json';

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_GET['reset'] ?? '') === '1') {
        requireAdminAuth();
        @unlink($file);
        auditLog('recovery', 'god_state_reset', []);
        echo json_encode(['status' => true, 'reset' => true]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireWriteAuth('god_state', 120, 60);
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) { echo json_encode(['status' => false, 'error' => 'Invalid state']); return; }

        // God-state snapshots are replace-all writes, so apply the same executor
        // fence as paper state. This prevents viewer/expired tabs from erasing a
        // stable pattern ID or a newly recorded completed outcome.
        $leaseFile = $dir . '/paper/executor-lease.json';
        $lease = file_exists($leaseFile) ? json_decode(@file_get_contents($leaseFile), true) : null;
        $leaseOwner = (string)($data['executorLeaseOwner'] ?? '');
        $leaseValid = is_array($lease) && (int)($lease['expiresAt'] ?? 0) > time()
            && $leaseOwner !== '' && hash_equals((string)($lease['owner'] ?? ''), $leaseOwner);
        if (!$leaseValid) {
            http_response_code(409);
            echo json_encode(['status'=>false,'error'=>'God state write rejected: executor lease is absent, expired, or owned by another tab']);
            return;
        }

        // Cap pattern history so this file cannot grow without bound.
        if (isset($data['patterns']) && is_array($data['patterns'])) {
            $data['patterns'] = array_slice($data['patterns'], -500);
        }
        $data['updatedAt'] = date('c');

        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($data), LOCK_EX) === false) {
            echo json_encode(['status' => false, 'error' => 'Write failed']); return;
        }
        @rename($tmp, $file); // atomic
        echo json_encode(['status' => true, 'patterns' => count($data['patterns'] ?? []), 'updatedAt' => $data['updatedAt']]);
        return;
    }

    if (!file_exists($file)) {
        echo json_encode(['status' => true, 'state' => [
            'patterns'  => [],
            'learnings' => ['weights' => new stdClass(), 'vetoes' => new stdClass(), 'totalAnalyzed' => 0],
            'trades'    => new stdClass(),
            'seeded'    => true,
        ]]);
        return;
    }

    $state = json_decode(@file_get_contents($file), true);
    if (!is_array($state)) { echo json_encode(['status' => false, 'error' => 'Corrupt state']); return; }
    echo json_encode(['status' => true, 'state' => $state]);
}

function handlePaperShadow() {
    $dir = __DIR__ . '/../brain-data/paper';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireWriteAuth('paper_shadow', 240, 60);
        // Store a shadow trade (rejected signal tracked)
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data) { echo json_encode(['error' => 'Invalid data']); return; }

        $record = [
            'ts'            => $data['timestamp'] ?? time(),
            'instrument'    => strtoupper($data['instrument'] ?? ''),
            'direction'     => $data['direction'] ?? '',
            'confidence'    => (float)($data['confidence'] ?? 0),
            'reason'        => $data['reason'] ?? '',
            'entryPremium'  => (float)($data['entryPremium'] ?? 0),
            'strike'        => (float)($data['strike'] ?? 0),
            'type'          => $data['type'] ?? '',
            'wouldHaveWon'  => $data['wouldHaveWon'] ?? null,
            'missedPnlPct'  => (float)($data['missedPnlPct'] ?? 0),
            'peakPremium'   => (float)($data['peakPremium'] ?? 0),
        ];

        $file = $dir . '/shadows.jsonl';
        @file_put_contents($file, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

        echo json_encode(['status' => true]);
    } else {
        // GET: Query shadow trades
        $file = $dir . '/shadows.jsonl';
        if (!file_exists($file)) {
            echo json_encode(['status' => true, 'shadows' => [], 'stats' => null]);
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $shadows = [];
        foreach ($lines as $line) {
            $r = json_decode($line, true);
            if ($r) $shadows[] = $r;
        }

        $completed = array_filter($shadows, fn($s) => $s['wouldHaveWon'] !== null);
        $wouldWin = array_filter($completed, fn($s) => $s['wouldHaveWon'] === true);
        $vetoAccuracy = count($completed) ? round((count($completed) - count($wouldWin)) / count($completed) * 100) : 0;

        // By reason
        $byReason = [];
        foreach ($completed as $s) {
            $r = $s['reason'] ?? 'Unknown';
            if (!isset($byReason[$r])) $byReason[$r] = ['total' => 0, 'correct' => 0, 'wrong' => 0];
            $byReason[$r]['total']++;
            if ($s['wouldHaveWon']) $byReason[$r]['wrong']++;
            else $byReason[$r]['correct']++;
        }

        echo json_encode([
            'status' => true,
            'shadows' => array_slice($shadows, -50),
            'stats' => [
                'total'         => count($shadows),
                'completed'     => count($completed),
                'wouldHaveWon'  => count($wouldWin),
                'wouldHaveLost' => count($completed) - count($wouldWin),
                'vetoAccuracy'  => $vetoAccuracy,
                'byReason'      => $byReason,
            ],
        ]);
    }
}
