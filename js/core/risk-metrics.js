/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * risk-metrics.js — honest performance statistics for the trading desks
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS EXISTS
 * The desks used to report a win rate and little else. A win rate cannot tell you
 * whether a strategy makes money — 80% winners with one oversized loser is a losing
 * book — and it cannot tell you whether a result is distinguishable from luck. Both
 * questions now get answered on screen.
 *
 * These formulas are a deliberate mirror of brain/validation/metrics.py in the
 * SignalsBrain repo, which is unit-tested. Keeping one definition of Sharpe across the
 * research harness and the live UI means a number quoted in one place means the same
 * thing in the other; two "Sharpe ratios" that disagree is worse than having none.
 *
 * Two bugs in the previous inline implementation are fixed here:
 *   1. It divided by a standard deviation checked only for `> 0`. A constant return
 *      series (a run of identical stop-outs) has a floating-point std around 1e-18,
 *      not 0, which produced an astronomically large Sharpe. A relative tolerance is
 *      used instead.
 *   2. It used the population standard deviation (÷n). Sample standard deviation
 *      (÷n-1) is correct for a track record, which is a sample of possible outcomes.
 */

// Relative tolerance below which a dispersion counts as zero.
const ZERO_TOL = 1e-12;

/** Trading-period constants for Indian index F&O (375-minute session). */
export const TRADING_DAYS_PER_YEAR = 252;

function clean(xs) {
  if (!Array.isArray(xs)) return [];
  return xs.map(Number).filter(v => Number.isFinite(v));
}

function mean(a) { return a.length ? a.reduce((s, x) => s + x, 0) / a.length : 0; }

/** Sample standard deviation (n-1), the correct choice for a track record. */
function stdev(a) {
  if (a.length < 2) return 0;
  const m = mean(a);
  return Math.sqrt(a.reduce((s, x) => s + (x - m) ** 2, 0) / (a.length - 1));
}

function negligible(sd, a) {
  return sd <= ZERO_TOL * Math.max(1, mean(a.map(Math.abs)));
}

/**
 * Mean return over its standard deviation, scaled to a year.
 * `periodsPerYear` must match the frequency of `returns` — for per-TRADE returns pass
 * an estimate of trades per year, not 252, or the figure is inflated.
 */
export function sharpe(returns, periodsPerYear = TRADING_DAYS_PER_YEAR) {
  const a = clean(returns);
  if (a.length < 2) return 0;
  const sd = stdev(a);
  if (negligible(sd, a)) return 0;
  return (mean(a) / sd) * Math.sqrt(periodsPerYear);
}

/**
 * Sharpe that punishes only downside deviation. The better headline for long options,
 * whose payoff is deliberately right-skewed — plain Sharpe treats the big winners you
 * are paying premium for as "risk".
 */
export function sortino(returns, periodsPerYear = TRADING_DAYS_PER_YEAR) {
  const a = clean(returns);
  if (a.length < 2) return 0;
  const down = a.filter(x => x < 0);
  if (!down.length) return mean(a) > 0 ? Infinity : 0;
  const dd = Math.sqrt(down.reduce((s, x) => s + x * x, 0) / down.length);
  if (negligible(dd, a)) return 0;
  return (mean(a) / dd) * Math.sqrt(periodsPerYear);
}

/** Deepest peak-to-trough fall of the compounded curve, as a fraction. */
export function maxDrawdown(returns) {
  const a = clean(returns);
  if (!a.length) return 0;
  let eq = 1, peak = 1, worst = 0;
  for (const r of a) {
    eq *= (1 + r);
    if (eq > peak) peak = eq;
    const dd = (eq - peak) / peak;
    if (dd < worst) worst = dd;
  }
  return -worst;
}

/** Gross win / gross loss. Below 1.0 the strategy loses money, whatever the win rate. */
export function profitFactor(returns) {
  const a = clean(returns);
  const g = a.filter(x => x > 0).reduce((s, x) => s + x, 0);
  const l = -a.filter(x => x < 0).reduce((s, x) => s + x, 0);
  if (l === 0) return g > 0 ? Infinity : 0;
  return g / l;
}

/** Average return per trade — the only number that actually compounds. */
export function expectancy(returns) { return mean(clean(returns)); }

export function winRate(returns) {
  const a = clean(returns);
  return a.length ? a.filter(x => x > 0).length / a.length : 0;
}

/**
 * One-sample t-statistic against zero mean, plus a normal-approximation p-value.
 * With a track record this short the t-stat is the honest way to say "this could
 * easily be noise" without pretending to more precision than the sample supports.
 */
export function tStat(returns) {
  const a = clean(returns);
  if (a.length < 2) return 0;
  const sd = stdev(a);
  if (negligible(sd, a)) return 0;
  return mean(a) / (sd / Math.sqrt(a.length));
}

