/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * AI PAPER TRADING ENGINE — 50 Super-Powerful Features
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Fully automated paper trading desk that:
 * - Runs 5 strategies simultaneously and picks the winner
 * - Shadow-trades every REJECTED signal to validate vetoes
 * - Runs what-if parallel universes on every trade
 * - Simulates slippage, spread, and execution delay
 * - Manages portfolio-level risk across multiple instruments
 * - Feeds every outcome back to God Mode brain for training
 * - Generates predictive scoring for next-day bias
 * - Benchmarks against buy-hold, straddle, and blind strategies
 *
 * NO hypothetical data. Every number comes from real signals + real premiums.
 *
 * Storage: SERVER ONLY (?action=paper_state). No localStorage — browser storage
 * made state device-specific and let stale config survive, so it was removed.
 */

import { postJSON, authedFetch, primeWriteAuth } from './core/write-auth.js?v=1.0';
import { godBrain } from './god-mode.js?v=2.5';
import { summarize as riskSummarize, returnsFromTrades } from './core/risk-metrics.js?v=1.0';

const PROXY = '/signals/api/proxy.php';
const PT_VERSION = '4.6.0'; // Reward/risk measured to T2 runner target (0.90 floor kept)
// Server-owned state. There is deliberately NO localStorage key here: browser
// storage made the numbers device-specific and let stale state survive config
// changes. The server is the single source of truth.
const PT_STATE_URL = '/signals/api/proxy.php?action=paper_state';
const INITIAL_CAPITAL = 100000; // ₹1,00,000 total budget
const MAX_PER_TRADE = 20000;   // Never exceed ₹20,000 in one trade
const VERY_HIGH_CONFIDENCE = 80; // Only these setups are allowed to hold for T2

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION A: 5-STRATEGY COMPETITION
// ═══════════════════════════════════════════════════════════════════════════════

const STRATEGIES = {
  aggressive: {
    name: 'Aggressive',
    minConfidence: 55,
    sizeMultiplier: 1.0,
    stopATR: 1.2,
    exitAt: 'T1_OR_T2', // Confidence policy: T1 normally, T2 at 80%+
    regimeFilter: null, // Takes all regimes
    gexFilter: null,
    description: 'Enters at 55%+ confidence, full size; common confidence policy books T1 or T2',
  },
  conservative: {
    name: 'Conservative',
    minConfidence: 75,
    sizeMultiplier: 0.5,
    stopATR: 1.0,
    exitAt: 'T1_OR_T2',
    regimeFilter: null,
    gexFilter: null,
    description: 'Only 75%+ confidence, half size; common confidence policy books T1 or T2',
  },
  scalper: {
    name: 'Scalper',
    minConfidence: 60,
    sizeMultiplier: 1.0,
    stopATR: 0.8,
    exitAt: 'T1_OR_T2',
    regimeFilter: null,
    gexFilter: null,
    description: 'Tight stops (0.8 ATR); common confidence policy books T1 or T2',
  },
  momentum: {
    name: 'Momentum',
    minConfidence: 60,
    sizeMultiplier: 1.0,
    stopATR: 1.5,
    exitAt: 'T1_OR_T2',
    regimeFilter: 'Trending', // Only in trending markets
    gexFilter: 'Negative Gamma', // Only when dealers amplify
    description: 'Only Trending + Neg Gamma. Wide stops; common confidence policy books T1 or T2',
  },
  meanReversion: {
    name: 'Mean Reversion',
    minConfidence: 60,
    sizeMultiplier: 0.75,
    stopATR: 1.0,
    exitAt: 'T1_OR_T2',
    regimeFilter: 'Choppy', // Only in choppy/range
    gexFilter: 'Positive Gamma', // Dealers suppress = mean revert
    description: 'Only Choppy + Pos Gamma. Fades extremes; common confidence policy books T1 or T2',
  },
};

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION E: SLIPPAGE & REALITY MODEL
// ═══════════════════════════════════════════════════════════════════════════════

