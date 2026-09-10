import { postJSON, authedFetch } from './core/write-auth.js?v=1.0';
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * GOD MODE BRAIN — 51 Capabilities for Indian F&O
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * A self-improving intelligence layer that runs IN THE BROWSER alongside
 * live-engine.js. No external server required. Uses localStorage + server-side
 * JSON files for persistence.
 *
 * Capabilities:
 *   #1-8:   Intelligence & Self-Improvement
 *   #9-17:  Real-Time Monitoring
 *   #18-27: Signal Generation (47-dimension)
 *   #28-36: Risk Management
 *   #37-44: Option Intelligence
 *   #45-48: Alert System
 *   #49-51: Dashboard
 *
 * Architecture:
 *   - GodBrain class: singleton orchestrator
 *   - PatternMemory: localStorage + server sync
 *   - VelocityTracker: rate-of-change for all dimensions
 *   - EvidenceChain: full reasoning trace
 *   - RiskShield: 9 protection mechanisms
 *   - AlertEngine: 3-stage escalation
 */

// ═══════════════════════════════════════════════════════════════════════════════
// CONSTANTS & CONFIG
// ═══════════════════════════════════════════════════════════════════════════════

const GOD_VERSION = '2.5.0'; // Stable outcome IDs + empirical calibration
// ── Server-owned brain state (NO localStorage) ──────────────────────────────
// The godmode_* localStorage keys made the brain per-device: a phone and a laptop
// each accumulated their own separate pattern history and learnings, so the God
// Mode dashboard showed different numbers depending on where you opened it.
// State now lives in brain-data/god-state.json on the server.
const GOD_STATE_URL = '/signals/api/proxy.php?action=god_state';
const _godState = {
  patterns: [],
  learnings: { weights: {}, vetoes: {}, totalAnalyzed: 0 },
  trades: {},
};
let _godHydrated = false;
let _godSaveTimer = null;
let _godReadyPromise = null;
let _godLeaseOwner = null;

async function godHydrate() {
  try {
    const r = await fetch(GOD_STATE_URL + '&cb=' + Date.now(), { cache: 'no-store' });
    const j = await r.json();
    if (j && j.status && j.state) {
      if (Array.isArray(j.state.patterns)) _godState.patterns = j.state.patterns;
      if (j.state.learnings && typeof j.state.learnings === 'object') {
        _godState.learnings = Object.assign({ weights: {}, vetoes: {}, totalAnalyzed: 0 }, j.state.learnings);
      }
      if (j.state.trades && typeof j.state.trades === 'object') _godState.trades = j.state.trades;
      if (j.state.lastSignal) _godState.lastSignal = j.state.lastSignal;
      if (j.state.lastSignalAt) _godState.lastSignalAt = j.state.lastSignalAt;
      _godState.signalLock = j.state.signalLock || null;
    }
  } catch (e) {
    console.warn('[GodBrain] Server state unavailable; running on defaults this session.', e);
  }
  _godHydrated = true;
  return _godState;
}

// ─── daily risk (server-derived) ─────────────────────────────────────────────
// Refreshed on a short TTL rather than once at load, because the breaker has to notice a
// loss taken minutes ago — including one realised by a cron-side square-off that this tab
// never saw. Failures leave the previous value in place; they must not silently reset the
// breaker to "no loss today".
const DAILY_RISK_URL = '/signals/api/proxy.php?action=daily_risk';
const DAILY_RISK_TTL = 60000;
let _dailyRisk = null;
let _dailyRiskAt = 0;
let _dailyRiskInflight = null;

async function godFetchDailyRisk(force = false) {
  if (!force && _dailyRisk && Date.now() - _dailyRiskAt < DAILY_RISK_TTL) return _dailyRisk;
  if (_dailyRiskInflight) return _dailyRiskInflight;
  _dailyRiskInflight = (async () => {
    try {
      const r = await fetch(DAILY_RISK_URL + '&cb=' + Date.now(), { cache: 'no-store' });
      const j = await r.json();
      if (j && j.status && j.daily) { _dailyRisk = j.daily; _dailyRiskAt = Date.now(); }
    } catch (e) { /* keep the previous figure */ }
    _dailyRiskInflight = null;
    return _dailyRisk;
  })();
  return _dailyRiskInflight;
}

/** Debounced — called on every processed signal, so batch the writes. */
function godSaveState() { // eslint-disable-line no-unused-vars
  if (!_godLeaseOwner) return;
  _godState.executorLeaseOwner = _godLeaseOwner;
  clearTimeout(_godSaveTimer);
  _godSaveTimer = setTimeout(() => {
    postJSON(GOD_STATE_URL, _godState).catch(() => {});
  }, 1500);
}
// (was GOD_VERSION_KEY, a localStorage key — no longer used; state is server-side)
const PROXY = '/signals/api/proxy.php';
const MAX_PATTERN_RECORDS = 2000;
const RAPID_SCAN_INTERVAL = 60000; // 60 seconds when setup building
const NORMAL_SCAN_INTERVAL = 30000; // 30 seconds normal

// 47 Dimension weights (category → dimension → weight)
const DIM_WEIGHTS = {
  // Options Microstructure (25% of total — THE edge)
  pcr: 9, pcr_velocity: 10, atm_iv: 6, iv_percentile: 7, iv_skew: 8,
  gex_regime: 10, gex_net: 6, gex_flip_distance: 9, call_wall_dist: 5,
  put_wall_dist: 5, max_pain_dist: 4, oi_buildup: 8,
  // Trend (20%)
  ema_stack: 8, supertrend: 7, adx_value: 7, adx_regime: 8,
  di_diff: 6, htf_trend: 7, trend_accel: 5,
  // Price Structure (15%)
  day_change: 6, day_range_pos: 5, vwap_dev: 7, ema_distance: 6,
  orb_status: 5, sr_proximity: 4, range_20d: 4, ltp: 0,
  // Volume & Flow (12%)
  volume_ratio: 6, volume_trend: 5, vwap_position: 7,
  delivery_pct: 3, fii_flow: 7, dii_flow: 4,
  // Momentum (10%)
  rsi: 6, rsi_divergence: 7, macd_hist: 6, macd_dir: 5, roc_5: 5, stoch_zone: 4,
  // Volatility (8%)
  vix: 6, vix_change: 7, bb_width: 5, atr_pct: 6,
  // Context (10%)
  session_min: 3, dte: 6, day_of_week: 3, session_phase: 4,
};

// Category weights (must sum to 100)
const CATEGORY_WEIGHTS = {
  options: 25, trend: 20, price: 15, flow: 12, momentum: 10, volatility: 8, context: 10,
};

// ═══════════════════════════════════════════════════════════════════════════════
// #1-8: INTELLIGENCE & SELF-IMPROVEMENT
// ═══════════════════════════════════════════════════════════════════════════════

class PatternMemory {
  constructor() {
    this.records = this._load();
    this.learnings = this._loadLearnings();
  }

  // #1: Self-Learning Pattern DB
  record(signal) {
    const now = Date.now();
    // The page reevaluates every 30s. Reuse the still-pending fingerprint for ten
    // minutes instead of treating dozens of identical observations as independent
    // samples. Outcomes must correspond to actual completed paper positions.
    const existing = [...this.records].reverse().find(r => !r.outcome && now - Number(r.ts || 0) < 600000
      && r.instrument === signal.instrument && r.direction === signal.direction
      && r.regime === (signal.regime || '') && r.gex_regime === (signal.gex_regime || '')
      && r.adx_band === (signal.adx_band || 0) && r.pcr_band === (signal.pcr_band || 0));
    if (existing) return existing.id || existing.ts;

    const entry = {
      id: `${now}-${Math.random().toString(36).slice(2, 9)}`,
      ts: now,
      instrument: signal.instrument,
      direction: signal.direction,
      confidence: signal.confidence,
      net_score: signal.netScore || 0,
      regime: signal.regime || '',
      gex_regime: signal.gex_regime || '',
      adx_band: signal.adx_band || 0,
      pcr_band: signal.pcr_band || 0,
      trend_dir: signal.trend_dir || 0,
      momentum_zone: signal.momentum_zone || 0,
      gex_flip_zone: signal.gex_flip_zone || 0,
      session: signal.session_phase || 0,
      dte_band: signal.dte_band || 0,
      day_of_week: new Date().getDay(),
      hour: new Date().getHours(),
      outcome: null, // filled later
      pnl_pct: null,
      move_atr: null,
      duration_min: null,
    };
    this.records.push(entry);
    if (this.records.length > MAX_PATTERN_RECORDS) this.records = this.records.slice(-MAX_PATTERN_RECORDS);
    this._save();
    return entry.id;
  }

