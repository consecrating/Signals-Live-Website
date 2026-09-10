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

## 1. Add a cron token

Add one line to `api/config.secret.php` (never committed — `.gitignore` blocks it):

```php
'cron_token' => 'PICK_A_LONG_RANDOM_STRING',
```

If you skip this, the worker falls back to the existing `admin_token`, so it still
works — but a dedicated token is better, since it can be rotated without touching
anything else.

## 2. Schedule it

### Option A — cPanel cron (preferred)

cPanel → **Cron Jobs**. Every minute during market hours, Mon–Fri:

```
*/1 4-11 * * 1-5   /usr/local/bin/php /home/USER/public_html/signals/api/cron.php >/dev/null 2>&1
```

Notes:
- `4-11` is **UTC**, covering 09:15–15:30 IST (03:45–10:00 UTC) with margin. If your
  server's cron runs in IST, use `9-16` instead.
- Confirm the PHP binary path (`which php`, or cPanel usually shows it).
- Replace `USER` and verify the docroot path.
- CLI runs need no token.

A second entry gives you a guaranteed end-of-day sweep even if the market-hours
schedule is disrupted:

```
25,35 10 * * 1-5   /usr/local/bin/php /home/USER/public_html/signals/api/cron.php >/dev/null 2>&1
```

### Option B — external pinger (no shell access needed)

Use any free scheduler ([cron-job.org](https://cron-job.org), UptimeRobot, etc.) to
fetch, every minute:

```
https://ads.sanctify.co.in/signals/api/cron.php?token=YOUR_CRON_TOKEN
```

Slightly less reliable than real cron, and the token travels in the URL — so prefer
Option A where possible.

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

| Symptom | Cause |
|---|---|
| `403 cron token required` | Token missing/incorrect, or `config.secret.php` lacks `cron_token` **and** `admin_token` |
| `another cron tick is still running` | Normal if a previous tick is slow; auto-clears, stale lock broken after 5 min |
| `no candles` for a symbol | Broker API hiccup or rate limit — it retries next tick |
| Signals all `NO_TRADE` | Usually correct. The engine requires ADX ≥ 25 and a reversion score between ±30 and ±50; in chop it stays out |
| Alert not received | Shared-host `mail()` can be throttled or spam-filtered. Check the cooldown file and your spam folder |
