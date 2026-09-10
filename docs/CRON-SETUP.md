# Server-Side Worker — Setup

## The problem this fixes

The whole system used to run in the browser. `js/live-engine.js` computed signals,
`js/paper-trading.js` and `js/virtual-trading.js` monitored positions, and execution was
fenced behind a lease held by an open tab.

**Close the laptop and everything stopped**: no signals, no alerts, no stop or target
checks, and no 15:20 square-off. That last one is why positions were once held for 700
and 2,100 minutes — nothing was watching them.

`api/cron.php` does that work on the server. Once cron is pointed at it, the desk keeps
running with every device switched off.

## What the worker does

| Runs | When |
|---|---|
| Square off / monitor open positions (stop, target, trail, schedule, **15:20 IST**, and stale positions left from an earlier session) | **Always**, even outside market hours |
| Generate signals for NIFTY / BANKNIFTY / FINNIFTY / MIDCPNIFTY | Market hours only (09:15–15:30 IST, Mon–Fri) |
| Email a confirmed signal (30-min cooldown per symbol+direction) | Market hours only |
| Write `brain-data/server-signals.json` for the dashboards | Market hours only |

Exits run unconditionally on purpose: an open position with no stop monitoring is more
dangerous than no automation at all.

### What it deliberately does NOT do

**It does not open new positions.** That needs God Mode's adaptive threshold and the
full live-quote execution gate stack, and enabling it silently would mean trades
appearing with nobody watching. It is a conscious risk decision, not a side effect of
fixing the laptop problem — ask before switching it on.

## 1. The cron token

**Already done** — a 48-character `cron_token` was generated and added to
`api/config.secret.php` on the server (untracked, and still 403 over HTTP). Read it with:

```bash
grep cron_token api/config.secret.php     # on the server, via FTP or File Manager
```

Rotate it freely; nothing else uses it. CLI/cPanel cron does not need it at all — only
HTTP callers do. If it were ever removed, the worker falls back to `admin_token`.

## 2. Schedule it

### Option A — cPanel cron (preferred: 1-minute precision, most reliable)

cPanel → **Cron Jobs** → Common Settings: *Once Per Minute (\* \* \* \* \*)*, then paste
this as the command. **One line, nothing else needed:**

```
/opt/cpanel/ea-php82/root/usr/bin/php /home4/sanctqeo/public_html/sanctify.co.in/ads/signals/api/cron.php >/dev/null 2>&1
```

Full line if you are entering the schedule manually:

```
* * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home4/sanctqeo/public_html/sanctify.co.in/ads/signals/api/cron.php >/dev/null 2>&1
```

**Why every minute with no hour range?** Because an hour range is the single most likely
thing to be silently wrong. This host reports `phpTimezone: UTC` but
`systemTimezone: Asia/Kolkata`, **and cron obeys the system one** — so a UTC-shaped
range like `3-10` would actually run 03:00–10:59 IST: before the open, finishing by
11:00, missing most of the session *and the 15:20 square-off*, while looking entirely
plausible in the cPanel UI.

`cron.php` already resolves IST correctly on its own and self-gates, so handing it every
minute and letting it decide removes that whole class of error. Outside market hours with
no open position it returns in well under a second and makes **no broker API calls**.

Two details that make this cheap enough to run 1,440×/day:
- Uneventful ticks write **no** audit rows (only exits and alerts are audited), so the
  trail stays readable instead of being buried in heartbeats.
- Liveness goes to `brain-data/cron-heartbeat.json`, rewritten each tick.

Verified paths for this host (do **not** substitute a guessed docroot — a wrong path
fails silently, with no error visible anywhere):

| | Value |
|---|---|
| Script | `/home4/sanctqeo/public_html/sanctify.co.in/ads/signals/api/cron.php` |
| PHP binary | `/opt/cpanel/ea-php82/root/usr/bin/php` (PHP 8.2.33) |
| Cron timezone | `Asia/Kolkata` |

CLI runs need **no token**.

**If your host forbids an every-minute job**, use these instead — already converted into
`Asia/Kolkata`, the timezone cron actually uses here:

```
*/1 9-15 * * 1-5    /opt/cpanel/ea-php82/root/usr/bin/php /home4/sanctqeo/public_html/sanctify.co.in/ads/signals/api/cron.php >/dev/null 2>&1
15,20,25 15 * * 1-5 /opt/cpanel/ea-php82/root/usr/bin/php /home4/sanctqeo/public_html/sanctify.co.in/ads/signals/api/cron.php >/dev/null 2>&1
```

**Re-derive all of the above** any time the host changes PHP version, moves the docroot,
or changes timezone:

```bash
curl -s "https://ads.sanctify.co.in/signals/api/cron.php?token=YOUR_TOKEN&setup=1"
```

It reports the live `scriptPath`, `phpBinary` (probing which candidates exist),
`phpTimezone` vs `systemTimezone`, the market window converted into the cron timezone,
and ready-to-paste lines built from those real values.

### Option B — GitHub Actions (no cPanel access needed)

`.github/workflows/trading-worker.yml` is committed and ready. It runs on GitHub's
infrastructure, so it fires with all of your devices switched off. **Two steps to
activate:**

1. **Settings → Secrets and variables → Actions → New repository secret**
   - Name: `SIGNALS_CRON_TOKEN`
   - Value: the `cron_token` from `api/config.secret.php` on the server