  // Record an outcome against a stable ID. Numeric index support keeps older
  // in-flight positions closable after this migration.
  recordOutcome(id, outcome, pnl_pct, move_atr, duration_min) {
    const record = this.records.find(r => String(r.id || r.ts) === String(id))
      || (Number.isInteger(id) ? this.records[id] : null);
    if (!record || record.outcome) return false;
    record.outcome = outcome;
    record.pnl_pct = pnl_pct;
    record.move_atr = move_atr;
    record.duration_min = duration_min;
    this._learnFromOutcome(record);

    // Persist through the MERGE endpoint, not only through the snapshot save.
    //
    // _save() -> godSaveState() returns early unless this tab owns the executor lease, so
    // a desk tab without it would mutate memory here and write nothing — the outcome simply
    // evaporated on reload. That is provable in the live data: the one virtual trade
    // carrying a patternId matched a stored pattern whose outcome was still null. Labelling
    // a pattern only merges one record and cannot clobber another writer, so it does not
    // need the lease and must not be gated on it. _save() is still called for the tab that
    // does hold the lease, which keeps the rest of the snapshot current.
    postJSON(`${PROXY}?action=god_outcome`, {
      patternId: String(id), outcome, pnlPct: pnl_pct,
      moveAtr: move_atr, durationMin: duration_min, closedBy: 'browser',
    }).catch(() => {});

    this._save();
    return true;
  }

  // Query: "Last N times this setup occurred, what happened?"
  findSimilar(fingerprint, direction, limit = 50) {
    return this.records
      .filter(r => r.outcome && r.direction === direction)
      .filter(r => {
        let matches = 0;
        if (r.gex_regime === fingerprint.gex_regime) matches++;
        if (r.trend_dir === fingerprint.trend_dir) matches++;
        if (Math.abs(r.adx_band - fingerprint.adx_band) <= 1) matches++;
        if (Math.abs(r.pcr_band - fingerprint.pcr_band) <= 1) matches++;
        if (r.momentum_zone === fingerprint.momentum_zone) matches++;
        if (Math.abs(r.gex_flip_zone - fingerprint.gex_flip_zone) <= 1) matches++;
        return matches >= 4; // At least 4 of 6 match
      })
      .slice(-limit);
  }

  // #4: Regime Performance Tracker
  getRegimePerformance(regime) {
    const trades = this.records.filter(r => r.outcome && r.regime === regime);
    if (!trades.length) return { count: 0, winRate: 0, avgPnl: 0 };
    const wins = trades.filter(r => r.pnl_pct > 0);
    return {
      count: trades.length,
      winRate: Math.round(wins.length / trades.length * 100),
      avgPnl: Math.round(trades.reduce((s, r) => s + (r.pnl_pct || 0), 0) / trades.length * 10) / 10,
    };
  }

  // #7: Time-of-Day Accuracy Map
  getTimeAccuracy() {
    const byHour = {};
    this.records.filter(r => r.outcome).forEach(r => {
      const h = r.hour || 10;
      if (!byHour[h]) byHour[h] = { total: 0, wins: 0 };
      byHour[h].total++;
      if (r.pnl_pct > 0) byHour[h].wins++;
    });
    const result = {};
    for (const [h, data] of Object.entries(byHour)) {
      result[h] = data.total >= 5 ? Math.round(data.wins / data.total * 100) : null;
    }
    return result;
  }

  // #8: Expiry Week Pattern Memory
  getExpiryDayPerformance() {
    const byDay = { 1: [], 2: [], 3: [], 4: [], 5: [] };
    this.records.filter(r => r.outcome && r.dte_band <= 1).forEach(r => {
      const d = r.day_of_week || 3;
      if (byDay[d]) byDay[d].push(r.pnl_pct || 0);
    });
    const result = {};
    for (const [d, pnls] of Object.entries(byDay)) {
      if (pnls.length >= 3) {
        result[d] = {
          count: pnls.length,
          winRate: Math.round(pnls.filter(p => p > 0).length / pnls.length * 100),
          avgPnl: Math.round(pnls.reduce((a, b) => a + b, 0) / pnls.length * 10) / 10,
        };
      }
    }
    return result;
  }

  // #5: Degradation Detector
  getDegradation() {
    const recent = this.records.filter(r => r.outcome).slice(-30);
    const older = this.records.filter(r => r.outcome).slice(-100, -30);
    if (recent.length < 15 || older.length < 20) return null;
    const recentWR = recent.filter(r => r.pnl_pct > 0).length / recent.length;
    const olderWR = older.filter(r => r.pnl_pct > 0).length / older.length;
    if (olderWR - recentWR > 0.15) {
      return {
        degrading: true,
        recentWR: Math.round(recentWR * 100),
        historicalWR: Math.round(olderWR * 100),
        note: `Win rate dropped from ${Math.round(olderWR*100)}% to ${Math.round(recentWR*100)}% in last 30 trades`,
      };
    }
    return { degrading: false };
  }

  // #2: Weight Auto-Calibration
  _learnFromOutcome(record) {
    const key = `${record.regime}|${record.gex_regime}`;
    if (!this.learnings.weights[key]) this.learnings.weights[key] = {};
    // Simple: if direction was correct, boost dimensions that agreed. If wrong, reduce.
    const isWin = record.pnl_pct > 0;
    const dims = ['pcr_band', 'trend_dir', 'momentum_zone', 'gex_flip_zone', 'adx_band'];
    dims.forEach(d => {
      const val = record[d] || 0;
      const agreed = (record.direction === 'BUY' && val > 0) || (record.direction === 'SELL' && val < 0);
      if (!this.learnings.weights[key][d]) this.learnings.weights[key][d] = 1.0;
      if (agreed && isWin) this.learnings.weights[key][d] = Math.min(1.5, this.learnings.weights[key][d] + 0.02);
      else if (agreed && !isWin) this.learnings.weights[key][d] = Math.max(0.5, this.learnings.weights[key][d] - 0.03);
    });
    this.learnings.totalAnalyzed++;
    this._saveLearnings();
  }

  // #3: Veto Validator
  recordVeto(vetoName, wouldHaveWon) {
    if (!this.learnings.vetoes[vetoName]) this.learnings.vetoes[vetoName] = { triggered: 0, savedMoney: 0, blockedProfit: 0 };
    this.learnings.vetoes[vetoName].triggered++;
    if (wouldHaveWon) this.learnings.vetoes[vetoName].blockedProfit++;
    else this.learnings.vetoes[vetoName].savedMoney++;
    this._saveLearnings();
  }

  getWeightAdjustment(regime, gexRegime, dimension) {
    const key = `${regime}|${gexRegime}`;
    return (this.learnings.weights[key] && this.learnings.weights[key][dimension]) || 1.0;
  }

  /**
   * Empirical calibration with Beta(2,2) shrinkage. No probability is exposed
   * before 20 completed live-premium paper trades; before then the UI must label
   * confidence as an unvalidated model score.
   */
  getCalibration(direction, regime, modelScore) {
    const all = this.records.filter(r => r.outcome && Number.isFinite(Number(r.pnl_pct)));
    const directional = all.filter(r => r.direction === direction);
    const specific = directional.filter(r => r.regime === regime);
    let sample = specific.length >= 20 ? specific : directional.length >= 20 ? directional : all;
    const sampleScope = specific.length >= 20 ? 'direction+regime' : directional.length >= 20 ? 'direction' : 'all';
    const n = sample.length;
    const wins = sample.filter(r => Number(r.pnl_pct) > 0).length;
    const posteriorWinRate = n ? ((wins + 2) / (n + 4)) * 100 : null;
    const available = n >= 20;
    const weight = available ? Math.min(0.65, n / 100) : 0;
    const calibratedScore = available
      ? Math.round((Number(modelScore) * (1 - weight) + posteriorWinRate * weight) * 10) / 10
      : Number(modelScore);
    return {
      available, minimumSamples: 20, sampleSize: n, wins, scope: sampleScope,
      empiricalWinRate: available ? Math.round(wins / n * 1000) / 10 : null,
      posteriorWinRate: available ? Math.round(posteriorWinRate * 10) / 10 : null,
      modelScore: Math.round(Number(modelScore) * 10) / 10,
      calibratedScore,
      label: available ? 'calibrated from completed paper trades' : `unvalidated model score — ${Math.max(0, 20 - n)} more completed trades required`,
    };
  }

