/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * VIRTUAL TRADING DESK — manual, human-operated (₹1,00,000)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * A SEPARATE ledger from the automated paper trader (js/paper-trading.js). This desk
 * is INTRADAY — every open position is squared off at 15:20 IST and nothing is ever
 * carried overnight (see eodSquareOffDue + monitor). Here YOU decide every entry and
 * discretionary exit:
 *   • Manually BUY the current signal's exact live option contract.
 *   • Manually SELL — full or partial (25/50/100%).
 *   • Attach a bracket at entry: hard stop-loss + target (auto-exit when hit).
 *   • Trailing stop (lock profit after it runs).
 *   • Schedule a SELL — at a clock time (IST) or when premium crosses a price.
 *   • Full record keeping (trade log, equity curve, per-instrument analytics).
 *   • Feeds real completed outcomes back to the God Mode brain for training.
 *
 * State lives ONLY on the server (?action=virtual_state). No localStorage — the
 * ledger is identical on every device. Writes use an optimistic `rev` counter so
 * a background monitor tab and a manual click cannot silently clobber each other.
 *
 * PAPER/SIMULATION ONLY. No real orders are ever placed. Prices are the same live
 * option quotes the rest of the site uses; fills execute at the live premium (no
 * synthetic slippage) so P&L reflects the real option move.
 */

import { postJSON } from './core/write-auth.js?v=1.0';
import { godBrain } from './god-mode.js?v=2.5';
import { summarize as riskSummarize, returnsFromTrades } from './core/risk-metrics.js?v=1.0';

export const VT_VERSION = '1.0.0';
const PROXY = '/signals/api/proxy.php';
const VS_URL = PROXY + '?action=virtual_state';
export const VIRTUAL_CAPITAL = 100000; // ₹1,00,000 — server is authoritative

const _uuid = () => (globalThis.crypto?.randomUUID?.() || (Date.now() + '-' + Math.random().toString(36).slice(2)));
const _round2 = (x) => Math.round((Number(x) || 0) * 100) / 100;
const _nowIso = () => new Date().toISOString();

/**
 * Execution friction model (mirrors the paper desk).
 *
 * Fills now execute at the EXACT live premium — no synthetic entry/exit friction.
 * The previous model added ~2.3% on entry (spread + impact + delay), which on
 * index-option premiums of ₹300-400 pushed the fill 7-9 points above the live
 * quote and marked every fresh position at an instant loss. Product requirement:
 * a manual buy fills at the premium the user actually sees. Entry == live premium,
 * exit == live premium, so a flat market nets ₹0 rather than a fabricated loss.
 * Kept as a function (returning zeros) so all downstream slippage fields/stats
 * keep working and a realistic model can be reintroduced in one place if desired.
 */
function slippage(premium) {
  return { entry: 0, exit: 0 };
}

/** IST minutes-of-day helper for market-hours + time schedules. */
function istNow() {
  const i = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  return { date: i, min: i.getHours() * 60 + i.getMinutes(), day: i.getDay() };
}

/**
 * ── INTRADAY SQUARE-OFF ────────────────────────────────────────────────────────
 * This desk is INTRADAY: nothing is carried overnight. Every open position is sold
 * at the end of the session, matching the paper desk's 15:20 IST forced close.
 *
 * 15:20 (not 15:30) deliberately — the last ten minutes are the thinnest part of
 * the option book, so squaring off there models a fill you could actually get.
 */
const EOD_SQUAREOFF_MIN = 15 * 60 + 20; // 15:20 IST
const MARKET_CLOSE_MIN  = 15 * 60 + 30; // 15:30 IST