2. **Merge the workflow into the default branch** — GitHub only runs schedules from the
   default branch, so it does nothing while it sits on a feature branch.

Then check **Actions → Trading worker**, or trigger it manually via **Run workflow**.

Trade-offs against Option A, stated plainly:

| | cPanel cron | GitHub Actions |
|---|---|---|
| Interval | 1 minute | 5 minutes minimum (the job internally ticks ~5× a minute apart to compensate) |
| Punctuality | Reliable | **Not guaranteed** — GitHub delays or drops scheduled runs under load, sometimes 15+ min |
| Token in flight | Never leaves the host | Sent as a header over TLS |
| Setup | Paste 3 lines | Add a secret + merge |

Because a delayed run could miss 15:20, the workflow over-covers the close, and
`cron.php` squares off stale positions on **any** later run — so a late tick closes a
position late, but can never leave one riding indefinitely.

### Option C — external pinger

Any free scheduler ([cron-job.org](https://cron-job.org), UptimeRobot, etc.) fetching:

```
https://ads.sanctify.co.in/signals/api/cron.php?token=YOUR_CRON_TOKEN
```

Prefer the header form where the service supports it (`X-Cron-Token: YOUR_CRON_TOKEN`),
so the token stays out of URLs and logs.

## 3. Verify

```bash
curl -s "https://ads.sanctify.co.in/signals/api/cron.php?token=YOUR_TOKEN" | head -40
```

Expect `"status": true`, an `ist` timestamp, `marketOpen`, and a `signals` block. During
market hours `brain-data/server-signals.json` should refresh within a minute or two.

### Confirm alerts can actually send

Shared hosting often disables or throttles `mail()`, and the failure is **silent** — you
would only find out by never receiving an alert, which looks identical to "no signal
fired". So test it explicitly:

```bash
curl -s "https://ads.sanctify.co.in/signals/api/cron.php?token=YOUR_TOKEN&selftest=alert"
```

`"mailAccepted": true` means the host took the message — check the inbox *and* spam. If
it returns `false`, alerts will not arrive and you need an SMTP relay or to enable mail
in the hosting panel. The self-test bypasses the cooldown but does **not** write the
dedup file, so it cannot suppress a real alert afterwards.

### Confirm it from the dashboard

The **God Mode** page shows worker liveness in the Safety & Control panel:

> ● Server worker: last tick 2m ago · 4 instruments scanned, 0 actionable · market open ·
> *signals run with every device off*

If it reads **"never run"** or **"stale"**, cron is not firing.

## Safety properties

- **Single-writer lock** (`brain-data/cron.lock`) — overlapping ticks cannot interleave
  state writes. A stale lock is broken automatically after 5 minutes.
- **Idempotent** — safe to call every minute; it does nothing when there is nothing to do.
- **Same engine as the browser.** `api/engine.php` is a port of `js/live-engine.js`, and
  `tools/engine-parity.php` proves it: 45 comparisons, 0 mismatches at 1e-9. Run it after
  changing either engine —
  ```bash
  php tools/engine-parity.php
  ```
  If it fails, do not deploy: cron would trade different logic than the page displays.
- **Writes state files directly**, under `flock`, because the HTTP write endpoints
  deliberately reject non-browser callers. Reads go through `proxy.php` so the worker
  reuses the existing broker auth, caching and retries.
- **`rev` is bumped** on virtual-state writes, so a stale browser tab re-hydrates instead
  of overwriting what the server just did.
- Closed trades are tagged `closedBy: "server-cron"`, so server exits are
  distinguishable from browser ones in the trade log.

## Alerts

Sent to the address configured in `cron.php` (`consecrating@gmail.com`), from
`signals@ads.sanctify.co.in`. The cooldown file is `brain-data/cron-alerts.json` and is
kept separate from the browser's dedup, so the two paths cannot suppress each other.

Every alert carries the honest caveat that the strategy is **not yet statistically
proven** — the last validation put the pooled deflated Sharpe at 0.55 against the 0.95
needed, on 40 option trades. See `SignalsBrain/docs/VALIDATION.md`.

## Troubleshooting

First check: is cron firing at all? `brain-data/cron-heartbeat.json` is rewritten on every
tick, so its `lastTickAt` tells you immediately whether the problem is the schedule or
the worker.

| Symptom | Cause |
|---|---|
| Heartbeat missing or minutes old | Cron is not firing. Wrong PHP binary or script path is the usual reason — re-check with `?setup=1` |
| Cron fires but at odd hours | An hour range written for the wrong timezone. Cron here runs in **Asia/Kolkata**, not UTC. Use `* * * * *` and let the script gate itself |
| `403 cron token required` | Token missing/incorrect, or `config.secret.php` lacks `cron_token` **and** `admin_token` |
| `another cron tick is still running` | Normal if a previous tick is slow; auto-clears, stale lock broken after 5 min |
| `no candles` for a symbol | Broker API hiccup or rate limit — it retries next tick |
| Signals all `NO_TRADE` | Usually correct. The engine requires ADX ≥ 25 and a reversion score between ±30 and ±50; in chop it stays out |
| Alert not received | Shared-host `mail()` can be throttled or spam-filtered. Check the cooldown file and your spam folder |