  getStats() {
    const completed = this.records.filter(r => r.outcome);
    const wins = completed.filter(r => r.pnl_pct > 0);
    return {
      totalRecords: this.records.length,
      completedTrades: completed.length,
      wins: wins.length,
      losses: completed.length - wins.length,
      winRate: completed.length ? Math.round(wins.length / completed.length * 100) : 0,
      avgPnl: completed.length ? Math.round(completed.reduce((s, r) => s + (r.pnl_pct || 0), 0) / completed.length * 10) / 10 : 0,
      totalLearnings: this.learnings.totalAnalyzed,
      degradation: this.getDegradation(),
    };
  }

  _load() {
    // Server-owned; populated by godHydrate(). Returns the shared array so that
    // hydration fills it in place and existing references stay valid.
    return _godState.patterns;
  }
  _save() {
    // NOTE: do NOT sync patterns to brain_ingest here. signal.html ingestToBrain()
    // already POSTs the authoritative record with real adx/atr/pcr/htf/ltp values.
    // The old _syncPatternsToServer() wrote a SECOND row per signal with all-zero
    // fields, which poisoned pattern matching and win-rate learning.
    _godState.patterns = this.records;
    godSaveState();
  }
  _loadLearnings() { return _godState.learnings; } // server-owned
  _saveLearnings() {
    _godState.learnings = this.learnings;
    godSaveState();
    // Also append a learnings audit row to brain-data (separate concern: history).
    this._syncLearningsToServer();
  }

  _syncPatternsToServer() {
    // DISABLED (v1.2.0). This method used to POST a duplicate record per signal
    // with pcr/adx/rsi/iv/atr/ltp hard-coded to 0 and htf ''. Those phantom rows
    // made up ~50% of brain-data and were fed straight into pattern matching and
    // win-rate learning, so the brain was training on garbage.
    // signal.html ingestToBrain() is now the single writer for brain_ingest.
  }

  _syncLearningsToServer() {
    try {
      authedFetch(PROXY + '?action=brain_ingest', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          instrument: 'GODMODE_LEARNINGS',
          direction: 'LEARNING_UPDATE',
          confidence: this.learnings.totalAnalyzed,
          net_score: Object.keys(this.learnings.weights).length,
          regime: JSON.stringify(this.learnings.vetoes).substring(0, 200),
          gex_regime: '',
          pcr: 0, adx: 0, rsi: 0, iv: 0, dte: 0, fii: 0, vix: 0, htf: '',
          ltp: 0, atr: 0,
          vetoes: Object.keys(this.learnings.vetoes),
          trade: { weights: this.learnings.weights, vetoes: this.learnings.vetoes, totalAnalyzed: this.learnings.totalAnalyzed },
          timestamp: Date.now() / 1000,
        }),
      }).catch(() => {});
    } catch (e) {}
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// #9-17: REAL-TIME MONITORING (Velocity Tracker)
// ═══════════════════════════════════════════════════════════════════════════════

class VelocityTracker {
  constructor() {
    this.history = {}; // dimension → [{value, ts}]
    this.maxHistory = 20;
  }

  update(dimension, value) {
    if (!this.history[dimension]) this.history[dimension] = [];
    this.history[dimension].push({ value, ts: Date.now() });
    if (this.history[dimension].length > this.maxHistory) this.history[dimension].shift();
  }

  // #9: Velocity (rate of change per minute)
  getVelocity(dimension) {
    const h = this.history[dimension];
    if (!h || h.length < 2) return 0;
    const dt = (h[h.length - 1].ts - h[h.length - 2].ts) / 60000; // minutes
    if (dt <= 0) return 0;
    return (h[h.length - 1].value - h[h.length - 2].value) / dt;
  }

  // Acceleration (2nd derivative)
  getAcceleration(dimension) {
    const h = this.history[dimension];
    if (!h || h.length < 3) return 0;
    const v1 = (h[h.length - 1].value - h[h.length - 2].value);
    const v2 = (h[h.length - 2].value - h[h.length - 3].value);
    return v1 - v2;
  }

  // #12: PCR Velocity Alert
  getPCRVelocity() { return this.getVelocity('pcr'); }

  // #13: GEX Flip Countdown
  getGEXFlipVelocity() { return this.getVelocity('gex_flip_distance'); }

  // #16: Multi-Instrument Correlation
  getCorrelationAlert(instruments) {
    // If one index is breaking down while others hold → divergence alert
    const biases = {};
    for (const [inst, tracker] of Object.entries(instruments)) {
      const vel = tracker.getVelocity('day_change');
      biases[inst] = vel;
    }
    return biases;
  }

  // Detect regime shift: 4+ dimensions moving in same direction simultaneously
  detectRegimeShift() {
    const velocities = {};
    let bullish = 0, bearish = 0;
    for (const [dim, h] of Object.entries(this.history)) {
      const vel = this.getVelocity(dim);
      if (Math.abs(vel) > 0.1) {
        velocities[dim] = vel;
        if (vel > 0.1) bullish++;
        else if (vel < -0.1) bearish++;
      }
    }
    if (bullish >= 4) return { type: 'BULLISH_SHIFT', count: bullish, dims: velocities };
    if (bearish >= 4) return { type: 'BEARISH_SHIFT', count: bearish, dims: velocities };
    return null;
  }

  // #14: Volume Spike Detector
  detectVolumeSpike(currentRatio) {
    const h = this.history['volume_ratio'];
    if (!h || h.length < 3) return false;
    const avg = h.slice(-5).reduce((s, x) => s + x.value, 0) / Math.min(5, h.length);
    return currentRatio > avg * 2.5; // 2.5x recent average = spike
  }

  // Get fastest moving dimensions (for dashboard)
  getFastestMovers(n = 5) {
    const movers = [];
    for (const [dim, h] of Object.entries(this.history)) {
      const vel = Math.abs(this.getVelocity(dim));
      if (vel > 0.01) movers.push({ dim, velocity: this.getVelocity(dim), accel: this.getAcceleration(dim) });
    }
    movers.sort((a, b) => Math.abs(b.velocity) - Math.abs(a.velocity));
    return movers.slice(0, n);
  }