/** Two-sided p-value via a normal approximation (adequate above ~30 observations). */
export function pValue(returns) {
  const t = Math.abs(tStat(returns));
  if (!t) return 1;
  // Abramowitz & Stegun 7.1.26 approximation of erf.
  const x = t / Math.SQRT2;
  const s = x < 0 ? -1 : 1, ax = Math.abs(x);
  const p = 0.3275911, a1 = 0.254829592, a2 = -0.284496736,
        a3 = 1.421413741, a4 = -1.453152027, a5 = 1.061405429;
  const tt = 1 / (1 + p * ax);
  const erf = s * (1 - ((((a5 * tt + a4) * tt + a3) * tt + a2) * tt + a1) * tt * Math.exp(-ax * ax));
  return Math.max(0, Math.min(1, 1 - erf));
}

/**
 * Plain-English reading, deliberately conservative.
 *
 * Mirrors the verdict logic in the Python harness. The wording never implies an edge
 * the statistics do not support: an option-buying desk needs a real edge to beat theta,
 * and a flattering label on a 12-trade sample is how a losing system keeps getting
 * funded. MIN_TRADES is set where a t-test starts to mean anything at all.
 */
export const MIN_TRADES_FOR_JUDGEMENT = 30;

export function verdict(returns) {
  const a = clean(returns);
  if (!a.length) return { level: 'none', text: 'No closed trades yet.' };
  const exp = expectancy(a), pf = profitFactor(a), t = tStat(a), p = pValue(a);

  if (a.length < MIN_TRADES_FOR_JUDGEMENT) {
    return {
      level: 'inconclusive',
      text: `Only ${a.length} closed trade${a.length === 1 ? '' : 's'} — too few to judge. `
          + `At least ${MIN_TRADES_FOR_JUDGEMENT} are needed before any of these figures mean anything.`,
    };
  }
  if (exp <= 0) {
    return {
      level: 'losing',
      text: `Negative expectancy: ${(exp * 100).toFixed(2)}% per trade (profit factor ${pf.toFixed(2)}). `
          + `No win rate fixes this — the average trade loses money.`,
    };
  }
  if (p > 0.05) {
    return {
      level: 'unproven',
      text: `Positive expectancy (${(exp * 100).toFixed(2)}%/trade) but not statistically significant `
          + `(t=${t.toFixed(2)}, p=${p.toFixed(3)}). Could still be luck — keep it on paper.`,
    };
  }
  return {
    level: 'supported',
    text: `Positive expectancy ${(exp * 100).toFixed(2)}%/trade, profit factor ${pf.toFixed(2)}, `
        + `t=${t.toFixed(2)} (p=${p.toFixed(3)}). Statistically meaningful on this sample.`,
  };
}

/**
 * Everything at once.
 *
 * `returns` are per-trade fractional returns. `tradesPerYear` should reflect the desk's
 * actual trading cadence; passing 252 for a desk that takes two trades a week overstates
 * the annualised figures by roughly eight times.
 */
export function summarize(returns, tradesPerYear = TRADING_DAYS_PER_YEAR) {
  const a = clean(returns);
  const wins = a.filter(x => x > 0), losses = a.filter(x => x < 0);
  const pf = profitFactor(a);
  return {
    n: a.length,
    winRate: Math.round(winRate(a) * 1000) / 10,
    avgWin: wins.length ? Math.round(mean(wins) * 1000) / 10 : 0,
    avgLoss: losses.length ? Math.round(mean(losses) * 1000) / 10 : 0,
    expectancyPct: Math.round(expectancy(a) * 10000) / 100,
    profitFactor: Number.isFinite(pf) ? Math.round(pf * 100) / 100 : Infinity,
    sharpe: Math.round(sharpe(a, tradesPerYear) * 100) / 100,
    sortino: Number.isFinite(sortino(a, tradesPerYear))
      ? Math.round(sortino(a, tradesPerYear) * 100) / 100 : Infinity,
    maxDrawdownPct: Math.round(maxDrawdown(a) * 1000) / 10,
    tStat: Math.round(tStat(a) * 100) / 100,
    pValue: Math.round(pValue(a) * 1000) / 1000,
    verdict: verdict(a),
  };
}

/** Per-trade fractional returns from a desk's closed-trade records. */
export function returnsFromTrades(trades) {
  if (!Array.isArray(trades)) return [];
  return trades.map(t => {
    // Prefer an explicit percentage when the desk recorded one.
    if (Number.isFinite(Number(t.pnlPct))) return Number(t.pnlPct) / 100;
    // Otherwise derive it from P&L against the capital actually committed.
    const cost = Number(t.cost) || (Number(t.entryPremium) * Number(t.qty || t.lotSize || 0));
    const pnl = Number(t.pnl);
    return (Number.isFinite(pnl) && cost > 0) ? pnl / cost : NaN;
  }).filter(Number.isFinite);
}