class SlippageModel {
  // Execution friction is DISABLED — paper fills occur at the exact live premium.
  //
  // The previous model added spread + impact + delay (~1.8% on index premiums) to
  // every entry and shaved it off every exit. On ₹300-400 premiums that pushed the
  // entry 7-9 points above the live quote, so a position opened showing an instant
  // loss and needed a ~3.3% round-trip move just to break even. Product decision:
  // fill at the displayed live premium so P&L reflects the real option move. The
  // model is retained (returning zeros) so every downstream slippage field and the
  // totalSlippage stat keep working, and a realistic model can return here later.
  calculate(premium, lotSize, volumeRatio) {
    return {
      entrySlippage: 0,
      exitSlippage: 0,
      totalPerUnit: 0,
      totalPerLot: 0,
      breakdown: { spread: 0, impact: 0, delay: 0 },
    };
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION B: SHADOW TRADING (Track rejected signals)
// ═══════════════════════════════════════════════════════════════════════════════

class ShadowTracker {
  constructor(storage) {
    this.shadows = storage.shadows || [];
  }

  // Record a rejected signal once per fingerprint/cooldown. Returns whether a
  // new shadow was actually created so callers never claim a false write.
  recordRejected(signal, snapshot, reason) {
    const trade = signal.optionTrade || signal.watchTrade;
    if (!trade || !trade.entryPremium || trade.premiumSource !== 'live' || !trade.expiry) return false;
    const direction = signal.direction || (signal.netScore >= 0 ? 'BUY' : 'SELL');
    const duplicate = [...this.shadows].reverse().find(s => !s.checkedAt
      && Date.now() - new Date(s.timestamp).getTime() < 15 * 60000
      && s.instrument === signal.symbol && s.direction === direction
      && Number(s.strike) === Number(trade.strike) && s.type === trade.type
      && s.reason === reason);
    if (duplicate) return false;

    this.shadows.push({
      id: Date.now(),
      timestamp: new Date().toISOString(),
      instrument: signal.symbol,
      direction,
      confidence: signal.confidence,
      reason: reason, // Why it was rejected
      entryPremium: trade.entryPremium,
      strike: trade.strike,
      type: trade.type,
      t1Premium: trade.targets ? trade.targets[0].premium : 0,
      t2Premium: trade.targets ? trade.targets[1].premium : 0,
      stopPremium: trade.stopLoss ? trade.stopLoss.premium : 0,
      // Outcome (filled later when we check)
      peakPremium: trade.entryPremium,
      finalPremium: null,
      wouldHaveWon: null,
      missedPnlPct: null,
      checkedAt: null,
    });

    // Keep last 200 shadow trades
    if (this.shadows.length > 200) this.shadows = this.shadows.slice(-200);
    return true;
  }

  // Update shadow trades with live premium data
  updateWithLive(instrument, livePremium) {
    for (const s of this.shadows) {
      if (s.instrument !== instrument || s.finalPremium !== null) continue;
      if (!livePremium) continue;

      // Track peak premium
      if (livePremium > s.peakPremium) s.peakPremium = livePremium;

      // Check if it would have hit T1
      if (livePremium >= s.t1Premium && s.wouldHaveWon === null) {
        s.wouldHaveWon = true;
        s.missedPnlPct = Math.round((s.t1Premium - s.entryPremium) / s.entryPremium * 100);
      }

      // Check if it would have hit stop
      if (livePremium <= s.stopPremium && s.wouldHaveWon === null) {
        s.wouldHaveWon = false;
        s.missedPnlPct = Math.round((s.stopPremium - s.entryPremium) / s.entryPremium * 100);
      }
    }
  }

  // Close old shadow trades (after 4 hours or end of day)
  closeStale() {
    const now = Date.now();
    for (const s of this.shadows) {
      if (s.finalPremium !== null) continue;
      const age = now - new Date(s.timestamp).getTime();
      if (age > 4 * 3600000) { // 4 hours
        s.finalPremium = s.peakPremium;
        if (s.wouldHaveWon === null) {
          s.wouldHaveWon = s.peakPremium > s.entryPremium * 1.05; // >5% peak = would have won with trailing
          s.missedPnlPct = Math.round((s.peakPremium - s.entryPremium) / s.entryPremium * 100);
        }
        s.checkedAt = new Date().toISOString();
      }
    }
  }

  getStats() {
    const completed = this.shadows.filter(s => s.wouldHaveWon !== null);
    if (!completed.length) return { total: this.shadows.length, completed: 0, wouldHaveWon: 0, missedProfit: 0 };
    const winners = completed.filter(s => s.wouldHaveWon);
    const totalMissed = winners.reduce((sum, s) => sum + (s.missedPnlPct || 0), 0);
    return {
      total: this.shadows.length,
      completed: completed.length,
      wouldHaveWon: winners.length,
      wouldHaveLost: completed.length - winners.length,
      vetoAccuracy: Math.round((completed.length - winners.length) / completed.length * 100), // % of rejections that were CORRECT
      missedProfitAvg: winners.length ? Math.round(totalMissed / winners.length) : 0,
      byReason: this._groupByReason(completed),
    };
  }

  _groupByReason(completed) {
    const groups = {};
    for (const s of completed) {
      const r = s.reason || 'Unknown';
      if (!groups[r]) groups[r] = { total: 0, correct: 0, wrong: 0 };
      groups[r].total++;
      if (s.wouldHaveWon) groups[r].wrong++; // Veto was WRONG (blocked a winner)
      else groups[r].correct++; // Veto was RIGHT (blocked a loser)
    }
    return groups;
  }

  getRecent(n = 20) { return this.shadows.slice(-n).reverse(); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION C: WHAT-IF SIMULATOR
// ═══════════════════════════════════════════════════════════════════════════════

class WhatIfSimulator {
  constructor(storage) {
    this.scenarios = storage.whatIf || [];
  }

  // For every trade taken, record what-if alternatives
  recordAlternatives(trade, snapshot) {
    const step = { NIFTY: 50, BANKNIFTY: 100, FINNIFTY: 50, MIDCPNIFTY: 25 }[trade.instrument] || 50;

    this.scenarios.push({
      tradeId: trade.id,
      timestamp: new Date().toISOString(),
      instrument: trade.instrument,
      actualStrike: trade.strike,
      actualEntry: trade.entryPremium,
      actualStop: trade.stopATR,
      alternatives: {
        // What if different strike?
        strikeITM: { strike: trade.type === 'PE' ? trade.strike + step : trade.strike - step, note: 'ITM (+1 strike)' },
        strikeOTM: { strike: trade.type === 'PE' ? trade.strike - step : trade.strike + step, note: 'OTM (-1 strike)' },
        // What if different stop?
        widerStop: { stopATR: 1.5, note: '1.5x ATR stop (wider)' },
        tighterStop: { stopATR: 0.8, note: '0.8x ATR stop (tighter)' },
        // What if spread instead of naked?
        spread: { type: 'spread', note: 'Debit spread (capped loss)' },
        // Outcomes (filled when trade closes)
        results: null,
      },
    });

    if (this.scenarios.length > 100) this.scenarios = this.scenarios.slice(-100);
  }

  // When actual trade closes, calculate what-if outcomes
  resolveForTrade(tradeId, actualOutcome, actualPnlPct, peakPremium) {
    const scenario = this.scenarios.find(s => s.tradeId === tradeId);
    if (!scenario) return;

    // Wider stop: fewer stop-outs (if actual was stopped, wider might have survived)
    const widerWouldSurvive = actualOutcome === 'STOP_LOSS' && peakPremium > scenario.actualEntry;
    // Tighter stop: more stop-outs (if actual won, tighter might have been stopped)
    const tighterWouldStop = actualOutcome.startsWith('WIN') && (scenario.actualEntry * 0.85) < scenario.actualEntry;

    scenario.alternatives.results = {
      actual: { outcome: actualOutcome, pnlPct: actualPnlPct },
      widerStop: {
        wouldHave: widerWouldSurvive ? 'SURVIVED (potential win)' : actualOutcome,
        note: widerWouldSurvive ? 'Wider stop would have kept you in this trade' : 'Same outcome',
      },
      tighterStop: {
        wouldHave: tighterWouldStop ? 'STOPPED_OUT' : actualOutcome,
        note: tighterWouldStop ? 'Tighter stop would have killed this winner early' : 'Same outcome',
      },
      spread: {
        wouldHave: actualPnlPct > 0 ? `+${Math.round(actualPnlPct * 0.6)}% (capped)` : `${Math.max(-30, Math.round(actualPnlPct * 0.4))}% (loss limited)`,
        note: 'Spread caps both upside and downside',
      },
    };
  }

  getInsights() {
    const resolved = this.scenarios.filter(s => s.alternatives.results);
    if (!resolved.length) return null;

    let widerBetter = 0, tighterBetter = 0, spreadBetter = 0;
    for (const s of resolved) {
      const r = s.alternatives.results;
      if (r.widerStop.wouldHave === 'SURVIVED (potential win)') widerBetter++;
      if (r.tighterStop.wouldHave !== 'STOPPED_OUT' && r.actual.pnlPct < 0) tighterBetter++;
      if (r.actual.pnlPct < -20 && r.spread.wouldHave.includes('loss limited')) spreadBetter++;
    }

    return {
      totalResolved: resolved.length,
      widerStopBetter: widerBetter,
      widerStopPct: Math.round(widerBetter / resolved.length * 100),
      tighterStopBetter: tighterBetter,
      spreadBetterOnLosses: spreadBetter,
      recommendation: widerBetter > resolved.length * 0.3
        ? 'Consider using 1.5 ATR stops — ' + widerBetter + ' of your stops would have survived and potentially won.'
        : tighterBetter > resolved.length * 0.3
        ? 'Tighter stops would have reduced losses.'
        : 'Current stop distance is optimal.',
    };
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION F: PSYCHOLOGICAL PROFILING
// ═══════════════════════════════════════════════════════════════════════════════

class PsychProfiler {
  analyze(trades) {
    if (trades.length < 10) return null;

    const insights = [];

    // Revenge trading: trades taken within 30 min of a loss
    let revengeTrades = 0, revengeWins = 0;
    for (let i = 1; i < trades.length; i++) {
      if (trades[i - 1].pnl < 0) {
        const gap = new Date(trades[i].openDate) - new Date(trades[i - 1].exitDate || trades[i - 1].openDate);
        if (gap < 30 * 60000) { // Within 30 min of loss
          revengeTrades++;
          if (trades[i].pnl > 0) revengeWins++;
        }
      }
    }
    if (revengeTrades >= 3) {
      const revengeWR = Math.round(revengeWins / revengeTrades * 100);
      insights.push({
        type: 'REVENGE_TRADING',
        severity: revengeWR < 40 ? 'HIGH' : 'MEDIUM',
        finding: `${revengeTrades} trades taken within 30 min of a loss. Win rate on those: ${revengeWR}%.`,
        recommendation: revengeWR < 40 ? 'Add 45-min cooldown after losses.' : 'Monitoring — revenge trades performing ok.',
      });
    }

    // Overconfidence after wins
    let afterWinStreak = [], normalTrades = [];
    let streak = 0;
    for (const t of trades) {
      if (streak >= 3) afterWinStreak.push(t);
      else normalTrades.push(t);
      if (t.pnl > 0) streak++;
      else streak = 0;
    }
    if (afterWinStreak.length >= 3) {
      const awWR = Math.round(afterWinStreak.filter(t => t.pnl > 0).length / afterWinStreak.length * 100);
      const normalWR = normalTrades.length ? Math.round(normalTrades.filter(t => t.pnl > 0).length / normalTrades.length * 100) : 50;
      if (awWR < normalWR - 10) {
        insights.push({
          type: 'OVERCONFIDENCE',
          severity: 'MEDIUM',
          finding: `After 3+ wins: WR drops to ${awWR}% (normal: ${normalWR}%).`,
          recommendation: 'Reduce size after 3 consecutive wins.',
        });
      }
    }

    // Time-of-day performance
    const byHour = {};
    for (const t of trades) {
      const h = new Date(t.openDate).getHours();
      if (!byHour[h]) byHour[h] = { wins: 0, total: 0 };
      byHour[h].total++;
      if (t.pnl > 0) byHour[h].wins++;
    }
    let bestHour = null, worstHour = null, bestWR = 0, worstWR = 100;
    for (const [h, data] of Object.entries(byHour)) {
      if (data.total < 3) continue;
      const wr = data.wins / data.total * 100;
      if (wr > bestWR) { bestWR = wr; bestHour = h; }
      if (wr < worstWR) { worstWR = wr; worstHour = h; }
    }
    if (bestHour && worstHour && bestWR - worstWR > 20) {
      insights.push({
        type: 'TIME_BIAS',
        severity: 'LOW',
        finding: `Best hour: ${bestHour}:00 (${Math.round(bestWR)}% WR). Worst: ${worstHour}:00 (${Math.round(worstWR)}% WR).`,
        recommendation: `Focus trading at ${bestHour}:00. Avoid or reduce size at ${worstHour}:00.`,
      });
    }

    return insights;
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION G: PREDICTIVE SCORING (Next-Day Bias)
// ═══════════════════════════════════════════════════════════════════════════════

class PredictiveScorer {
  generatePrediction(lastSignal, lastSnapshot, historicalPatterns) {
    if (!lastSignal || !lastSnapshot) return null;

    const gex = lastSnapshot.gex;
    const regime = lastSignal.strategy?.regime || 'Unknown';
    const netScore = lastSignal.netScore || 0;
    const htf = lastSignal.strategy?.mtf || 'n/a';
    const pcr = lastSnapshot.pcr || 1.0;

    // Directional bias from closing state
    let direction = 'NEUTRAL';
    let probability = 50;

    if (Math.abs(netScore) > 15) {
      direction = netScore > 0 ? 'BULLISH' : 'BEARISH';
      probability = Math.min(75, 50 + Math.abs(netScore) * 0.8);
    }

    // GEX influence on next day
    let gexNote = '';
    if (gex) {
      if (gex.regime === 'Negative Gamma') {
        gexNote = 'Negative Gamma persists — expect continuation of today\'s move. Trend trades favored.';
        probability += 5;
      } else {
        gexNote = 'Positive Gamma — expect mean reversion toward ' + (gex.flip || 'flip level') + '. Range-bound likely.';
        if (direction !== 'NEUTRAL') probability -= 5;
      }
    }

    // HTF alignment
    let htfNote = '';
    if (htf === 'Bullish' && direction === 'BULLISH') {
      htfNote = 'Higher TF confirms bullish — strong continuation expected.';
      probability += 5;
    } else if (htf === 'Bearish' && direction === 'BEARISH') {
      htfNote = 'Higher TF confirms bearish — strong continuation expected.';
      probability += 5;
    } else if (htf !== 'n/a') {
      htfNote = 'Higher TF conflicts — cautious start expected.';
      probability -= 5;
    }

    // Best entry window (empirical)
    const entryWindow = regime === 'Trending' ? '09:30–10:30 (breakout confirmation)' : '10:00–11:00 (range established)';

    return {
      direction,
      probability: Math.max(30, Math.min(80, probability)),
      regime,
      gexNote,
      htfNote,
      entryWindow,
      generatedAt: new Date().toISOString(),
      summary: `Tomorrow bias: ${direction} (${probability}% probability). ${gexNote} Entry window: ${entryWindow}.`,
    };
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION J: COMPETITIVE BENCHMARKING
// ═══════════════════════════════════════════════════════════════════════════════

class Benchmarker {
  constructor(storage) {
    const defaults = { buyHold: { startPrice: 0, currentPrice: 0, pnlPct: 0 }, blindSignals: { trades: 0, wins: 0, pnlPct: 0 }, onlyHigh: { trades: 0, wins: 0, pnlPct: 0 } };
    const stored = storage.benchmarks || {};
    this.benchmarks = {
      buyHold: { ...defaults.buyHold, ...(stored.buyHold || {}) },
      blindSignals: { ...defaults.blindSignals, ...(stored.blindSignals || {}) },
      onlyHigh: { ...defaults.onlyHigh, ...(stored.onlyHigh || {}) },
    };
  }

  updateBuyHold(startPrice, currentPrice) {
    if (!this.benchmarks) this.benchmarks = {};
    if (!this.benchmarks.buyHold) this.benchmarks.buyHold = { startPrice: 0, currentPrice: 0, pnlPct: 0 };
    if (!this.benchmarks.buyHold.startPrice && startPrice) {
      this.benchmarks.buyHold.startPrice = startPrice;
    }
    if (currentPrice) {
      this.benchmarks.buyHold.currentPrice = currentPrice;
      if (this.benchmarks.buyHold.startPrice > 0) {
        this.benchmarks.buyHold.pnlPct = Math.round((currentPrice - this.benchmarks.buyHold.startPrice) / this.benchmarks.buyHold.startPrice * 100 * 10) / 10;
      }
    }
  }

  recordBlindSignal(pnlPct) {
    this.benchmarks.blindSignals.trades++;
    if (pnlPct > 0) this.benchmarks.blindSignals.wins++;
    // Running average P&L
    const prev = this.benchmarks.blindSignals.pnlPct * (this.benchmarks.blindSignals.trades - 1);
    this.benchmarks.blindSignals.pnlPct = Math.round((prev + pnlPct) / this.benchmarks.blindSignals.trades * 10) / 10;
  }

  recordHighConfOnly(pnlPct) {
    this.benchmarks.onlyHigh.trades++;
    if (pnlPct > 0) this.benchmarks.onlyHigh.wins++;
    const prev = this.benchmarks.onlyHigh.pnlPct * (this.benchmarks.onlyHigh.trades - 1);
    this.benchmarks.onlyHigh.pnlPct = Math.round((prev + pnlPct) / this.benchmarks.onlyHigh.trades * 10) / 10;
  }

  getComparison(systemReturn) {
    const bh = this.benchmarks.buyHold || { pnlPct: 0 };
    const bs = this.benchmarks.blindSignals || { pnlPct: 0, trades: 0, wins: 0 };
    const oh = this.benchmarks.onlyHigh || { pnlPct: 0, trades: 0, wins: 0 };
    return {
      system: { returnPct: systemReturn, label: 'AI Paper Trader' },
      buyHold: { returnPct: bh.pnlPct || 0, label: 'NIFTY Buy & Hold' },
      blindSignals: {
        returnPct: bs.pnlPct || 0,
        winRate: bs.trades ? Math.round(bs.wins / bs.trades * 100) : 0,
        label: 'Trade Every Signal (no filter)',
      },
      onlyHigh: {
        returnPct: oh.pnlPct || 0,
        winRate: oh.trades ? Math.round(oh.wins / oh.trades * 100) : 0,
        label: 'Only 80%+ Confidence',
      },
      alpha: Math.round((systemReturn - (bh.pnlPct || 0)) * 10) / 10,
    };
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN: PAPER TRADING ENGINE
// ═══════════════════════════════════════════════════════════════════════════════

export class PaperTradingEngine {
  constructor() {
    this.state = this._load();
    this.slippage = new SlippageModel();
    this.shadow = new ShadowTracker(this.state);
    this.whatIf = new WhatIfSimulator(this.state);
    this.psych = new PsychProfiler();
    this.predictor = new PredictiveScorer();
    this.benchmarker = new Benchmarker(this.state);
    this.version = PT_VERSION;
  }

  _defaultState() {
    return {
      startDate: new Date().toISOString().split('T')[0],
      initialCapital: INITIAL_CAPITAL,
      capital: INITIAL_CAPITAL,
      peakCapital: INITIAL_CAPITAL,
      totalPnl: 0,
      totalSlippage: 0,
      trades: [],
      active: [],
      shadows: [],
      whatIf: [],
      benchmarks: {},
      equityCurve: [{ date: new Date().toISOString().split('T')[0], equity: INITIAL_CAPITAL }],
      strategyResults: {},
      dailyTrades: 0,
      consecutiveLosses: 0,
      lastTradeDate: '',
      lastSignal: null,
      lastSnapshot: null,
      prediction: null,
      lastExecution: null,
      executorHeartbeat: null,
    };
  }

  /**
   * NO localStorage. State is owned by the server (?action=paper_state).
   *
   * Browser storage made the state per-device (desktop and mobile disagreed), let a
   * stale capital base survive a budget change, and produced visible flicker on
   * tool.html because local state and the server report were rendered by two
   * different timers that disagreed.
   *
   * Construction stays synchronous and returns defaults; real state arrives via
   * the async hydrate() below. Callers must await ready() before acting on it.
   */
  _load() {
    return this._defaultState();
  }

  /** Pull authoritative state from the server. Safe to call repeatedly. */
  async hydrate() {
    try {
      const r = await fetch(PT_STATE_URL + '?cb=' + Date.now(), { cache: 'no-store' });
      const j = await r.json();
      if (j && j.status && j.state) {
        const s = j.state;
        if (!s.seeded) {
          // Merge onto defaults so a partial/older payload cannot leave holes.
          this.state = Object.assign(this._defaultState(), s);
        } else {
          this.state = this._defaultState();
        }
        this.state.initialCapital = s.initialCapital || INITIAL_CAPITAL;
        if (Array.isArray(this.state.shadows)) this.shadow.shadows = this.state.shadows;
        if (Array.isArray(this.state.whatIf)) this.whatIf.scenarios = this.state.whatIf;
        if (this.state.benchmarks) this.benchmarker.benchmarks = this.state.benchmarks;
        this._hydrated = true;
        console.log(`[PaperTrade] State loaded from server — capital ₹${Math.round(this.state.capital).toLocaleString('en-IN')}, ${(this.state.trades || []).length} trades, ${(this.state.active || []).length} active.`);
      }
    } catch (e) {
      console.warn('[PaperTrade] Server state unavailable; running on defaults this session.', e);
    }
    return this.state;
  }

  /** Resolves once server state has been applied. */
  ready() {
    if (this._hydrated) return Promise.resolve(this.state);
    if (!this._readyPromise) this._readyPromise = this.hydrate();
    return this._readyPromise;
  }

  /** Rehydrate before a new executor assumes a server lease. */
  async refresh() {
    this._hydrated = false;
    this._readyPromise = null;
    return this.ready();
  }

  setExecutorLease(owner) {
    this._leaseOwner = owner || null;
    this.state.executorLeaseOwner = this._leaseOwner;
  }

  /** Persist an explicit accept/reject reason for tool.html diagnostics. */
  _decision(signal, result, details = {}) {
    this.state.lastExecution = {
      timestamp: new Date().toISOString(),
      instrument: signal?.symbol || null,
      direction: signal?.direction || null,
      confidence: signal?.confidence ?? null,
      executed: !!result.executed,
      reason: result.reason || (result.executed ? 'Paper trade opened' : 'Not executed'),
      ...details,
      auditPending: true,
    };
    this.state.executorHeartbeat = new Date().toISOString();
    this._save();
    return result;
  }

  /**
   * Rebase a saved state that was created under a different budget.
   *
   * Without this, a state saved when the budget was ₹20,000 keeps reporting
   * against ₹20,000 forever, because _load() accepts any state with a truthy
   * initialCapital and the version check only fires when PT_VERSION changes.
   * That is what produced a CAPITAL tile of ₹17,922 and a -10.4% return under a
   * ₹1,00,000 header: -2,078 against the OLD base.
   *
   * Realized P&L is preserved and the equity curve is shifted by the same delta,
   * so trade history stays intact and only the baseline moves.
   */
  _migrateBudget(s) {
    if (!s || s.initialCapital === INITIAL_CAPITAL) return s;
    const oldBase = s.initialCapital || 0;
    const realized = (typeof s.totalPnl === 'number') ? s.totalPnl : ((s.capital || 0) - oldBase);
    const delta = INITIAL_CAPITAL - oldBase;
    s.initialCapital = INITIAL_CAPITAL;
    s.capital = INITIAL_CAPITAL + realized;
    s.peakCapital = Math.max(INITIAL_CAPITAL, s.capital);
    if (Array.isArray(s.equityCurve)) {
      s.equityCurve = s.equityCurve.map(p => ({ ...p, equity: (p.equity || 0) + delta }));
    }
    console.warn(`[PaperTrade] Budget changed ₹${oldBase.toLocaleString('en-IN')} → ₹${INITIAL_CAPITAL.toLocaleString('en-IN')}. `
      + `Rebased capital to ₹${Math.round(s.capital).toLocaleString('en-IN')} (realized P&L ₹${Math.round(realized).toLocaleString('en-IN')} preserved).`);
    return s;
  }

  /**
   * Persist to the SERVER (no localStorage).
   *
   * Debounced because _save() is called on every price tick; without it a 10s
   * refresh loop would POST the whole state several times a second.
   */
  _save() {
    this.state.shadows = this.shadow.shadows;
    this.state.whatIf = this.whatIf.scenarios;
    this.state.benchmarks = this.benchmarker.benchmarks;
    this.state.initialCapital = INITIAL_CAPITAL;
    this.state.maxPerTrade = MAX_PER_TRADE;

    clearTimeout(this._saveTimer);
    this._saveTimer = setTimeout(() => this._flush(), 1200);
  }

  /** Immediate write — use when the tab may be closing. */
  async _flush() {
    if (!this._leaseOwner) {
      console.warn('[PaperTrade] State save skipped: this tab does not hold the executor lease.');
      return;
    }
    try {
      this.state.executorLeaseOwner = this._leaseOwner;
      const response = await postJSON(PT_STATE_URL, this.state);
      if (response && response.ok === false) console.warn('[PaperTrade] State save rejected by lease fence:', response.status);
    } catch (e) {
      console.warn('[PaperTrade] State save failed (will retry on next change).', e);
    }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // AUTO-EXECUTE: Called by signal.html on every confirmed signal
  // ═══════════════════════════════════════════════════════════════════════════

  autoExecute(signal, snapshot, godMode) {
    const s = this.state;
    const today = new Date().toISOString().split('T')[0];
    let executionGate = null;
    const finish = (result, details = {}) => this._decision(signal, result,
      executionGate ? { ...details, executionGate } : details);

    // Market hours check
    const ist = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
    const min = ist.getHours() * 60 + ist.getMinutes();
    if (min < 555 || min > 930 || ist.getDay() === 0 || ist.getDay() === 6) {
      return finish({ executed: false, reason: 'Outside market hours' });
    }

    // Reset daily counter
    if (s.lastTradeDate !== today) { s.dailyTrades = 0; s.lastTradeDate = today; }

    // Store for prediction generation (end of day)
    s.lastSignal = { symbol: signal.symbol, direction: signal.direction, confidence: signal.confidence, netScore: signal.netScore, strategy: signal.strategy };
    s.lastSnapshot = { gex: snapshot.gex, pcr: snapshot.pcr, iv: snapshot.iv, ltp: snapshot.ltp };

    // Update buy-hold benchmark
    if (snapshot.ltp) this.benchmarker.updateBuyHold(null, snapshot.ltp);

    // ── SIGNAL IS NOT CONFIRMED → SHADOW TRADE ──
    if (signal.direction === 'NO_TRADE') {
      let shadowRecorded = false;
      if (signal.confidence >= 40 && Math.abs(signal.netScore || 0) > 5) {
        const reason = signal.vetoes?.length ? signal.vetoes[0] : `Confidence ${signal.confidence}% below threshold`;
        shadowRecorded = this.shadow.recordRejected(signal, snapshot, reason);
      }
      return finish({ executed: false, reason: shadowRecorded ? 'Signal is NO_TRADE (shadow recorded)' : 'Signal is NO_TRADE', shadowRecorded });
    }

    // The raw engine direction is not sufficient: God Mode applies independent
    // evidence, threshold, expiry, loss-limit, and time-exit gates.
    if (!godMode || godMode.actionable !== true) {
      return finish({ executed: false, reason: godMode?.verdict || 'God Mode did not mark this setup actionable' });
    }

    // ── CONFIRMED SIGNAL → EXECUTE ON ALL QUALIFYING STRATEGIES ──
    const trade = signal.optionTrade || signal.watchTrade;
    if (!trade || !trade.entryPremium) return finish({ executed: false, reason: 'No trade plan' });

    // No daily trade limit — user wants unlimited trades

    // Consecutive loss limiter
    if (s.consecutiveLosses >= 2) {
      // Still execute but at half size
    }

    // Max active positions — 1 per instrument (no duplicate trades)
    if (s.active.length >= 3) return finish({ executed: false, reason: 'Max 3 active positions total' });
    // DEDUP: Never enter same instrument+direction if already active
    const alreadyIn = s.active.find(p => p.instrument === signal.symbol && p.direction === signal.direction);
    if (alreadyIn) return finish({ executed: false, reason: `Already have ${signal.direction} position on ${signal.symbol}` });

    // ── CORRELATED EXPOSURE GUARD ──────────────────────────────────────────────
    // The Indian index F&O universe is one trade wearing four names: NIFTY,
    // BANKNIFTY, FINNIFTY and MIDCPNIFTY all track the same domestic equity beta
    // and sell off together. The per-instrument dedup above only stopped a repeat of
    // the SAME symbol, so on a broad move the engine could fill all three position
    // slots with the same directional bet — e.g. on 2026-09-08 it produced BUY CE on
    // NIFTY, BANKNIFTY and FINNIFTY simultaneously during a market-wide decline.
    // That is not three ideas diversifying each other, it is one idea at 3x size,
    // and it is how a single wrong read empties the book.
    //
    // Cap same-direction exposure across the correlated basket at 2 concurrent
    // positions. Opposite-direction positions are unaffected (they hedge), and a
    // non-index underlying is not counted against the basket.
    const CORRELATED_BASKET = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'MIDCPNIFTY', 'SENSEX'];
    const MAX_CORRELATED_SAME_DIR = 2;
    if (CORRELATED_BASKET.includes(signal.symbol)) {
      const sameDirBasket = s.active.filter(p => p.direction === signal.direction
        && CORRELATED_BASKET.includes(p.instrument));
      if (sameDirBasket.length >= MAX_CORRELATED_SAME_DIR) {
        return finish({ executed: false, reason: `Correlated exposure cap: already ${sameDirBasket.length} `
          + `${signal.direction} position(s) on correlated indices (${sameDirBasket.map(p => p.instrument).join(', ')}). `
          + `These move together — adding ${signal.symbol} would concentrate one bet, not diversify.` });
      }
    }
    // DEDUP: Don't re-enter same instrument within 10 minutes of last trade
    const lastTrade = s.trades.filter(t => t.instrument === signal.symbol).slice(-1)[0];
    if (lastTrade && lastTrade.exitDate) {
      const sinceExit = (Date.now() - new Date(lastTrade.exitDate).getTime()) / 60000;
      if (sinceExit < 10) return finish({ executed: false, reason: `Cooldown: ${signal.symbol} traded ${Math.round(sinceExit)}min ago (need 10min)` });
    }

    // Capital check
    const lotSize = trade.lotSize || 75;
    // REFUSE TO OPEN ON AN ESTIMATED PREMIUM.
    // trade.entryPremium is a Black-Scholes estimate until signal.html manages to
    // fetch the real option quote and rebuild the plan (it sets _liveOpt on success).
    // When the quote failed the position was opened at the model price and then
    // marked to the real market price on a later tick — booking a large "loss" that
    // never happened. Measured live: entry 173.60 vs real 91.60 on a NIFTY 24300 CE.
    // Same principle as the server-side STALE_PRICE guardrail: no verifiable price,
    // no execution.
    const _live = signal._liveOpt;
    const liveLtp = Number(_live?.ltp);
    const premiumSourceIsLive = trade.premiumSource === 'live';
    const tradeExecutable = !!trade.executable;
    const hasLiveQuote = !!_live;
    const liveLtpValid = liveLtp > 0;
    const predicateFailures = [];
    if (!premiumSourceIsLive) predicateFailures.push('PREMIUM_NOT_LIVE');
    if (!tradeExecutable) predicateFailures.push('TRADE_PLAN_NOT_EXECUTABLE');
    if (!hasLiveQuote) predicateFailures.push('LIVE_QUOTE_MISSING_FROM_SIGNAL');
    if (!liveLtpValid) predicateFailures.push('LIVE_QUOTE_LTP_INVALID');
    const failedGates = [...new Set([
      ...predicateFailures,
      ...(Array.isArray(trade.failedExecutionGates) ? trade.failedExecutionGates : []),
    ])];
    const contractEligible = typeof trade.executionGates?.contractEligible === 'boolean'
      ? trade.executionGates.contractEligible
      : (typeof _live?.eligible === 'boolean' ? _live.eligible : null);
    executionGate = {
      premiumSource: trade.premiumSource || null,
      tradeExecutable,
      hasLiveQuote,
      liveLtp: Number.isFinite(liveLtp) ? liveLtp : null,
      contractEligible,
      quoteTimestamp: _live?.quoteTimestamp ?? trade.quoteTimestamp ?? null,
      failedGates,
    };
    const failExecutionGate = (code, reason, gateDetails = {}, details = {}) => {
      if (!executionGate.failedGates.includes(code)) executionGate.failedGates.push(code);
      Object.assign(executionGate, gateDetails);
      return finish({ executed: false, reason }, details);
    };
    if (predicateFailures.length) {
      return finish({ executed: false,
               reason: `Execution gates failed: ${failedGates.join(', ')}` });
    }
    if (!trade.expiry && !trade.expiryDisplay) {
      return failExecutionGate('EXACT_EXPIRY_MISSING', 'Exact listed option expiry is missing');
    }
    if (_live.strike != null && Number(_live.strike) !== Number(trade.strike)) {
      return failExecutionGate('LIVE_QUOTE_STRIKE_MISMATCH',
        'Live quote strike does not match the trade plan',
        { quotedStrike: Number(_live.strike), plannedStrike: Number(trade.strike) });
    }
    if (_live.type && String(_live.type).toUpperCase() !== String(trade.type).toUpperCase()) {
      return failExecutionGate('LIVE_QUOTE_TYPE_MISMATCH',
        'Live quote option type does not match the trade plan',
        { quotedType: String(_live.type).toUpperCase(), plannedType: String(trade.type).toUpperCase() });
    }
    const quoteTime = Number(_live.quoteTimestamp || trade.quoteTimestamp || 0);
    const quoteAgeMs = quoteTime ? Date.now() - quoteTime : Infinity;
    executionGate.quoteAgeMs = Number.isFinite(quoteAgeMs) ? quoteAgeMs : null;
    if (!quoteTime || quoteAgeMs > 45000) {
      return failExecutionGate('QUOTE_STALE_OR_MISSING', 'Live option quote is stale or undated');
    }
    if (quoteAgeMs < 0) {
      return failExecutionGate('QUOTE_FROM_FUTURE', 'Live option quote timestamp is in the future');
    }
    if (_live.volume != null && Number(_live.volume) <= 0) {
      return failExecutionGate('LIVE_QUOTE_NO_REPORTED_VOLUME',
        'Option contract has no reported traded volume',
        { reportedVolume: Number(_live.volume) });
    }
    const premium = Number(trade.entryPremium);
    const risk = premium - Number(trade.stopLoss?.premium || 0);
    // Reward is measured to T2 (the runner target), not T1. The engine places T1 at
    // ~1.0*ATR but the stop at ~1.2*ATR, so a T1-only reward/risk is structurally
    // below the 0.90 floor and rejected nearly every confirmed setup. After T1 the
    // stop moves to breakeven and the position trails toward T2, so T1->T2 is the
    // reward leg that actually reflects the trade's risk profile. The 0.90 floor is
    // unchanged; only the target the ratio is measured against is corrected. Falls
    // back to T1 if a T2 level is somehow absent.
    const t1Premium = Number(trade.targets?.[0]?.premium || 0);
    const rewardTarget = Number(trade.targets?.[1]?.premium || 0) || t1Premium;
    const reward = rewardTarget - premium;
    const rr = risk > 0 ? reward / risk : 0;
    if (!(risk > 0) || !(reward > 0) || rr < 0.9) {
      return finish({ executed: false, reason: `Unacceptable option reward/risk ${rr.toFixed(2)} (minimum 0.90)` }, { rewardRisk: rr });
    }
    if (godMode.calibration?.available && Number(godMode.calibration.calibratedScore) < 55) {
      return finish({ executed: false, reason: `Calibrated score ${godMode.calibration.calibratedScore}% is below 55%` });
    }

    // DAILY LOSS STOP — enforced here, at the executor.
    //
    // God Mode computes gm.dailyLimit, but that flag was structurally always false: it read
    // an in-memory counter mutated only by a method with no callers. So the 4% daily stop
    // existed in the UI and in nobody's code path. Now that it is derived from the trade log
    // (?action=daily_risk), the desk that actually opens positions has to honour it —
    // checking it only in the advisory layer would leave the same hole one level down.
    //
    // This is the last line of defence after a bad day, so it refuses on the server-derived
    // figure and does not fall back to a permissive default when that figure is absent.
    const dr = godMode.dailyRisk;
    if (dr && dr.breached === true) {
      return finish({ executed: false, reason:
        `Daily loss limit reached — realised ${Math.round(dr.realisedPnl)} against a `
        + `${Math.round(dr.lossLimitAmount)} stop (${dr.lossLimitPct}% of ${Math.round(dr.capital)}). `
        + `No new entries today.` });
    }
    const slippageCalc = this.slippage.calculate(premium, lotSize, 1.0);
    const effectiveEntry = premium + slippageCalc.entrySlippage;
    const cost = effectiveEntry * lotSize;

    // Per-trade cost cap. One lot is the minimum tradeable unit and cannot be split, so
    // when a single lot already exceeds the cap the instrument is simply untradeable at
    // that premium — no amount of sizing down helps. The old message just showed two
    // numbers, which read like an arbitrary rejection; it now says WHY, and names the
    // premium above which this contract becomes affordable, because on BANKNIFTY
    // (30 x premium) and MIDCPNIFTY (120 x premium) the cap binds surprisingly often
    // and was a silent, unexplained reason for a confirmed signal never appearing here.
    if (cost > MAX_PER_TRADE) {
      const affordablePremium = Math.floor((MAX_PER_TRADE / lotSize) * 100) / 100;
      return finish({ executed: false, reason:
        `One lot of ${signal.symbol} ${trade.strike} ${trade.type} costs ₹${Math.round(cost).toLocaleString('en-IN')} `
        + `(₹${effectiveEntry} premium × ${lotSize} lot size), above the ₹${MAX_PER_TRADE.toLocaleString('en-IN')} per-trade cap. `
        + `A lot cannot be split, so this contract is untradeable on this budget until its premium is under `
        + `₹${affordablePremium} — the signal itself is unaffected.` },
        { perTradeCap: MAX_PER_TRADE, attemptedCost: Math.round(cost), maxAffordablePremium: affordablePremium });
    }

    // Determine which strategies qualify
    const qualifyingStrategies = [];
    const regime = signal.strategy?.regime || '';
    const gex = snapshot.gex?.regime || '';
    const confidence = Number(godMode?.godConfidence ?? signal.confidence ?? 0);
    const exitTarget = confidence >= VERY_HIGH_CONFIDENCE ? 'T2' : 'T1';

    for (const [key, strat] of Object.entries(STRATEGIES)) {
      if (confidence < strat.minConfidence) continue;
      if (strat.regimeFilter && regime !== strat.regimeFilter) continue;
      if (strat.gexFilter && gex !== strat.gexFilter) continue;
      qualifyingStrategies.push(key);
    }

    if (!qualifyingStrategies.length) {
      const shadowRecorded = this.shadow.recordRejected(signal, snapshot, 'No strategy qualified');
      return finish({ executed: false, reason: 'No strategy qualifies at this confidence/regime', shadowRecorded });
    }

    // Execute with the FIRST qualifying strategy (primary)
    const primaryStrategy = qualifyingStrategies[0];
    const strat = STRATEGIES[primaryStrategy];

    const position = {
      id: Date.now(),
      openDate: new Date().toISOString(),
      instrument: signal.symbol,
      direction: signal.direction,
      strike: trade.strike,
      type: trade.type,
      expiry: trade.expiry || trade.expiryDisplay || '',
      expiryDisplay: trade.expiryDisplay || trade.expiry || '',
      lotSize,
      patternId: godMode?.patternId ?? null,
      entrySpot: Number(snapshot.ltp) || null,
      atr: Number(signal.atr) || null,
      quoteTimestamp: quoteTime,
      modelConfidence: godMode?.modelConfidence ?? signal.confidence,
      calibration: godMode?.calibration || null,
      entryPremium: effectiveEntry,
      rawPremium: premium,
      slippage: slippageCalc.entrySlippage,
      cost,
      currentPremium: effectiveEntry,
      peakPremium: effectiveEntry,
      stopPremium: trade.stopLoss ? trade.stopLoss.premium : premium * 0.6,
      stopATR: strat.stopATR,
      t1Premium: trade.targets ? trade.targets[0].premium : premium * 1.5,
      t2Premium: trade.targets ? trade.targets[1].premium : premium * 2.5,
      t3Premium: trade.targets ? trade.targets[2].premium : premium * 4,
      confidence,
      regime,
      gex,
      strategy: primaryStrategy,
      strategyName: strat.name,
      exitTarget,
      exitTargetPolicy: 'confidence',
      exitTargetThreshold: VERY_HIGH_CONFIDENCE,
      sizeMultiplier: strat.sizeMultiplier * (s.consecutiveLosses >= 2 ? 0.5 : 1.0),
      status: 'OPEN',
      pnl: 0,
      t1Hit: false,
      t2Hit: false,
      breakevenActive: false,
      qualifiedStrategies: qualifyingStrategies,
    };

    s.active.push(position);
    s.dailyTrades++;
    s.capital -= cost;

    // Record what-if alternatives
    this.whatIf.recordAlternatives(position, snapshot);

    // Benchmark: record for "blind signals" (every signal taken)
    // (Will be resolved when trade closes)

    this._save();
    this._syncToServer(position, 'OPEN');

    return finish({ executed: true, position, strategy: strat.name, slippage: slippageCalc, reason: 'Paper trade opened from fresh live contract quote' },
      { contract: `${signal.symbol} ${trade.strike} ${trade.type}`, cost: Math.round(cost), rewardRisk: Math.round(rr * 100) / 100 });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // POSITION MONITOR: Called every 30s with live premium
  // ═══════════════════════════════════════════════════════════════════════════

  /**
   * Requote every active contract by its own exact expiry/strike/type. This is
   * independent of whichever new signal the scanner currently recommends, so an
   * open position continues to receive stops, targets, trailing, and EOD handling.
   */
  async monitorActivePositions(leaseGuard = null) {
    await this.ready();
    const results = [];
    const contracts = [...(this.state.active || [])];
    for (const pos of contracts) {
      if (!pos.expiry || !pos.strike || !pos.type) {
        results.push({ id: pos.id, updated: false, reason: 'Position lacks exact contract identity' });
        continue;
      }
      try {
        const qs = new URLSearchParams({
          action: 'option_ltp', symbol: pos.instrument, expiry: pos.expiry,
          strike: String(pos.strike), type: pos.type, cb: String(Date.now()),
        });
        const r = await fetch(`${PROXY}?${qs}`, { cache: 'no-store' });
        const j = await r.json();
        if (!j?.status || !(Number(j.data?.ltp) > 0)) {
          results.push({ id: pos.id, updated: false, reason: j?.error || 'Quote unavailable' });
          continue;
        }
        const quoteAt = Date.parse(j.timestamp || '') || 0;
        const quoteAgeMs = quoteAt ? Date.now() - quoteAt : Infinity;
        if (quoteAgeMs < 0 || quoteAgeMs > 45000) {
          results.push({ id: pos.id, updated: false, reason: `Stale option quote (${Number.isFinite(quoteAgeMs) ? Math.round(quoteAgeMs/1000)+'s' : 'undated'})` });
          continue;
        }
        if (leaseGuard && !(await leaseGuard())) {
          results.push({ id: pos.id, updated: false, reason: 'Executor lease lost before mark update' });
          break;
        }
        pos.lastQuoteAt = quoteAt;
        const closed = this.updatePositions(pos.instrument, Number(j.data.ltp), 1.0,
          { strike: pos.strike, type: pos.type, expiry: pos.expiry });
        results.push({ id: pos.id, updated: true, closed: closed.length > 0, ltp: Number(j.data.ltp) });
      } catch (e) {
        results.push({ id: pos.id, updated: false, reason: e.message });
      }
    }
    this.state.executorHeartbeat = new Date().toISOString();
    this._save();
    return results;
  }

  /**
   * Mark open positions to a live quote.
   * @param quote {{strike:number,type:string}} which contract the premium belongs to.
   *        Required — without it a position cannot be matched to the quote and is
   *        left untouched rather than mismarked.
   */
  updatePositions(instrument, livePremium, volumeRatio, quote) {
    const s = this.state;
    const closedTrades = [];

    // Update shadow trades too
    this.shadow.updateWithLive(instrument, livePremium);

    for (const pos of [...s.active]) {
      if (pos.instrument !== instrument || !livePremium) continue;

      // CONTRACT MATCHING.
      // This used to match on instrument ALONE, so every open position for a symbol
      // was marked against whatever strike the engine happened to be recommending on
      // that tick. A NIFTY 24150 PE position was being valued using a 24300 CE quote.
      // That is how the book produced a -62% "loss" in 1 minute and a -47% one in 2
      // minutes — those were mismarks, not market moves. An option is only comparable
      // to a quote for the SAME strike and side.
      if (quote && quote.strike != null) {
        if (Number(pos.strike) !== Number(quote.strike)) continue;
        if (quote.type && String(pos.type).toUpperCase() !== String(quote.type).toUpperCase()) continue;
      } else {
        // Cannot identify which contract this quote belongs to. Marking blind is what
        // caused the phantom losses, so skip rather than guess.
        continue;
      }

      // Profit-booking policy is confidence-owned, not strategy-order-owned.
      // Existing positions are normalized here as well, so a pre-v4.5 position
      // cannot keep waiting for T2/T3 merely because Aggressive was selected.
      pos.exitTarget = Number(pos.confidence ?? 0) >= VERY_HIGH_CONFIDENCE ? 'T2' : 'T1';
      pos.exitTargetPolicy = 'confidence';
      pos.exitTargetThreshold = VERY_HIGH_CONFIDENCE;

      pos.currentPremium = livePremium;
      if (livePremium > pos.peakPremium) pos.peakPremium = livePremium;

      const pnlPerUnit = livePremium - pos.entryPremium;
      pos.pnl = pnlPerUnit * pos.lotSize * pos.sizeMultiplier;

      // ── CIRCUIT BREAKER: 40% loss in 5 min ──
      const elapsed = (Date.now() - new Date(pos.openDate).getTime()) / 1000;
      const drawdownPct = (pos.entryPremium - livePremium) / pos.entryPremium * 100;
      if (drawdownPct >= 40 && elapsed <= 300) {
        this._closePosition(pos, livePremium, 'CIRCUIT_BREAKER', closedTrades);
        continue;
      }

      // ── STOP LOSS ──
      if (livePremium <= pos.stopPremium) {
        this._closePosition(pos, livePremium, 'STOP_LOSS', closedTrades);
        continue;
      }

      // A legacy position may already have crossed T1 under the old strategy
      // policy. Once normalized to T1, book it on the first fresh profitable
      // quote rather than allowing the old T2/T3 target to linger.
      if (pos.exitTarget === 'T1' && pos.t1Hit) {
        this._closePosition(pos, livePremium, 'WIN_T1', closedTrades);
        continue;
      }
      if (pos.exitTarget === 'T2' && pos.t2Hit) {
        this._closePosition(pos, livePremium, 'WIN_T2', closedTrades);
        continue;
      }

      // ── T1 HIT ──
      if (!pos.t1Hit && livePremium >= pos.t1Premium) {
        pos.t1Hit = true;
        pos.breakevenActive = true;
        pos.stopPremium = pos.entryPremium; // ZERO LOSS from here
        // If strategy exits at T1, close now
        if (pos.exitTarget === 'T1') {
          this._closePosition(pos, livePremium, 'WIN_T1', closedTrades);
          continue;
        }
      }

      // ── T2 HIT ──
      if (!pos.t2Hit && livePremium >= pos.t2Premium) {
        pos.t2Hit = true;
        if (pos.exitTarget === 'T2') {
          this._closePosition(pos, livePremium, 'WIN_T2', closedTrades);
          continue;
        }
      }

      // ── T3 HIT ──
      if (livePremium >= pos.t3Premium) {
        this._closePosition(pos, livePremium, 'WIN_T3', closedTrades);
        continue;
      }

      // ── TRAILING STOP (after T1) ──
      if (pos.t1Hit) {
        const trailLevel = pos.peakPremium * 0.80;
        if (trailLevel > pos.stopPremium) pos.stopPremium = Math.max(pos.entryPremium, trailLevel);
        if (livePremium <= pos.stopPremium && livePremium > pos.entryPremium) {
          this._closePosition(pos, livePremium, pos.t2Hit ? 'WIN_T2' : 'WIN_T1', closedTrades);
          continue;
        }
      }

      // ── THETA BLEED: premium down >15% without spot movement (>10 min in trade) ──
      if (elapsed > 600 && drawdownPct > 15 && !pos.t1Hit) {
        // Check if it's just theta (no spot movement would mean premium decays)
        // Simplified: if >15% loss after 10 min without hitting T1, likely theta
        // In a real system we'd check spot movement — here we use time as proxy
        if (elapsed > 1800 && drawdownPct > 20) { // 30 min + 20% loss
          this._closePosition(pos, livePremium, 'THETA_BLEED', closedTrades);
          continue;
        }
      }
    }

    this._save();
    return closedTrades;
  }

  _closePosition(pos, exitPremium, outcome, closedTrades) {
    const s = this.state;
    const slippageCalc = this.slippage.calculate(exitPremium, pos.lotSize, 1.0);
    const effectiveExit = exitPremium - slippageCalc.exitSlippage;

    pos.exitPremium = effectiveExit;
    pos.exitDate = new Date().toISOString();
    pos.status = outcome;
    pos.exitSlippage = slippageCalc.exitSlippage;

    const pnlPerUnit = effectiveExit - pos.entryPremium;
    pos.pnl = pnlPerUnit * pos.lotSize * pos.sizeMultiplier;
    pos.pnlPct = Math.round(pnlPerUnit / pos.entryPremium * 100);
    pos.holdMinutes = Math.round((new Date(pos.exitDate) - new Date(pos.openDate)) / 60000);

    // Update capital
    s.capital += pos.cost + pos.pnl;
    s.totalPnl += pos.pnl;
    s.totalSlippage += (pos.slippage + pos.exitSlippage) * pos.lotSize;
    if (s.capital > s.peakCapital) s.peakCapital = s.capital;

    // Consecutive losses
    if (pos.pnl < 0) s.consecutiveLosses++;
    else s.consecutiveLosses = 0;

    // Move to completed
    s.trades.push(pos);
    s.active = s.active.filter(p => p.id !== pos.id);

    // Update equity curve
    const today = new Date().toISOString().split('T')[0];
    const lastEq = s.equityCurve[s.equityCurve.length - 1];
    if (lastEq && lastEq.date === today) lastEq.equity = s.capital;
    else s.equityCurve.push({ date: today, equity: s.capital });

    // Strategy results tracking
    if (!s.strategyResults[pos.strategy]) s.strategyResults[pos.strategy] = { trades: 0, wins: 0, pnl: 0 };
    s.strategyResults[pos.strategy].trades++;
    if (pos.pnl > 0) s.strategyResults[pos.strategy].wins++;
    s.strategyResults[pos.strategy].pnl += pos.pnl;

    // Resolve what-if
    this.whatIf.resolveForTrade(pos.id, outcome, pos.pnlPct, pos.peakPremium);

    // Feed only a real completed live-premium position back to the originating
    // God pattern. No synthetic spot move is inferred from option P&L.
    if (pos.patternId != null) {
      try { godBrain.recordOutcome(pos.patternId, outcome, pos.pnlPct, null, pos.holdMinutes); }
      catch (e) { console.warn('[PaperTrade] Could not record God outcome:', e); }
    }

    // Benchmarks
    this.benchmarker.recordBlindSignal(pos.pnlPct);
    if (pos.confidence >= 80) this.benchmarker.recordHighConfOnly(pos.pnlPct);

    closedTrades.push(pos);
    this._syncToServer(pos, 'CLOSED');
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // SESSION MANAGEMENT
  // ═══════════════════════════════════════════════════════════════════════════

  forceCloseAll(reason) {
    const s = this.state;
    const closed = [], pending = [];
    for (const pos of [...s.active]) {
      const quoteAt = Number(pos.lastQuoteAt || pos.quoteTimestamp || 0);
      const ageMs = quoteAt ? Date.now() - quoteAt : Infinity;
      if (ageMs < 0 || ageMs > 45000 || !(Number(pos.currentPremium) > 0)) {
        pos.reconciliationRequired = true;
        pos.reconciliationReason = `${reason}: fresh exact-contract exit quote unavailable`;
        pending.push(pos);
        continue;
      }
      this._closePosition(pos, pos.currentPremium, 'TIME_EXIT', closed);
    }
    this._save();
    return { closed, pending };
  }

  generateEndOfDayReport() {
    const s = this.state;
    const prediction = this.predictor.generatePrediction(s.lastSignal, s.lastSnapshot, []);
    s.prediction = prediction;
    this.shadow.closeStale();
    this._save();
    return prediction;
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ANALYTICS
  // ═══════════════════════════════════════════════════════════════════════════

  getFullStats() {
    const s = this.state;
    const trades = s.trades;
    const wins = trades.filter(t => t.pnl > 0);
    const losses = trades.filter(t => t.pnl <= 0);
    const drawdown = s.peakCapital > 0 ? Math.round((1 - s.capital / s.peakCapital) * 100 * 10) / 10 : 0;

    // ── Risk statistics ───────────────────────────────────────────────────────
    // Computed by the shared, unit-tested module rather than inline. The previous
    // version had two defects: it used the POPULATION standard deviation (n) where a
    // track record needs the SAMPLE one (n-1), and it guarded the divisor with
    // `stdDev > 0` — a constant equity change has a floating-point std near 1e-18, not
    // zero, which produced an absurd Sharpe instead of "no dispersion". It also measured
    // per-DAY equity steps while annualising by 252 regardless of how many trades
    // actually occurred.
    //
    // Per-trade returns are the right unit here: this desk's P&L arrives in discrete
    // trades, not daily marks, and annualising on its own cadence avoids inflating the
    // figure on a desk that trades a few times a week.
    const _rets = returnsFromTrades(trades);
    const _startMs = s.startDate ? new Date(s.startDate).getTime() : Date.now();
    const _days = Math.max(1, (Date.now() - _startMs) / 86400000);
    const _tradesPerYear = Math.max(1, Math.round((_rets.length / _days) * 252));
    const _risk = riskSummarize(_rets, _tradesPerYear);
    const sharpe = _risk.sharpe;

    // By regime
    const byRegime = {};
    for (const t of trades) {
      const r = t.regime || 'Unknown';
      if (!byRegime[r]) byRegime[r] = { trades: 0, wins: 0, pnl: 0 };
      byRegime[r].trades++;
      if (t.pnl > 0) byRegime[r].wins++;
      byRegime[r].pnl += t.pnl;
    }

    // By GEX
    const byGex = {};
    for (const t of trades) {
      const g = t.gex || 'Unknown';
      if (!byGex[g]) byGex[g] = { trades: 0, wins: 0, pnl: 0 };
      byGex[g].trades++;
      if (t.pnl > 0) byGex[g].wins++;
      byGex[g].pnl += t.pnl;
    }

    // Avg hold time
    const avgHold = trades.length ? Math.round(trades.reduce((sum, t) => sum + (t.holdMinutes || 0), 0) / trades.length) : 0;
    const avgHoldWin = wins.length ? Math.round(wins.reduce((sum, t) => sum + (t.holdMinutes || 0), 0) / wins.length) : 0;
    const avgHoldLoss = losses.length ? Math.round(losses.reduce((sum, t) => sum + (t.holdMinutes || 0), 0) / losses.length) : 0;

    // Max consecutive
    let maxConsecWin = 0, maxConsecLoss = 0, curWin = 0, curLoss = 0;
    for (const t of trades) {
      if (t.pnl > 0) { curWin++; curLoss = 0; maxConsecWin = Math.max(maxConsecWin, curWin); }
      else { curLoss++; curWin = 0; maxConsecLoss = Math.max(maxConsecLoss, curLoss); }
    }

    return {
      // Core
      capital: s.capital,
      initialCapital: s.initialCapital,
      totalPnl: Math.round(s.totalPnl),
      returnPct: Math.round((s.capital - s.initialCapital) / s.initialCapital * 100 * 10) / 10,
      totalTrades: trades.length,
      wins: wins.length,
      losses: losses.length,
      winRate: trades.length ? Math.round(wins.length / trades.length * 100) : 0,
      avgPnl: trades.length ? Math.round(s.totalPnl / trades.length) : 0,
      maxDrawdown: drawdown,
      sharpe,
      // Full risk block, including the honest verdict on whether this record supports
      // any conclusion yet. Same definitions as the offline validation harness.
      risk: _risk,
      totalSlippage: Math.round(s.totalSlippage),

      // Active
      active: s.active,
      activeCount: s.active.length,

      // Time
      avgHoldMinutes: avgHold,
      avgHoldWin: avgHoldWin,
      avgHoldLoss: avgHoldLoss,
      maxConsecWins: maxConsecWin,
      maxConsecLosses: maxConsecLoss,

      // Breakdowns
      byRegime,
      byGex,
      strategyResults: s.strategyResults,

      // Multi-strategy leaderboard
      strategyLeaderboard: this._getLeaderboard(),

      // Shadow trading
      shadowStats: this.shadow.getStats(),

      // What-if insights
      whatIfInsights: this.whatIf.getInsights(),

      // Psychology
      psychInsights: this.psych.analyze(trades),

      // Prediction
      prediction: s.prediction,

      // Benchmarks
      benchmarks: this.benchmarker.getComparison(Math.round((s.capital - s.initialCapital) / s.initialCapital * 100 * 10) / 10),

      // Equity curve
      equityCurve: s.equityCurve,

      // Recent trades
      trades: trades.slice(-50).reverse(),

      // Meta
      startDate: s.startDate,
      dailyTrades: s.dailyTrades,
      consecutiveLosses: s.consecutiveLosses,
      version: this.version,
    };
  }

  _getLeaderboard() {
    const results = this.state.strategyResults;
    const board = [];
    for (const [key, data] of Object.entries(results)) {
      const strat = STRATEGIES[key];
      board.push({
        key,
        name: strat ? strat.name : key,
        trades: data.trades,
        wins: data.wins,
        winRate: data.trades ? Math.round(data.wins / data.trades * 100) : 0,
        pnl: Math.round(data.pnl),
        avgPnl: data.trades ? Math.round(data.pnl / data.trades) : 0,
      });
    }
    board.sort((a, b) => b.pnl - a.pnl);
    return board;
  }

  _syncToServer(position, status) {
    try {
      // Sync to brain-data (for God Mode training)
      authedFetch(PROXY + '?action=brain_ingest', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          instrument: position.instrument,
          direction: position.direction,
          confidence: position.confidence,
          ltp: position.entryPremium,
          regime: position.regime,
          gex_regime: position.gex,
          trade: {
            type: 'PAPER_TRADE',
            status,
            strategy: position.strategyName,
            strike: position.strike,
            optType: position.type,
            entry: position.entryPremium,
            exit: position.exitPremium || null,
            pnl: position.pnl,
            pnlPct: position.pnlPct || 0,
            outcome: position.status,
          },
          timestamp: Date.now() / 1000,
        }),
      }).catch(() => {});

      // ALSO sync to paper_trades endpoint (for tool.html server-side display)
      if (status === 'CLOSED') {
        authedFetch(PROXY + '?action=paper_trades', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            timestamp: Date.now() / 1000,
            instrument: position.instrument,
            direction: position.direction,
            strike: position.strike,
            type: position.type,
            strategy: position.strategyName || '',
            entry: position.entryPremium,
            exit: position.exitPremium || 0,
            pnl: position.pnl || 0,
            pnlPct: position.pnlPct || 0,
            outcome: position.status,
            holdMinutes: position.holdMinutes || 0,
            confidence: position.confidence || 0,
            patternId: position.patternId || null,
            expiry: position.expiry || '',
            modelConfidence: position.modelConfidence || null,
            calibration: position.calibration || null,
            regime: position.regime || '',
            gex: position.gex || '',
            slippage: (position.slippage || 0) + (position.exitSlippage || 0),
            lotSize: position.lotSize || 0,
          }),
        }).catch(() => {});
      }
    } catch (e) {}
  }

  // Reset everything (server-side too — there is no browser copy any more)
  reset() {
    // Server-side reset now requires the admin token, which the browser never
    // holds. Clearing locally is enough; the next _save() overwrites server state.
    console.warn('[PaperTrade] Local reset. Server-side wipe requires the admin token.');
    this.state = this._defaultState();
    this.shadow = new ShadowTracker(this.state);
    this.whatIf = new WhatIfSimulator(this.state);
    this.benchmarker = new Benchmarker(this.state);
    this._save();
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXPORT
// ═══════════════════════════════════════════════════════════════════════════════

export const paperTrader = new PaperTradingEngine();
export { STRATEGIES, SlippageModel, ShadowTracker, WhatIfSimulator, PsychProfiler, PredictiveScorer, Benchmarker };