/** IST calendar day of a timestamp, as YYYY-MM-DD, for same-session comparisons. */
function istDayKey(dateLike) {
  const d = dateLike ? new Date(dateLike) : new Date();
  if (isNaN(d.getTime())) return null;
  const i = new Date(d.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  return i.getFullYear() + '-' + String(i.getMonth() + 1).padStart(2, '0') + '-' + String(i.getDate()).padStart(2, '0');
}

/**
 * Should this position be squared off now purely on the clock?
 * Returns an outcome label, or null to leave it open.
 *
 * Two distinct cases, because evaluation only runs while a browser tab is open:
 *  1. SAME SESSION, past 15:20 — the normal intraday close.
 *  2. AN EARLIER SESSION — the tab was shut before 15:20 (or over a weekend), so
 *     the position was never squared off and is now stale. It must not keep running
 *     as if it were live; close it on the last price we have.
 */
function eodSquareOffDue(pos) {
  const now = istNow();
  const todayKey = istDayKey();
  const openKey = istDayKey(pos && pos.openDate);
  if (openKey && openKey !== todayKey) return 'EOD_SQUAREOFF_STALE';
  if (now.min >= EOD_SQUAREOFF_MIN) return 'EOD_SQUAREOFF';
  return null;
}
export function isMarketOpen() {
  const { min, day } = istNow();
  return day >= 1 && day <= 5 && min >= 555 && min <= 930;
}

export class VirtualTradingEngine {
  constructor() {
    this.version = VT_VERSION;
    this.state = this._defaultState();
    this._hydrated = false;
    this._readyPromise = null;
  }

  _defaultState() {
    const today = new Date().toISOString().split('T')[0];
    return {
      startDate: today,
      initialCapital: VIRTUAL_CAPITAL,
      capital: VIRTUAL_CAPITAL,
      cash: VIRTUAL_CAPITAL,
      peakCapital: VIRTUAL_CAPITAL,
      totalPnl: 0,
      totalSlippage: 0,
      active: [],
      trades: [],
      journal: [],
      equityCurve: [{ date: today, equity: VIRTUAL_CAPITAL }],
      brainTraining: true,
      rev: 0,
    };
  }

  // ── Server-owned state ─────────────────────────────────────────────────────
  async hydrate() {
    try {
      const r = await fetch(VS_URL + '&cb=' + Date.now(), { cache: 'no-store' });
      const j = await r.json();
      if (j && j.status && j.state) {
        // ── PRESERVE LIVE MARKS ACROSS A RE-HYDRATE ──────────────────────────
        // monitor() requotes open positions but deliberately does NOT persist a
        // marking-only tick (that churn made every manual click collide with a 409).
        // So the SERVER's copy of an open position still carries the premium it had at
        // entry. A wholesale `Object.assign` therefore overwrote the freshly requoted
        // price with the stale one — and because the page re-hydrates every 10s while
        // monitoring every 15s, the displayed "Live" price visibly alternated between
        // the real premium and the entry premium, flipping P&L with it.
        //
        // Fix: keep the locally-computed mark for any position that still exists, when
        // our mark is newer than the server's. Everything else (cash, trades, journal,
        // equity curve, rev) still comes from the server, which remains authoritative
        // for the ledger itself.
        const localMarks = new Map();
        for (const p of (this.state.active || [])) {
          if (p && p.id && Number(p.lastQuoteAt) > 0) {
            localMarks.set(p.id, {
              currentPremium: p.currentPremium, peakPremium: p.peakPremium,
              pnl: p.pnl, pnlPct: p.pnlPct, lastQuoteAt: Number(p.lastQuoteAt),
            });
          }
        }

        this.state = Object.assign(this._defaultState(), j.state);
        if (!Array.isArray(this.state.active)) this.state.active = [];
        if (!Array.isArray(this.state.trades)) this.state.trades = [];
        if (!Array.isArray(this.state.journal)) this.state.journal = [];
        if (!Array.isArray(this.state.equityCurve)) this.state.equityCurve = [];

        for (const p of this.state.active) {
          const mk = localMarks.get(p.id);
          if (!mk) continue;
          // Only if our quote is genuinely fresher than whatever the server stored.
          if (mk.lastQuoteAt > Number(p.lastQuoteAt || 0)) {
            p.currentPremium = mk.currentPremium;
            p.peakPremium = Math.max(Number(mk.peakPremium) || 0, Number(p.peakPremium) || 0);
            p.pnl = mk.pnl;
            p.pnlPct = mk.pnlPct;
            p.lastQuoteAt = mk.lastQuoteAt;
          }
        }
        // Re-derive capital from the marks actually on screen so the header total and
        // the position rows cannot disagree.
        this.state.capital = _round2((Number(this.state.cash) || 0) + this.state.active.reduce(
          (a, p) => a + (Number(p.currentPremium) || p.effectiveEntry) * p.qty, 0));
      }
      this._hydrated = true;
    } catch (e) {
      console.warn('[Virtual] Server state unavailable; using defaults this session.', e);
    }
    return this.state;
  }
  ready() {
    if (this._hydrated) return Promise.resolve(this.state);
    if (!this._readyPromise) this._readyPromise = this.hydrate();
    return this._readyPromise;
  }
  async refresh() { this._hydrated = false; this._readyPromise = null; return this.ready(); }

  /**
   * Persist. Sends the loaded `rev` as baseRev for optimistic concurrency. On a
   * 409 (someone else wrote first) it re-hydrates and throws so the caller can
   * retry against fresh state instead of clobbering it.
   */
  async _persist(action) {
    const body = { ...this.state, baseRev: this.state.rev };
    if (action) { body.lastAction = action; body.lastActionId = _uuid(); }
    const res = await postJSON(VS_URL, body);
    const j = res && typeof res.json === 'function' ? await res.json() : res;
    if (!j || j.status === false) {
      if (res && res.status === 409) { await this.hydrate(); throw new Error('Virtual ledger changed elsewhere — reloaded; please retry.'); }
      throw new Error((j && j.error) || 'Virtual state save failed');
    }
    this.state.rev = j.rev;
    this.state.updatedAt = j.updatedAt;
    return j;
  }

  // ── Live requote of an exact contract (same endpoint the paper desk uses) ────
  async requote(pos) {
    if (!pos || !pos.expiry || pos.strike == null || !pos.type) return null;
    try {
      const qs = new URLSearchParams({
        action: 'option_ltp', symbol: pos.instrument, expiry: pos.expiry,
        strike: String(pos.strike), type: pos.type, cb: String(Date.now()),
      });
      const r = await fetch(PROXY + '?' + qs.toString());
      const j = await r.json();
      if (!j || !j.status || !(Number(j.data?.ltp) > 0)) return null;
      const quoteAt = Date.parse(j.timestamp || '') || 0;
      return { ltp: Number(j.data.ltp), quoteAt };
    } catch (e) { return null; }
  }

  // ── MANUAL BUY ───────────────────────────────────────────────────────────
  /**
   * Open a manual virtual position from the current signal's live contract.
   * opts: { signal, lots, stopPremium?, targetPremium?, trailPct?, schedule?, note?, tags? }
   */
  async manualBuy(opts = {}, _retried = false) {
    await this.ready();
    const signal = opts.signal || {};
    const trade = signal.optionTrade || signal.watchTrade || {};
    const lots = Math.max(1, Math.floor(Number(opts.lots) || 1));
    const lotSize = Number(trade.lotSize) || 1;
    if (!trade.strike || !trade.type || !trade.expiry) {
      throw new Error('This signal has no exact listed contract (strike/type/expiry) to trade yet.');
    }
    const entryRaw = Number(trade.livePremium) || Number(trade.entryPremium) || 0;
    if (!(entryRaw > 0)) throw new Error('No positive entry premium available for this contract.');
    const live = trade.premiumSource === 'live';

    // Intraday desk: refuse an entry that the 15:20 square-off would close within
    // minutes. Opening here only pays the spread for no time in the trade.
    const _tNow = istNow();
    if (_tNow.day >= 1 && _tNow.day <= 5 && _tNow.min >= EOD_SQUAREOFF_MIN - 5 && _tNow.min < MARKET_CLOSE_MIN + 1) {
      throw new Error('Too late in the session — this desk squares off all positions at 15:20 IST, so a new intraday entry is blocked after 15:15.');
    }

    const slip = slippage(entryRaw);
    const effectiveEntry = _round2(entryRaw + slip.entry);
    const qty = lots * lotSize;
    const cost = _round2(effectiveEntry * qty);
    if (cost > (this.state.cash || 0) + 0.001) {
      throw new Error(`Cost ₹${Math.round(cost).toLocaleString('en-IN')} exceeds available virtual cash ₹${Math.round(this.state.cash).toLocaleString('en-IN')}.`);
    }

    const stopPremium = Number(opts.stopPremium) > 0 ? _round2(opts.stopPremium) : null;
    const targetPremium = Number(opts.targetPremium) > 0 ? _round2(opts.targetPremium) : null;
    const trailPct = Number(opts.trailPct) > 0 ? Number(opts.trailPct) : null;
    const schedule = this._normalizeSchedule(opts.schedule);

    const pos = {
      id: _uuid(),
      openDate: _nowIso(),
      instrument: signal.symbol || trade.symbol || '',
      direction: signal.direction || (trade.type === 'CE' ? 'BUY' : 'SELL'),
      strike: Number(trade.strike),
      type: String(trade.type).toUpperCase(),
      expiry: trade.expiry,
      expiryDisplay: trade.expiryDisplay || trade.expiry,
      lots, lotSize, qty,
      entryPremium: _round2(entryRaw),
      effectiveEntry,
      entrySlippage: slip.entry,
      entrySource: live ? 'live' : (trade.premiumSource || 'estimated'),
      cost,
      currentPremium: _round2(entryRaw),
      peakPremium: _round2(entryRaw),
      pnl: 0, pnlPct: 0,
      stopPremium, targetPremium, trailPct,
      schedule,
      patternId: signal._patternId ?? null,
      confidence: signal._godConfidence ?? signal.confidence ?? null,
      regime: signal.strategy?.regime || '',
      gex: signal.strategy?.gex?.regime || signal.snapshot?.gex?.regime || '',
      note: (opts.note || '').slice(0, 500),
      tags: Array.isArray(opts.tags) ? opts.tags.slice(0, 10) : [],
      quoteTimestamp: Number(trade.quoteTimestamp) || Date.now(),
      lastQuoteAt: Number(trade.quoteTimestamp) || Date.now(),
      status: 'OPEN',
    };

    this.state.active.push(pos);
    this.state.cash = _round2(this.state.cash - cost);
    if (pos.note) this.state.journal.push({ ts: _nowIso(), id: pos.id, kind: 'OPEN', text: pos.note });

    try {
      await this._persist({
        action: 'BUY', instrument: pos.instrument, direction: pos.direction,
        strike: pos.strike, type: pos.type, lots: pos.lots, premium: pos.effectiveEntry,
        reason: live ? 'Manual buy (live quote)' : 'Manual buy (non-live premium)',
      });
    } catch (e) {
      // _persist already re-hydrated fresh state on a 409; recompute once against
      // it so a concurrent auto-exit elsewhere does not make the user retry by hand.
      if (!_retried && /changed elsewhere/i.test(e.message || '')) return this.manualBuy(opts, true);
      throw e;
    }
    return pos;
  }

  _normalizeSchedule(sch) {
    if (!sch || !sch.kind) return null;
    const kind = String(sch.kind);
    if (kind === 'time') {
      const at = Date.parse(sch.at || '');
      if (!at) return null;
      return { kind, at: new Date(at).toISOString() };
    }
    if (kind === 'price_at_or_above' || kind === 'price_at_or_below') {
      const price = Number(sch.price);
      if (!(price > 0)) return null;
      return { kind, price: _round2(price) };
    }
    return null;
  }

  async setSchedule(id, schedule) {
    await this.ready();
    const pos = this.state.active.find(p => p.id === id);
    if (!pos) throw new Error('Position not found (it may already be closed).');
    pos.schedule = this._normalizeSchedule(schedule);
    await this._persist({ action: 'SCHEDULE', instrument: pos.instrument, strike: pos.strike, type: pos.type,
      reason: pos.schedule ? ('Scheduled ' + pos.schedule.kind) : 'Schedule cleared' });
    return pos.schedule;
  }
  async cancelSchedule(id) { return this.setSchedule(id, null); }

  // ── SELL (internal mutate; caller persists) ────────────────────────────────
  _sellInternal(pos, exitPremiumRaw, pct, outcome) {
    const s = this.state;
    const closeLots = pct >= 100 ? pos.lots : Math.max(1, Math.round(pos.lots * pct / 100));
    const closeQty = closeLots * pos.lotSize;
    const slip = slippage(exitPremiumRaw);
    const effExit = _round2(exitPremiumRaw - slip.exit);
    const entryCostPortion = _round2(pos.effectiveEntry * closeQty);
    const proceeds = _round2(effExit * closeQty);
    const pnl = _round2(proceeds - entryCostPortion);
    const pnlPct = pos.effectiveEntry > 0 ? Math.round((effExit - pos.effectiveEntry) / pos.effectiveEntry * 100) : 0;

    s.cash = _round2(s.cash + proceeds);
    s.totalPnl = _round2((s.totalPnl || 0) + pnl);
    s.totalSlippage = _round2((s.totalSlippage || 0) + (pos.entrySlippage + slip.exit) * closeQty);

    const holdMinutes = Math.round((Date.now() - new Date(pos.openDate).getTime()) / 60000);
    const fullClose = closeLots >= pos.lots;

    const record = {
      id: pos.id + (fullClose ? '' : ':' + Date.now()),
      openDate: pos.openDate, exitDate: _nowIso(),
      instrument: pos.instrument, direction: pos.direction,
      strike: pos.strike, type: pos.type, expiry: pos.expiry, expiryDisplay: pos.expiryDisplay,
      lots: closeLots, lotSize: pos.lotSize, qty: closeQty,
      entryPremium: pos.entryPremium, effectiveEntry: pos.effectiveEntry,
      exitPremium: effExit,
      cost: entryCostPortion, proceeds,
      pnl, pnlPct, holdMinutes,
      outcome, status: outcome,
      partial: !fullClose,
      confidence: pos.confidence, regime: pos.regime, gex: pos.gex,
      patternId: pos.patternId,
      slippage: _round2(pos.entrySlippage + slip.exit),
    };
    s.trades.push(record);

    if (fullClose) {
      s.active = s.active.filter(p => p.id !== pos.id);
      // Feed the completed outcome back to the God Mode brain (best-effort;
      // persistence of the learning requires the god lease held by the active
      // signal/tool tab). Same call the auto paper trader uses.
      if (s.brainTraining !== false && pos.patternId != null) {
        try { godBrain.recordOutcome(pos.patternId, outcome, pnlPct, null, holdMinutes); }
        catch (e) { /* non-fatal */ }
      }
    } else {
      pos.lots -= closeLots;
      pos.qty = pos.lots * pos.lotSize;
      pos.cost = _round2(pos.cost - entryCostPortion);
    }

    // Capital = cash + open exposure marked at last premium.
    s.capital = _round2(s.cash + s.active.reduce((a, p) => a + (Number(p.currentPremium) || p.effectiveEntry) * p.qty, 0));
    if (s.capital > (s.peakCapital || 0)) s.peakCapital = s.capital;
    const today = new Date().toISOString().split('T')[0];
    const last = s.equityCurve[s.equityCurve.length - 1];
    if (last && last.date === today) last.equity = s.capital; else s.equityCurve.push({ date: today, equity: s.capital });
    return record;
  }

  /** Manual sell. premium optional — if omitted, requotes live first. */
  async manualSell(id, opts = {}, _retried = false) {
    await this.ready();
    const pos = this.state.active.find(p => p.id === id);
    if (!pos) throw new Error('Position not found (it may already be closed).');
    const pct = Math.max(1, Math.min(100, Number(opts.pct) || 100));
    let premium = Number(opts.premium) > 0 ? Number(opts.premium) : null;
    if (premium == null) {
      const q = await this.requote(pos);
      if (q) { premium = q.ltp; pos.currentPremium = q.ltp; pos.lastQuoteAt = q.quoteAt; }
      else premium = Number(pos.currentPremium) || pos.effectiveEntry;
    }
    const outcome = opts.reason || (premium >= pos.effectiveEntry ? 'MANUAL_WIN' : 'MANUAL_LOSS');
    const rec = this._sellInternal(pos, premium, pct, outcome);
    try {
      await this._persist({
        action: 'SELL', instrument: pos.instrument, direction: pos.direction,
        strike: pos.strike, type: pos.type, lots: rec.lots, premium: rec.exitPremium,
        pnl: rec.pnl, reason: outcome + (rec.partial ? ' (partial)' : ''),
      });
    } catch (e) {
      // On a concurrent write the state was re-hydrated; retry once against it.
      // If the position was already closed elsewhere, the retry surfaces a clear
      // "position not found" rather than double-booking the sell.
      if (!_retried && /changed elsewhere/i.test(e.message || '')) return this.manualSell(id, opts, true);
      throw e;
    }
    return rec;
  }

  // ── MONITOR: requote actives, mark, and auto-fire brackets/schedules ────────
  async monitor() {
    await this.ready();
    const actives = [...(this.state.active || [])];
    if (!actives.length) return { marked: 0, closed: 0 };
    let changed = false, closed = 0;
    for (const pos of actives) {
      const q = await this.requote(pos);
      if (q) {
        pos.currentPremium = q.ltp; pos.lastQuoteAt = q.quoteAt;
        if (q.ltp > (pos.peakPremium || 0)) pos.peakPremium = q.ltp;
        pos.pnl = _round2((q.ltp - pos.effectiveEntry) * pos.qty);
        pos.pnlPct = pos.effectiveEntry > 0 ? Math.round((q.ltp - pos.effectiveEntry) / pos.effectiveEntry * 100) : 0;
        changed = true;
      }

      // ── INTRADAY SQUARE-OFF (checked BEFORE the freshness gate) ──────────────
      // This is deliberately evaluated even without a fresh quote. After 15:30 the
      // option feed stops ticking, so requiring freshness here would mean the
      // end-of-day exit could never fill and the position would silently ride
      // overnight — which is what left holds of 700+ and 2,100+ minutes on the
      // paper book. The desk is intraday, so the clock closes the trade and we fill
      // on the best price available: a fresh quote if there is one, otherwise the
      // last premium we marked.
      const due = eodSquareOffDue(pos);
      if (due) {
        const px = (q && Number(q.ltp) > 0) ? q.ltp
                 : (Number(pos.currentPremium) > 0 ? Number(pos.currentPremium) : pos.effectiveEntry);
        this._sellInternal(pos, px, 100, due);
        closed++;
        continue;
      }

      if (!q) continue;
      // Bracket/schedule exits DO require a fresh quote to fill against.
      const fresh = q.quoteAt && (Date.now() - q.quoteAt) <= 120000;
      if (!fresh) continue;
      const trigger = this._evaluateTriggers(pos, q.ltp);
      if (trigger) {
        this._sellInternal(pos, q.ltp, 100, trigger);
        closed++;
      }
    }
    // Re-mark capital from live premiums even when nothing closed.
    const s = this.state;
    s.capital = _round2(s.cash + s.active.reduce((a, p) => a + (Number(p.currentPremium) || p.effectiveEntry) * p.qty, 0));
    if (s.capital > (s.peakCapital || 0)) s.peakCapital = s.capital;
    // Persist ONLY when a position actually closed. Marking-only ticks stay local
    // (every viewer requotes independently), so the monitor no longer advances the
    // server `rev` on each tick — that churn was making manual clicks from a
    // long-open tab hit 409 "reload; retry". `changed` is retained for the return.
    void changed;
    if (closed) {
      try { await this._persist({ action: 'AUTO_EXIT', reason: 'Bracket/schedule/end-of-day fills', count: closed }); }
      catch (e) { /* 409 handled by hydrate; positions re-evaluated next tick */ }
    }
    return { marked: actives.length, closed };
  }

  _evaluateTriggers(pos, ltp) {
    if (pos.stopPremium && ltp <= pos.stopPremium) return 'STOP_LOSS';
    if (pos.targetPremium && ltp >= pos.targetPremium) return 'TARGET_HIT';
    if (pos.trailPct && pos.peakPremium > pos.effectiveEntry) {
      // Fire once the trail is armed, even if price has since fallen back through
      // entry: a trailing stop must still protect on a fast drop when the user set
      // no hard stop. (Previously gated on ltp>entry, which left a gap-down hole.)
      const trail = pos.peakPremium * (1 - pos.trailPct / 100);
      if (ltp <= trail) return 'TRAIL_STOP';
    }
    const sch = pos.schedule;
    if (sch) {
      if (sch.kind === 'time' && Date.now() >= Date.parse(sch.at)) return 'SCHEDULED_TIME';
      if (sch.kind === 'price_at_or_above' && ltp >= sch.price) return 'SCHEDULED_PRICE';
      if (sch.kind === 'price_at_or_below' && ltp <= sch.price) return 'SCHEDULED_PRICE';
    }
    return null;
  }

  // ── ANALYTICS ──────────────────────────────────────────────────────────────
  getStats() {
    const s = this.state;
    const full = s.trades.filter(t => !t.partial);
    const all = s.trades;
    const wins = all.filter(t => t.pnl > 0);
    const losses = all.filter(t => t.pnl <= 0);
    const grossWin = wins.reduce((a, t) => a + t.pnl, 0);
    const grossLoss = Math.abs(losses.reduce((a, t) => a + t.pnl, 0));
    const openExposure = s.active.reduce((a, p) => a + (Number(p.currentPremium) || p.effectiveEntry) * p.qty, 0);
    const capital = _round2(s.cash + openExposure);
    const byInstrument = {};
    for (const t of all) {
      const k = t.instrument || '—';
      (byInstrument[k] = byInstrument[k] || { trades: 0, wins: 0, pnl: 0 });
      byInstrument[k].trades++; if (t.pnl > 0) byInstrument[k].wins++; byInstrument[k].pnl += t.pnl;
    }
    return {
      capital, cash: s.cash, openExposure: _round2(openExposure),
      initialCapital: s.initialCapital || VIRTUAL_CAPITAL,
      totalPnl: _round2(s.totalPnl || 0),
      returnPct: Math.round((capital / (s.initialCapital || VIRTUAL_CAPITAL) - 1) * 1000) / 10,
      openCount: s.active.length,
      closedCount: full.length,
      totalFills: all.length,
      winRate: all.length ? Math.round(wins.length / all.length * 100) : 0,
      avgWin: wins.length ? _round2(grossWin / wins.length) : 0,
      avgLoss: losses.length ? _round2(grossLoss / losses.length) : 0,
      profitFactor: grossLoss > 0 ? Math.round(grossWin / grossLoss * 100) / 100 : (grossWin > 0 ? Infinity : 0),
      expectancy: all.length ? _round2((grossWin - grossLoss) / all.length) : 0,
      maxDrawdownPct: s.peakCapital > 0 ? Math.round((1 - capital / s.peakCapital) * 1000) / 10 : 0,
      totalSlippage: _round2(s.totalSlippage || 0),
      byInstrument,
      // Proper risk statistics, shared with the validation harness so a Sharpe quoted
      // here means the same thing as one quoted in research. `risk.verdict` is the
      // honest reading: it refuses to call anything an edge on a short record.
      risk: this.getRiskMetrics(),
    };
  }

  /**
   * Per-trade risk metrics for the closed book.
   *
   * Annualised on the desk's OWN trade cadence rather than 252, because this is an
   * intraday desk that may take a handful of trades a week — using a daily constant
   * would inflate Sharpe several-fold and is the commonest way these numbers mislead.
   */
  getRiskMetrics() {
    const s = this.state;
    const rets = returnsFromTrades(s.trades || []);
    const start = s.startDate ? new Date(s.startDate).getTime() : Date.now();
    const days = Math.max(1, (Date.now() - start) / 86400000);
    const tradesPerYear = Math.max(1, Math.round((rets.length / days) * 252));
    return riskSummarize(rets, tradesPerYear);
  }

  toCSV() {
    const cols = ['exitDate','instrument','direction','strike','type','expiry','lots','qty','entryPremium','exitPremium','pnl','pnlPct','holdMinutes','outcome','partial','confidence','regime','gex'];
    const esc = (v) => { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
    const rows = [cols.join(',')];
    for (const t of this.state.trades) rows.push(cols.map(c => esc(t[c])).join(','));
    return rows.join('\n');
  }

  async addJournalNote(id, text) {
    await this.ready();
    this.state.journal.push({ ts: _nowIso(), id: id || null, kind: 'NOTE', text: String(text || '').slice(0, 500) });
    await this._persist(null);
  }

  async setBrainTraining(on) {
    await this.ready();
    this.state.brainTraining = !!on;
    await this._persist(null);
  }
}

export const virtualTrader = new VirtualTradingEngine();
