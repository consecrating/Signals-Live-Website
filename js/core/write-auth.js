/**
 * Same-origin write authorisation.
 *
 * Every state-mutating endpoint on this site was previously open to the internet:
 * an unauthenticated POST of {"__probe":1} to ?action=paper_state replaced the
 * entire paper-trading state, and ?action=god_state&reset=1 wiped the brain over a
 * plain GET. proxy.php now requires a rotating token that is only issued to a
 * browser whose Origin/Referer belongs to this site.
 *
 * Scope, stated plainly: this is defence-in-depth, not user authentication. A
 * static frontend cannot keep a secret from its own user. It stops unauthenticated
 * scanners, cross-site drive-by writes and casual tampering. Destructive resets
 * need an admin token that is never sent to the browser at all.
 */

const TOKEN_URL = '/signals/api/proxy.php?action=write_token';

let _token = null;
let _fetchedAt = 0;
let _inflight = null;

async function getToken(force = false) {
  const age = Date.now() - _fetchedAt;
  // Server rotates on a 30-min window and accepts the previous one, so refresh
  // every 10 min — well inside the overlap.
  if (!force && _token && age < 600000) return _token;
  if (_inflight) return _inflight;
  _inflight = (async () => {
    try {
      const r = await fetch(TOKEN_URL, { cache: 'no-store', credentials: 'same-origin' });
      const j = await r.json();
      if (j && j.status && j.token) { _token = j.token; _fetchedAt = Date.now(); }
    } catch (e) {
      console.warn('[writeAuth] Could not obtain write token; writes will be rejected.', e);
    } finally { _inflight = null; }
    return _token;
  })();
  return _inflight;
}

/**
 * fetch() wrapper that attaches the write token. Retries exactly once on a 403 in
 * case the token rotated mid-flight — deliberately once, not in a loop, because
 * uncontrolled retries are themselves one of the failure modes being guarded.
 */
export async function authedFetch(url, options = {}) {
  const tok = await getToken();
  const opts = {
    ...options,
    credentials: 'same-origin',
    headers: { ...(options.headers || {}), 'X-Write-Token': tok || '' },
  };
  let res = await fetch(url, opts);
  if (res.status === 403) {
    const fresh = await getToken(true);
    if (fresh && fresh !== tok) {
      res = await fetch(url, { ...opts, headers: { ...opts.headers, 'X-Write-Token': fresh } });
    }
  }
  return res;
}

/** Convenience for JSON POSTs. */
export async function postJSON(url, body) {
  return authedFetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

/** Warm the token early so the first write is not delayed. */
export function primeWriteAuth() { return getToken(); }