  // Divergence detection (#16 related)
  detectDivergences() {
    const divs = [];
    const priceVel = this.getVelocity('day_change');
    const rsiVel = this.getVelocity('rsi');
    const pcrVel = this.getVelocity('pcr');
    const volVel = this.getVelocity('volume_ratio');

    if (priceVel > 0.05 && rsiVel < -0.05)
      divs.push({ type: 'BEARISH_RSI_DIV', note: 'Price rising but RSI falling — momentum weakening' });
    if (priceVel < -0.05 && rsiVel > 0.05)
      divs.push({ type: 'BULLISH_RSI_DIV', note: 'Price falling but RSI rising — selling exhausting' });
    if (priceVel > 0.05 && pcrVel < -0.1)
      divs.push({ type: 'PCR_WARNING', note: 'Price rising but PCR falling — put support withdrawing' });
    if (Math.abs(priceVel) > 0.05 && volVel < -0.1)
      divs.push({ type: 'LOW_CONVICTION', note: 'Price moving but volume declining — low institutional backing' });
    return divs;
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// #18-27: SIGNAL GENERATION (47-Dimension Evidence Chain)
// ═══════════════════════════════════════════════════════════════════════════════

class EvidenceChainBuilder {
  // #20: Evidence Chain — full reasoning trace
  build(dimensions, direction, netScore) {
    const evidence = { primary: [], supporting: [], counter: [] };
    const signDir = netScore >= 0 ? 1 : -1;

    for (const [dim, data] of Object.entries(dimensions)) {
      if (!data || Math.abs(data.normalized) < 0.15) continue;
      const weight = DIM_WEIGHTS[dim] || 0;
      if (weight < 4) continue;

      const agrees = (data.normalized > 0 && signDir > 0) || (data.normalized < 0 && signDir < 0);
      const strength = Math.abs(data.normalized) * weight;
      const entry = {
        factor: dim,
        finding: data.finding || `${dim}: ${data.normalized > 0 ? 'Bullish' : 'Bearish'} (${data.normalized.toFixed(2)})`,
        impact: Math.round(data.normalized * weight),
        weight: weight >= 8 ? 'CRITICAL' : weight >= 6 ? 'HIGH' : 'MEDIUM',
        velocity: data.velocity || 0,
      };

      if (agrees) {
        if (strength > 5) evidence.primary.push(entry);
        else evidence.supporting.push(entry);
      } else {
        evidence.counter.push(entry);
      }
    }

    // Sort by absolute impact
    evidence.primary.sort((a, b) => Math.abs(b.impact) - Math.abs(a.impact));
    evidence.counter.sort((a, b) => Math.abs(b.impact) - Math.abs(a.impact));
    evidence.primary = evidence.primary.slice(0, 5);
    evidence.counter = evidence.counter.slice(0, 3);

    return evidence;
  }

  // #21: Counter-Argument Generator
  getCounterArguments(evidence) {
    return evidence.counter.map(e => `${e.factor}: ${e.finding} (${e.impact > 0 ? '+' : ''}${e.impact} against)`);
  }
}

// #19: 7-Stage Confidence Calculator
class ConfidenceCalculator {
  calculate(netBias, agreement, regime, gexRegime, gexFlipDist, htfAligned, evidenceQuality, historicalWR, velocity) {
    const bd = {};

    // Stage 1: Base
    bd.base = Math.min(99, Math.abs(netBias) * 0.62 + agreement * 42);

    // Stage 2: Regime
    bd.regime = regime === 'Trending' ? 8 : regime === 'Developing' ? 3 : regime === 'Choppy' ? -10 : 0;

    // Stage 3: GEX (distance-scaled)
    const dist = Math.abs(gexFlipDist);
    if (gexRegime === 'Negative Gamma') bd.gex = 8;
    else if (gexRegime === 'Positive Gamma') {
      if (dist <= 1.0) bd.gex = 4; // Transition zone
      else if (dist <= 2.0) bd.gex = -3;
      else if (dist <= 4.0) bd.gex = -6;
      else bd.gex = -10;
    } else bd.gex = 0;

    // Stage 4: Historical
    bd.historical = historicalWR >= 75 ? 8 : historicalWR >= 65 ? 5 : historicalWR >= 55 ? 2 : historicalWR >= 45 ? -3 : historicalWR > 0 ? -8 : 0;

    // Stage 5: Velocity (regime shift detection)
    bd.velocity = velocity >= 4 ? 8 : velocity >= 2 ? 3 : 0;

    // Stage 6: Multi-TF
    bd.mtf = htfAligned === true ? 7 : htfAligned === false ? -8 : 0;

    // Stage 7: Evidence Quality
    bd.evidence = evidenceQuality >= 4 ? 6 : evidenceQuality >= 3 ? 3 : evidenceQuality <= -3 ? -6 : 0;

    bd.final = Math.max(0, Math.min(99, bd.base + bd.regime + bd.gex + bd.historical + bd.velocity + bd.mtf + bd.evidence));
    return bd;
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// #28-36: RISK MANAGEMENT (Risk Shield)
// ═══════════════════════════════════════════════════════════════════════════════

class RiskShield {
  constructor() {
    this.activeTrades = this._loadTrades();
    this.dailyPnL = 0;
    this.dailySignals = 0;
    this.dailyStops = 0;
    // Populated from ?action=daily_risk. Null means "not yet known", which is deliberately
    // distinct from "zero loss today" — the breaker must not be evaluated against a figure
    // it has not actually loaded.
    this.dailyRisk = null;
  }

  // #28: Breakeven Acceleration
  moveStopToBreakeven(tradeId) {
    const t = this.activeTrades[tradeId];
    if (t) { t.stopPremium = t.entryPremium; t.breakevenActive = true; this._saveTrades(); }
  }

  // #29: Trailing Premium Stop (20% ratchet)
  updateTrailingStop(tradeId, currentPremium) {
    const t = this.activeTrades[tradeId];
    if (!t) return null;
    if (currentPremium > (t.highWater || t.entryPremium)) {
      t.highWater = currentPremium;
      const newStop = t.highWater * 0.80; // 20% below peak
      if (newStop > t.stopPremium) {
        t.stopPremium = Math.max(t.entryPremium, newStop); // Never below entry after T1
        this._saveTrades();
        return { newStop: t.stopPremium, highWater: t.highWater };
      }
    }
    return null;
  }

  // #30: Theta Bleed Monitor
  checkThetaBleed(tradeId, currentPremium, spotMovePct) {
    const t = this.activeTrades[tradeId];
    if (!t) return false;
    const premLoss = (t.entryPremium - currentPremium) / t.entryPremium;
    // Premium lost >15% but spot barely moved (<0.3%)
    return premLoss > 0.15 && Math.abs(spotMovePct) < 0.003 && (Date.now() - t.entryTime) > 600000;
  }

  // #31: IV Crush Auto-Exit
  checkIVCrush(entryIV, currentIV) {
    return (entryIV - currentIV) >= 3.0; // IV dropped 3%+ absolute
  }

  // #32: Circuit Breaker (40% in 5 min)
  checkCircuitBreaker(tradeId, currentPremium) {
    const t = this.activeTrades[tradeId];
    if (!t) return false;
    const drawdown = (t.entryPremium - currentPremium) / t.entryPremium * 100;
    const elapsed = (Date.now() - t.entryTime) / 1000;
    return drawdown >= 40 && elapsed <= 300;
  }

  // #33: Time-Based Auto-Exit
  checkTimeExit(dte) {
    const ist = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
    const minutes = ist.getHours() * 60 + ist.getMinutes();
    if (dte <= 1 && minutes >= 915) return { exit: true, reason: 'Expiry day — 3:15 PM reached. Theta exponential.' };
    if (minutes >= 920) return { exit: true, reason: 'Market about to close. Exit all positions.' };
    return { exit: false };
  }

  // #34: Max Daily Loss Limit
  /**
   * Daily-loss circuit breaker.
   *
   * This used to read `this.dailyPnL`, which starts at 0 and is only mutated by
   * closeTrade() — a method with no callers anywhere. So it compared 0 against the limit on
   * every signal and returned false permanently: a hard risk stop that could never fire,
   * while the UI reported it as active protection. The capital default was a hardcoded
   * 20000 as well, against a desk that actually runs 100000, so the threshold was five
   * times tighter than intended had it ever worked.
   *
   * It now uses server-derived REALISED P&L from the trade log (?action=daily_risk), which
   * survives reloads, is shared across tabs, and includes positions closed by cron — none
   * of which per-tab memory could do. If that figure has not been fetched yet it falls back
   * to the in-memory value rather than inventing a breach.
   */
  checkDailyLimit(capital = null) {
    if (this.dailyRisk && typeof this.dailyRisk.breached === 'boolean') return this.dailyRisk.breached;
    const base = capital || this.dailyRisk?.capital || 100000;
    return this.dailyPnL < -(base * 0.04);
  }

  /** Feed in the server-derived figures. */
  setDailyRisk(dr) {
    if (dr && typeof dr === 'object') {
      this.dailyRisk = dr;
      if (typeof dr.realisedPnl === 'number') this.dailyPnL = dr.realisedPnl;
      if (typeof dr.stops === 'number') this.dailyStops = dr.stops;
      if (typeof dr.trades === 'number') this.dailySignals = dr.trades;
    }
  }

  // #35: Position Size Calculator (Half-Kelly)
  calculatePositionSize(confidence, premium, lotSize, capital = 20000) {
    const p = Math.min(0.75, Math.max(0.45, (confidence / 100) * 0.85 + 0.10));
    const b = 1.8; // Assumed win/loss ratio
    const kelly = Math.max(0, (p * b - (1 - p)) / b);
    const halfKelly = kelly * 0.5;
    const maxRiskPct = Math.min(2.0, halfKelly * 100);
    const maxRisk = capital * (maxRiskPct / 100);
    const riskPerLot = premium * lotSize;
    const lots = Math.max(1, Math.min(5, Math.floor(maxRisk / Math.max(1, riskPerLot))));
    return { lots, maxRisk: Math.round(lots * riskPerLot), capitalPct: Math.round(lots * riskPerLot / capital * 100 * 10) / 10, kelly: Math.round(kelly * 1000) / 1000 };
  }

  // #36: Hedge Suggestion
  suggestHedge(strike, optType, step, ltp, iv, dte) {
    const sellStrike = optType === 'CE' ? strike + step * 2 : strike - step * 2;
    const t = Math.max(0.001, dte / 365);
    const sigma = Math.max(0.05, iv);
    // Simplified spread cost estimate
    const netDebit = Math.max(1, Math.abs(strike - sellStrike) * 0.3);
    const maxGain = Math.abs(strike - sellStrike) - netDebit;
    return {
      type: 'Debit Spread',
      sellStrike,
      description: `Sell ${sellStrike} ${optType} to cap loss at ~₹${Math.round(netDebit)}/unit. Max gain ~₹${Math.round(maxGain)}/unit.`,
      riskReward: Math.round(maxGain / netDebit * 10) / 10,
    };
  }

  // #6: Win/Loss Streak Awareness
  getStreakInfo() {
    const recent = this.activeTrades ? Object.values(this.activeTrades) : [];
    // Load from pattern memory
    return { dailySignals: this.dailySignals, dailyStops: this.dailyStops, dailyPnL: this.dailyPnL,
             // Surfaced so the UI can distinguish "no loss today" from "never loaded", and
             // show how much room is left before the stop rather than only a boolean.
             dailyRiskKnown: !!this.dailyRisk,
             capital: this.dailyRisk?.capital ?? null,
             lossLimitAmount: this.dailyRisk?.lossLimitAmount ?? null,
             remainingBeforeHalt: this.dailyRisk?.remainingBeforeHalt ?? null,
             breached: this.dailyRisk?.breached ?? null };
  }

  startTrade(id, entry) {
    this.activeTrades[id] = { ...entry, entryTime: Date.now(), highWater: entry.entryPremium, breakevenActive: false };
    this.dailySignals++;
    this._saveTrades();
  }

  closeTrade(id, exitPremium, reason) {
    const t = this.activeTrades[id];
    if (!t) return null;
    const pnl = ((exitPremium - t.entryPremium) / t.entryPremium) * 100;
    if (pnl < 0 && reason === 'STOP_LOSS') this.dailyStops++;
    this.dailyPnL += pnl;
    delete this.activeTrades[id];
    this._saveTrades();
    return { pnl, duration: (Date.now() - t.entryTime) / 60000, reason };
  }

  _loadTrades() { return _godState.trades; } // server-owned
  _saveTrades() { _godState.trades = this.activeTrades; godSaveState(); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// #37-44: OPTION INTELLIGENCE
// ═══════════════════════════════════════════════════════════════════════════════

class OptionIntelligence {
  // #37: IV Percentile Engine
  getIVRegime(iv) {
    if (iv >= 28) return { regime: 'EXTREME', note: 'Options EXPENSIVE. Spreads only.', penaltyConf: -5 };
    if (iv >= 20) return { regime: 'HIGH', note: 'Prefer debit spreads to cap IV crush.', penaltyConf: -3 };
    if (iv >= 12) return { regime: 'MODERATE', note: 'Naked longs acceptable.', penaltyConf: 0 };
    return { regime: 'LOW', note: 'Options CHEAP. Max leverage with naked longs.', penaltyConf: 3 };
  }

  // #38: Gamma/Theta Efficiency
  gammaTheta(strike, ltp, dte, iv) {
    const t = Math.max(0.001, dte / 365);
    const sigma = Math.max(0.05, iv);
    const sqrtT = Math.sqrt(t);
    const d1 = (Math.log(ltp / strike) + (0.065 + sigma * sigma / 2) * t) / (sigma * sqrtT);
    const nd1 = Math.exp(-0.5 * d1 * d1) / Math.sqrt(2 * Math.PI);
    const gamma = nd1 / (ltp * sigma * sqrtT);
    const theta = -(ltp * nd1 * sigma) / (2 * sqrtT) / 365;
    const ratio = gamma / Math.max(0.001, Math.abs(theta));
    return { gamma, theta, ratio, efficient: ratio > 0.03 };
  }

  // #39: Optimal Strike Selector
  selectStrike(direction, ltp, step, dte, iv, confidence) {
    const atm = Math.round(ltp / step) * step;
    const optType = direction === 'SELL' ? 'PE' : 'CE';
    let strike = atm, moneyness = 'ATM', reason = '';

    if (dte <= 2) {
      strike = direction === 'BUY' ? atm - step : atm + step;
      moneyness = 'ITM'; reason = 'Short DTE: ITM for high delta.';
    } else if (iv >= 0.22 || (iv >= 0.18 && confidence < 70)) {
      strike = atm; moneyness = 'ATM'; reason = 'High IV: ATM for spread structure.';
    } else if (iv < 0.12 && confidence >= 70) {
      strike = atm; moneyness = 'ATM'; reason = 'Low IV + high confidence: ATM for max gamma.';
    } else {
      strike = atm; moneyness = 'ATM'; reason = 'Standard: ATM balanced delta/gamma.';
    }
    return { strike, optType, moneyness, reason };
  }

  // #40: Premium Ceiling Calculator
  premiumCeiling(ltp, strike, optType, dte, iv, atr) {
    const t = Math.max(0.001, dte / 365);
    const sigma = Math.max(0.05, iv);
    const bsPrice = this._bs(optType, ltp, strike, t, 0.065, sigma);
    // Target: 1.5 ATR move should give 2:1 RR
    const targetSpot = optType === 'CE' ? ltp + 1.5 * atr : ltp - 1.5 * atr;
    const targetPrem = this._bs(optType, targetSpot, strike, t * 0.5, 0.065, sigma * 0.95);
    const maxFromRR = targetPrem / 3; // Ensures 2:1 RR minimum
    const ceiling = Math.min(bsPrice * 1.1, Math.max(bsPrice * 0.5, maxFromRR));
    return { maxPremium: Math.max(2, Math.round(ceiling * 100) / 100), fairValue: Math.round(bsPrice * 100) / 100, targetPremium: Math.round(targetPrem * 100) / 100 };
  }

  // #42: Max Pain Gravity
  maxPainGravity(ltp, maxPain, dte) {
    if (!maxPain || dte > 3) return { active: false };
    const pull = (maxPain - ltp) / Math.max(1, Math.abs(ltp)) * 100;
    return { active: true, maxPain, pull: Math.round(pull * 100) / 100, note: `Price gravitating toward ${maxPain} (${pull > 0 ? 'up' : 'down'} ${Math.abs(pull).toFixed(2)}%)` };
  }

  // #44: Expiry Week Theta Curve
  thetaCurveWarning(dte) {
    if (dte <= 0.5) return { severity: 'EXTREME', note: 'Expiry day: theta is exponential. Only immediate moves work.' };
    if (dte <= 1) return { severity: 'HIGH', note: 'Day before expiry: theta accelerating fast.' };
    if (dte <= 2) return { severity: 'MODERATE', note: '2 days to expiry: theta noticeable. Quick trades only.' };
    return { severity: 'LOW', note: 'Theta manageable at this DTE.' };
  }

  _bs(type, S, K, t, r, sig) {
    if (t <= 0 || sig <= 0) return type === 'CE' ? Math.max(0, S - K) : Math.max(0, K - S);
    const d1 = (Math.log(S / K) + (r + sig * sig / 2) * t) / (sig * Math.sqrt(t));
    const d2 = d1 - sig * Math.sqrt(t);
    const Nd1 = this._ncdf(d1), Nd2 = this._ncdf(d2);
    return type === 'CE' ? S * Nd1 - K * Math.exp(-r * t) * Nd2 : K * Math.exp(-r * t) * this._ncdf(-d2) - S * this._ncdf(-d1);
  }
  _ncdf(x) { const a = 0.254829592, b = -0.284496736, c = 1.421413741, d = -1.453152027, e = 1.061405429, p = 0.3275911; const s = x < 0 ? -1 : 1; x = Math.abs(x) / Math.sqrt(2); const t = 1 / (1 + p * x); const y = 1 - (((((e * t + d) * t) + c) * t + b) * t + a) * t * Math.exp(-x * x); return 0.5 * (1 + s * y); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// #45-48: ALERT SYSTEM (3-Stage Escalation)
// ═══════════════════════════════════════════════════════════════════════════════

class AlertEngine {
  constructor() {
    this.state = {}; // instrument → { stage, triggerLevel, escalatedAt, ... }
  }

  // #45: 3-Stage Alert Flow
  evaluate(instrument, confidence, direction, triggerLevel, ltp) {
    if (!this.state[instrument]) this.state[instrument] = { stage: 0, alerts: [] };
    const s = this.state[instrument];
    const prev = s.stage;

    if (confidence >= 60 && direction !== 'NO_TRADE') {
      // Stage 3: CONFIRMED
      if (s.stage < 3) {
        s.stage = 3;
        s.confirmedAt = Date.now();
        return { stage: 3, action: 'CONFIRMED', message: `🟢 CONFIRMED — ${instrument} ${direction}. ENTER NOW.` };
      }
    } else if (confidence >= 50 && Math.abs(direction !== 'NO_TRADE' || confidence >= 45)) {
      if (triggerLevel && ltp) {
        const breached = (direction === 'SELL' && ltp <= triggerLevel) || (direction === 'BUY' && ltp >= triggerLevel);
        if (breached && s.stage < 3) {
          // #46: Instant Trigger Alert — trigger breached!
          s.stage = 3;
          return { stage: 3, action: 'TRIGGER_BREACH', message: `⚡ TRIGGER BREACHED — ${instrument} crossed ${triggerLevel}. CONFIRMED.` };
        }
        if (s.stage < 2) {
          s.stage = 2;
          return { stage: 2, action: 'TRIGGER_ARMED', message: `🎯 TRIGGER ARMED — ${instrument} approaching ${triggerLevel}` };
        }
      }
      if (s.stage < 1) {
        s.stage = 1;
        s.triggerLevel = triggerLevel;
        return { stage: 1, action: 'SETUP_BUILDING', message: `⏳ SETUP BUILDING — ${instrument} ${direction} lean, confidence ${confidence}%` };
      }
    } else {
      // Reset if conditions deteriorate
      if (s.stage > 0 && confidence < 35) {
        s.stage = 0;
        return { stage: 0, action: 'RESET', message: `Setup dissolved for ${instrument}` };
      }
    }
    return { stage: s.stage, action: 'HOLD', message: null };
  }

  // #47: Target Hit Notifications
  targetHit(instrument, targetNum, premium) {
    const msgs = {
      1: `🎯 T1 HIT — ${instrument}! Book ⅓. Stop → cost (ZERO LOSS).`,
      2: `🎯 T2 HIT — ${instrument}! Book ⅓ more. Trail remaining.`,
      3: `🎯 T3 HIT — ${instrument}! Exit all. Maximum profit captured.`,
    };
    return msgs[targetNum] || '';
  }

  // #48: Exit Alert
  exitAlert(instrument, reason) {
    return `🚪 EXIT ${instrument} — ${reason}`;
  }

  getState(instrument) { return this.state[instrument] || { stage: 0 }; }
  resetAll() { this.state = {}; }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN: GOD BRAIN ORCHESTRATOR (combines all 51 capabilities)
// ═══════════════════════════════════════════════════════════════════════════════

class GodBrain {
  constructor() {
    this.memory = new PatternMemory();
    this.velocity = new VelocityTracker();
    this.evidence = new EvidenceChainBuilder();
    this.confidence = new ConfidenceCalculator();
    this.risk = new RiskShield();
    this.options = new OptionIntelligence();
    this.alerts = new AlertEngine();
    this.scanInterval = NORMAL_SCAN_INTERVAL;
    this._rapidActive = false;
    this.version = GOD_VERSION;
    console.log(`[GodBrain] v${GOD_VERSION} constructed. Awaiting server state...`);
  }

  /**
   * Resolves once brain state has been fetched from the server.
   *
   * State is no longer in localStorage, so it arrives asynchronously. Callers must
   * await this before process() — otherwise the first signal would be matched
   * against an empty pattern history and then that empty history would be saved
   * back over the real one.
   */
  /**
   * Publish the latest God Mode result to the server.
   * Was sessionStorage, which is per-tab — god-dashboard.html opened in another
   * browser or on a phone found nothing and showed zeros.
   */
  recordLastSignal(gm) {
    _godState.lastSignal = gm;
    _godState.lastSignalAt = Date.now();
    godSaveState();
  }

  /** Latest God Mode result as loaded from the server (null before hydration). */
  getLastSignal() {
    return _godState.lastSignal || null;
  }

  setExecutorLease(owner) {
    _godLeaseOwner = owner || null;
    _godState.executorLeaseOwner = _godLeaseOwner;
    if (!_godLeaseOwner) clearTimeout(_godSaveTimer);
  }

  /** Attach a real completed paper-trade outcome to its originating pattern. */
  recordOutcome(patternId, outcome, pnlPct, moveAtr, durationMinutes) {
    if (patternId == null) return false;
    return this.memory.recordOutcome(patternId, outcome, pnlPct, moveAtr, durationMinutes);
  }

  /**
   * Active signal lock (the 5-minute hysteresis hold), shared via the server.
   * Keeping it server-side means a signal locked on desktop is also shown as
   * locked on mobile, instead of each tab holding its own idea of the trade.
   */
  getSignalLock() { return _godState.signalLock || null; }
  setSignalLock(lock) { _godState.signalLock = lock || null; godSaveState(); }

  /** Re-pull server state. The dashboard is a viewer, so it must poll for updates. */
  async refresh() {
    await godHydrate();
    this.memory.records = _godState.patterns;
    this.memory.learnings = _godState.learnings;
    this.risk.activeTrades = _godState.trades;
    return this;
  }

  ready() {
    if (_godHydrated) return Promise.resolve(this);
    if (!_godReadyPromise) {
      _godReadyPromise = godHydrate().then(() => {
        // Re-point the live objects at the hydrated data.
        this.memory.records = _godState.patterns;
        this.memory.learnings = _godState.learnings;
        this.risk.activeTrades = _godState.trades;
        console.log(`[GodBrain] v${GOD_VERSION} state loaded from server — ${this.memory.records.length} patterns, ${Object.keys(this.risk.activeTrades || {}).length} tracked trades, ${this.memory.learnings.totalAnalyzed || 0} analyzed.`);
        return this;
      });
    }
    return _godReadyPromise;
  }

  /**
   * MAIN ENTRY: Process a signal through all 51 capabilities.
   * Called after live-engine.js generates a raw signal.
   * Returns enhanced signal with God Mode intelligence.
   */
  process(rawSignal, snapshot, options = {}) {
    const persist = options.persist !== false;
    const start = performance.now();
    const result = { ...rawSignal, godMode: {} };
    const gm = result.godMode;

    // ── Update velocity tracker with all available dimensions ──
    this._updateVelocity(snapshot, rawSignal);

    // ── Build 47-dimension state ──
    const dimensions = this._buildDimensions(snapshot, rawSignal);
    gm.dimensions = dimensions;

    // ── Regime shift detection (#9-17) ──
    gm.regimeShift = this.velocity.detectRegimeShift();
    gm.divergences = this.velocity.detectDivergences();
    gm.fastestMovers = this.velocity.getFastestMovers(5);
    gm.pcrVelocity = this.velocity.getPCRVelocity();
    gm.gexFlipVelocity = this.velocity.getGEXFlipVelocity();

    // ── Evidence chain (#20-21) ──
    gm.evidence = this.evidence.build(dimensions, rawSignal.direction, rawSignal.netScore || 0);
    gm.counterArguments = this.evidence.getCounterArguments(gm.evidence);

    // ── Historical context (#1, #4, #5, #7, #8) ──
    const fingerprint = this._buildFingerprint(rawSignal, snapshot);
    gm.fingerprint = fingerprint;
    const similar = this.memory.findSimilar(fingerprint, rawSignal.direction || 'SELL');
    gm.historicalMatches = similar.length;
    gm.historicalWinRate = similar.length >= 20
      ? Math.round(similar.filter(r => r.pnl_pct > 0).length / similar.length * 100)
      : 0;
    gm.regimePerformance = this.memory.getRegimePerformance(rawSignal.strategy?.regime || '');
    gm.degradation = this.memory.getDegradation();
    gm.timeAccuracy = this.memory.getTimeAccuracy();
    gm.expiryDayPerf = this.memory.getExpiryDayPerformance();

    // ── 7-Stage Confidence (#19) ──
    const regime = rawSignal.strategy?.regime || 'Unknown';
    const gexRegime = snapshot.gex?.regime || '';
    const gexFlipDist = snapshot.gex?.flip && snapshot.ltp
      ? (snapshot.ltp - snapshot.gex.flip) / Math.max(1, rawSignal.atr || snapshot.ltp * 0.01)
      : 999;
    const htfAligned = rawSignal.strategy?.mtf === 'Bearish' && rawSignal.direction === 'SELL' ||
                       rawSignal.strategy?.mtf === 'Bullish' && rawSignal.direction === 'BUY' ? true :
                       rawSignal.strategy?.mtf && rawSignal.strategy.mtf !== 'n/a' ? false : null;
    const velCount = gm.regimeShift ? gm.regimeShift.count : 0;
    const evidenceQuality = (gm.evidence.primary?.length || 0) - (gm.evidence.counter?.length || 0);

    gm.confidenceBreakdown = this.confidence.calculate(
      rawSignal.netScore || 0, rawSignal.agreement || 0.5,
      regime, gexRegime, gexFlipDist, htfAligned,
      evidenceQuality, gm.historicalWinRate, velCount
    );
    gm.modelConfidence = gm.confidenceBreakdown.final;
    gm.calibration = this.memory.getCalibration(rawSignal.direction || 'NO_TRADE', regime, gm.modelConfidence);
    gm.godConfidence = gm.calibration.available ? gm.calibration.calibratedScore : gm.modelConfidence;

    // ── Self-improvement weight adjustments (#2) ──
    const adj = this.memory.getWeightAdjustment(regime, gexRegime, 'pcr_band');
    gm.weightAdjustment = adj !== 1.0 ? adj : null;

    // ── Option Intelligence (#37-44) ──
    const iv = snapshot.iv || 0.15;
    const dte = snapshot.dte || 5;
    gm.ivRegime = this.options.getIVRegime(iv * 100);
    gm.thetaCurve = this.options.thetaCurveWarning(dte);
    if (snapshot.gex?.maxPain) gm.maxPainGravity = this.options.maxPainGravity(snapshot.ltp, snapshot.gex.maxPain, dte);
    if (rawSignal.direction !== 'NO_TRADE') {
      const step = Number(snapshot.strikeStep) || { NIFTY: 50, BANKNIFTY: 100, FINNIFTY: 50, MIDCPNIFTY: 25 }[rawSignal.symbol] || 50;
      const strikeInfo = this.options.selectStrike(rawSignal.direction, snapshot.ltp, step, dte, iv, gm.godConfidence);
      gm.optimalStrike = strikeInfo;
      gm.premiumCeiling = this.options.premiumCeiling(snapshot.ltp, strikeInfo.strike, strikeInfo.optType, dte, iv, rawSignal.atr || snapshot.ltp * 0.01);
      gm.gammaTheta = this.options.gammaTheta(strikeInfo.strike, snapshot.ltp, dte, iv);
      gm.hedge = this.risk.suggestHedge(strikeInfo.strike, strikeInfo.optType, step, snapshot.ltp, iv, dte);
    }

    // ── Risk Management (#28-36) ──
    gm.positionSize = rawSignal.direction !== 'NO_TRADE'
      ? this.risk.calculatePositionSize(gm.godConfidence, gm.premiumCeiling?.maxPremium || 20, snapshot.lotSize || 75)
      : null;
    // Kick a refresh (non-blocking) and evaluate the breaker on whatever the server last
    // reported. Deliberately not awaited: a slow request must not stall signal rendering,
    // and the TTL means the figure is at most a minute old.
    godFetchDailyRisk().then(dr => { if (dr) this.risk.setDailyRisk(dr); }).catch(() => {});
    if (_dailyRisk) this.risk.setDailyRisk(_dailyRisk);
    gm.dailyLimit = this.risk.checkDailyLimit();
    gm.dailyRisk = _dailyRisk || null;
    gm.streakInfo = this.risk.getStreakInfo();
    gm.timeExit = this.risk.checkTimeExit(dte);

    // ── Alert System (#45-48) ──
    const triggerLevel = rawSignal.watchTrade?.entryTrigger || rawSignal.optionTrade?.entryTrigger;
    const alertDir = rawSignal.direction !== 'NO_TRADE' ? rawSignal.direction : (rawSignal.netScore >= 0 ? 'BUY' : 'SELL');
    gm.alert = this.alerts.evaluate(rawSignal.symbol, gm.godConfidence, rawSignal.direction, triggerLevel, snapshot.ltp);

    // ── Rapid scan activation (#9) ──
    if (gm.alert.stage >= 1 && !this._rapidActive) {
      this._rapidActive = true;
      this.scanInterval = RAPID_SCAN_INTERVAL;
      gm.rapidScanActive = true;
    } else if (gm.alert.stage === 0 && this._rapidActive) {
      this._rapidActive = false;
      this.scanInterval = NORMAL_SCAN_INTERVAL;
      gm.rapidScanActive = false;
    }

    // ── #24: Regime-Adaptive Thresholds ──
    //
    // These are compared against godConfidence, whose realistic range is roughly
    // 40-85 once `agreement` is supplied correctly (it was defaulting to 0.5, which
    // held the ceiling near 66). A threshold of 80 on that scale was not a risk
    // control — it was an off switch, and it is why expiry day never produced a
    // confirmed trade.
    //
    // Expiry day IS genuinely the most dangerous session: the same gamma that lets a
    // 24150 CE double in minutes also takes it to zero. So it keeps the HIGHEST bar
    // of any regime — but a reachable one, backed by two structural conditions that
    // matter more than an arbitrary number: the higher timeframe must not be fighting
    // the trade, and there must be enough time left for the move to pay before theta
    // and the close take over.
    let threshold = 60;
    if (regime === 'Trending') threshold = 55;
    else if (regime === 'Choppy') threshold = 75;

    gm.expiryDay = dte <= 1;
    if (gm.expiryDay) {
      threshold = Math.max(threshold, 68);
      const ist = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
      const mins = ist.getHours() * 60 + ist.getMinutes();
      gm.expiryGuards = {
        htfNotAgainst: htfAligned !== false,     // 1H must not oppose the trade
        timeBuffer: mins <= 14 * 60 + 45,        // stop opening new expiry risk after 14:45
      };
      gm.expiryBlocked = !gm.expiryGuards.htfNotAgainst || !gm.expiryGuards.timeBuffer;
    } else {
      gm.expiryGuards = null;
      gm.expiryBlocked = false;
    }

    gm.adaptiveThreshold = threshold;
    gm.meetsThreshold = gm.godConfidence >= threshold && !gm.expiryBlocked;

    // ── Final God Mode Verdict ──
    gm.actionable = rawSignal.direction !== 'NO_TRADE' && gm.meetsThreshold && !gm.dailyLimit && !gm.timeExit.exit;
    gm.verdict = this._buildVerdict(rawSignal, gm);

    // ── Record in pattern memory ──
    // Only record patterns that can ever be LABELLED.
    //
    // This used to also store NO_TRADE observations whose conviction cleared 40, and they
    // accumulated to 194 of 425 records — 46% of the store — while being structurally
    // incapable of ever receiving an outcome: a NO_TRADE never becomes a position, and
    // findSimilar()/getCalibration() only consider completed records. So they contributed
    // nothing to learning while consuming the capped store and, under the old blind
    // tail-slice eviction, actively displacing the handful of real outcomes.
    //
    // Observational history is still kept — it moved to the confirmed-signal log
    // (?action=signal_log), which is the right home for "what did we see" as opposed to
    // "what did we learn". This store is now strictly the outcome-learning corpus.
    if (persist && rawSignal.direction !== 'NO_TRADE') {
      const recId = this.memory.record({
        instrument: rawSignal.symbol,
        direction: rawSignal.direction,
        confidence: gm.godConfidence,
        netScore: rawSignal.netScore,
        ...fingerprint,
      });
      gm.patternId = recId;
    }

    // ── Brain stats ──
    gm.brainStats = this.memory.getStats();
    gm.processingMs = Math.round((performance.now() - start) * 100) / 100;

    // Server-side sync only from the active executor. Viewer tabs compute the
    // same diagnostics but cannot replace shared learning state.
    if (persist) this._syncToServer(rawSignal.symbol, gm, snapshot);

    return result;
  }

  // ── INTERNAL HELPERS ──────────────────────────────────────────────────────

  _updateVelocity(snapshot, signal) {
    if (snapshot.pcr) this.velocity.update('pcr', snapshot.pcr);
    if (snapshot.gex?.flip && snapshot.ltp) this.velocity.update('gex_flip_distance', (snapshot.ltp - snapshot.gex.flip) / (signal.atr || 100));
    if (signal.rsi) this.velocity.update('rsi', signal.rsi / 100);
    if (snapshot.iv) this.velocity.update('iv', snapshot.iv);
    if (signal.netScore) this.velocity.update('day_change', signal.netScore / 100);
    const volRatio = snapshot.volumes ? snapshot.volumes[snapshot.volumes.length - 1] / (snapshot.volumes.slice(-20).reduce((a, b) => a + b, 0) / 20) : 1;
    this.velocity.update('volume_ratio', volRatio);
    if (snapshot.gex?.netGEX) this.velocity.update('gex_net', snapshot.gex.netGEX);
  }

  _buildDimensions(snapshot, signal) {
    const dims = {};
    const ltp = snapshot.ltp || signal.ltp;
    const atr = signal.atr || ltp * 0.01;

    // Options
    if (snapshot.pcr) dims.pcr = { normalized: snapshot.pcr > 1.2 ? 0.5 : snapshot.pcr < 0.7 ? -0.5 : (snapshot.pcr - 0.95) * 2, finding: `PCR ${snapshot.pcr.toFixed(2)}`, velocity: this.velocity.getVelocity('pcr') };
    if (snapshot.gex) {
      dims.gex_regime = { normalized: snapshot.gex.regime === 'Negative Gamma' ? -1 : 1, finding: snapshot.gex.regime };
      if (snapshot.gex.flip && ltp) {
        const dist = (ltp - snapshot.gex.flip) / atr;
        dims.gex_flip_distance = { normalized: Math.max(-1, Math.min(1, dist / 4)), finding: `${Math.abs(dist).toFixed(1)} ATR from flip`, velocity: this.velocity.getVelocity('gex_flip_distance') };
      }
    }
    if (snapshot.iv) dims.atm_iv = { normalized: (snapshot.iv * 100 - 16) / 10, finding: `IV ${(snapshot.iv * 100).toFixed(1)}%` };

    // Trend
    if (signal.superTrend) dims.supertrend = { normalized: signal.superTrend === 'bullish' ? 1 : -1, finding: `SuperTrend ${signal.superTrend}` };
    if (signal.strategy?.adx != null) dims.adx_value = { normalized: Math.min(1, signal.strategy.adx / 50), finding: `ADX ${signal.strategy.adx}` };
    if (signal.strategy?.regime) dims.adx_regime = { normalized: signal.strategy.regime === 'Trending' ? 1 : signal.strategy.regime === 'Choppy' ? -1 : 0, finding: signal.strategy.regime };
    if (signal.strategy?.mtf) dims.htf_trend = { normalized: signal.strategy.mtf === 'Bullish' ? 1 : signal.strategy.mtf === 'Bearish' ? -1 : 0, finding: `HTF ${signal.strategy.mtf}` };

    // Momentum
    if (signal.rsi != null) dims.rsi = { normalized: (signal.rsi - 50) / 30, finding: `RSI ${signal.rsi.toFixed(0)}`, velocity: this.velocity.getVelocity('rsi') };

    // Flow
    if (snapshot.fiiFlow != null) dims.fii_flow = { normalized: Math.max(-1, Math.min(1, snapshot.fiiFlow / 2000)), finding: `FII ${snapshot.fiiFlow > 0 ? '+' : ''}${snapshot.fiiFlow}` };

    // Volatility
    if (snapshot.vix) dims.vix = { normalized: (snapshot.vix - 15) / 10, finding: `VIX ${snapshot.vix}` };

    return dims;
  }

  _buildFingerprint(signal, snapshot) {
    const regime = signal.strategy?.regime || '';
    const gexRegime = snapshot.gex?.regime || '';
    const pcr = snapshot.pcr || 1;
    const adx = signal.strategy?.adx || 15;
    const rsi = signal.rsi || 50;
    const dte = snapshot.dte || 5;
    const ltp = snapshot.ltp || 0;
    const flip = snapshot.gex?.flip || ltp;
    const atr = signal.atr || ltp * 0.01;

    return {
      gex_regime: gexRegime.includes('Negative') ? -1 : gexRegime.includes('Positive') ? 1 : 0,
      trend_dir: signal.netScore > 10 ? 1 : signal.netScore < -10 ? -1 : 0,
      adx_band: adx >= 25 ? 1 : adx < 18 ? -1 : 0,
      pcr_band: pcr > 1.2 ? 1 : pcr < 0.7 ? -1 : 0,
      momentum_zone: rsi > 60 ? 1 : rsi < 40 ? -1 : 0,
      gex_flip_zone: atr > 0 ? Math.round(Math.max(-2, Math.min(2, (ltp - flip) / atr))) : 0,
      session_phase: new Date().getHours() < 11 ? 1 : new Date().getHours() >= 14 ? 3 : 2,
      dte_band: dte <= 1 ? 0 : dte <= 3 ? 1 : 2,
      regime,
      gex_regime: gexRegime,
    };
  }

  _buildVerdict(signal, gm) {
    if (!gm.actionable) {
      const reasons = [];
      if (signal.direction === 'NO_TRADE') reasons.push('no directional edge');
      if (!gm.meetsThreshold) reasons.push(`confidence ${gm.godConfidence.toFixed(0)}% < ${gm.adaptiveThreshold}% threshold`);
      if (gm.dailyLimit) reasons.push('daily loss limit reached');
      if (gm.timeExit.exit) reasons.push(gm.timeExit.reason);
      return `NO TRADE — ${reasons.join(', ')}`;
    }
    let v = `${signal.direction} ${signal.symbol} · God Mode ${gm.godConfidence.toFixed(0)}%`;
    if (gm.historicalMatches >= 20) v += ` · History: ${gm.historicalWinRate}% WR (${gm.historicalMatches} similar)`;
    if (gm.regimeShift) v += ` · ⚡ REGIME SHIFT`;
    if (gm.optimalStrike) v += ` · Strike: ${gm.optimalStrike.strike} ${gm.optimalStrike.optType}`;
    return v;
  }

  _syncToServer(instrument, gm, snapshot) {
    // DISABLED (v1.3.0) — this was silently CORRUPTING the training data.
    //
    // It wrote God Mode's internal confidence-breakdown components into fields
    // whose names mean something completely different:
    //     net_score <- gm.confidenceBreakdown.base    (a sub-score, not net score)
    //     adx       <- gm.confidenceBreakdown.regime  (a sub-score, not ADX)
    //     rsi       <- 0 always
    //     vetoes    <- [] always
    //     direction <- 'ACTIVE' (not a real trade direction)
    //
    // Because those numbers land in plausible ranges, nothing looked broken, but
    // live data showed two rows for the same instant disagreeing with each other
    // (net=+33.7/adx=8 next to the true net=-20.5/adx=31). Pattern matching and
    // win-rate learning were consuming mislabeled features.
    //
    // God Mode output is now recorded by signal.html ingestToBrain() under
    // correctly-named god_* fields. Single writer, one row per signal.
  }

  // Public API for dashboard
  getFullStatus() {
    return {
      version: this.version,
      brainStats: this.memory.getStats(),
      activeTrades: this.risk.activeTrades,
      alertStates: this.alerts.state,
      rapidScanActive: this._rapidActive,
      scanInterval: this.scanInterval,
      timeAccuracy: this.memory.getTimeAccuracy(),
      degradation: this.memory.getDegradation(),
      vetoStats: this.memory.learnings.vetoes,
      learnings: this.memory.learnings,
    };
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXPORT: Singleton instance
// ═══════════════════════════════════════════════════════════════════════════════

export const godBrain = new GodBrain();
export { GodBrain, PatternMemory, VelocityTracker, EvidenceChainBuilder, ConfidenceCalculator, RiskShield, OptionIntelligence, AlertEngine };
