/**
 * live-engine.js — Consolidated live trading engine (fresh filename, cache-safe)
 * Indicators + Signal Engine + Angel One proxy data layer, all in one module.
 */

const PROXY = '/signals/api/proxy.php';

// ─── Config ──────────────────────────────────────────────────────────────────
export const STRIKE_STEPS = {
  NIFTY: 50, BANKNIFTY: 100, FINNIFTY: 50, SENSEX: 100, MIDCPNIFTY: 25,
  RELIANCE: 10, HDFCBANK: 5, ICICIBANK: 10,
};
// Fallbacks only. signal.html replaces these from the official scrip master for
// the actual expiry before constructing an executable contract.
export const LOT_SIZES = {
  NIFTY: 65, BANKNIFTY: 30, FINNIFTY: 60, SENSEX: 20, MIDCPNIFTY: 120,
  RELIANCE: 500, HDFCBANK: 650, ICICIBANK: 700, INFY: 400, TCS: 175,
  SBIN: 750, BHARTIARTL: 475, ITC: 1600, KOTAKBANK: 400, LT: 150,
  AXISBANK: 625, TATAMOTORS: 550, MARUTI: 50, WIPRO: 3000,
  HCLTECH: 350, ADANIENT: 300, BAJFINANCE: 750, TITAN: 175,
};
export const INSTRUMENTS = [
  'NIFTY', 'BANKNIFTY', 'FINNIFTY', 'MIDCPNIFTY',
  'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN',
  'BHARTIARTL', 'ITC', 'KOTAKBANK', 'LT', 'AXISBANK', 'TATAMOTORS',
  'MARUTI', 'WIPRO', 'HCLTECH', 'ADANIENT', 'BAJFINANCE', 'TITAN',
];
const FNO_INDICES = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY'];
const RISK_FREE = 0.065;

// ─── Fetch helpers ─────────────────────────────────────────────────────────
async function fetchTimeout(url, ms = 12000) {
  const c = new AbortController();
  const t = setTimeout(() => c.abort(), ms);
  try {
    const r = await fetch(url, { signal: c.signal });
    clearTimeout(t);
    return r;
  } catch (e) { clearTimeout(t); throw e; }
}

export async function getIndices() {
  try {
    const r = await fetchTimeout(`${PROXY}?action=indices`);
    const j = await r.json();
    return (j.status && j.data) ? j.data : null;
  } catch (e) { return null; }
}

export async function getQuotes(symbols) {
  try {
    const r = await fetchTimeout(`${PROXY}?action=quote&symbols=${symbols.join(',')}`);
    const j = await r.json();
    return (j.status && j.data) ? j.data : null;
  } catch (e) { return null; }
}

export async function getCandles(symbol, interval = 'FIFTEEN_MINUTE', days = 10) {
  const to = new Date().toISOString().split('T')[0];
  const from = new Date(Date.now() - days * 86400000).toISOString().split('T')[0];
  try {
    const r = await fetchTimeout(`${PROXY}?action=candles&symbol=${encodeURIComponent(symbol)}&interval=${interval}&from=${from}&to=${to}`, 15000);
    const j = await r.json();
    if (!j.status || !j.data || !j.data.count) return null;
    return j.data; // {opens, highs, lows, closes, volumes, timestamps, count}
  } catch (e) { return null; }
}

// ─── Indicators ──────────────────────────────────────────────────────────────
export function SMA(v, p) { if (!v || v.length < p) return null; return v.slice(-p).reduce((s, x) => s + x, 0) / p; }

export function emaSeries(v, p) {
  if (!v || v.length < p) return [];
  const k = 2 / (p + 1), out = new Array(v.length).fill(null);
  let e = v.slice(0, p).reduce((s, x) => s + x, 0) / p;
  out[p - 1] = e;
  for (let i = p; i < v.length; i++) { e = (v[i] - e) * k + e; out[i] = e; }
  return out;
}
export function EMA(v, p) { const s = emaSeries(v, p); return s.length ? s[s.length - 1] : null; }

export function RSI(c, p = 14) {
  if (!c || c.length < p + 1) return { latest: null, prev: null };
  let g = 0, l = 0;
  for (let i = 1; i <= p; i++) { const d = c[i] - c[i - 1]; if (d > 0) g += d; else l -= d; }
  let ag = g / p, al = l / p, prev = null, latest = null;
  for (let i = p + 1; i < c.length; i++) {
    const d = c[i] - c[i - 1];
    ag = (ag * (p - 1) + (d > 0 ? d : 0)) / p;
    al = (al * (p - 1) + (d < 0 ? -d : 0)) / p;
    prev = latest;
    latest = al === 0 ? 100 : 100 - 100 / (1 + ag / al);
  }
  if (latest === null) { const rs = al === 0 ? 100 : ag / al; latest = 100 - 100 / (1 + rs); }
  return { latest, prev };
}

export function MACD(c, f = 12, s = 26, sig = 9) {
  if (!c || c.length < s + sig) return { macd: null, signal: null, hist: null };
  const ef = emaSeries(c, f), es = emaSeries(c, s), line = [];
  for (let i = 0; i < c.length; i++) if (ef[i] !== null && es[i] !== null) line.push(ef[i] - es[i]);
  if (line.length < sig) return { macd: null, signal: null, hist: null };
  const sl = emaSeries(line, sig);
  const m = line[line.length - 1], sg = sl[sl.length - 1];
  return { macd: m, signal: sg, hist: sg !== null ? m - sg : null };
}

export function ATR(h, l, c, p = 14) {
  const n = h.length; if (n < p + 1) return null;
  const tr = [];
  for (let i = 1; i < n; i++) tr.push(Math.max(h[i] - l[i], Math.abs(h[i] - c[i - 1]), Math.abs(l[i] - c[i - 1])));
  if (tr.length < p) return null;
  let a = tr.slice(0, p).reduce((s, x) => s + x, 0) / p;
  for (let i = p; i < tr.length; i++) a = (a * (p - 1) + tr[i]) / p;
  return a;
}

/**
 * ATR with a safe fallback, for use in the scoring block which runs before the
 * main ATR is computed. Returns a 1.2%-of-price proxy if ATR is unavailable, so
 * distance-scaled terms never divide by zero or NaN.
 */
export function atrProxy(h, l, c, ltp) {
  const a = ATR(h, l, c);
  if (a && isFinite(a) && a > 0) return a;
  return (ltp && isFinite(ltp)) ? ltp * 0.012 : 0;
}

export function bollinger(c, p = 20, sd = 2) {
  if (!c || c.length < p) return { upper: null, mid: null, lower: null };
  const s = c.slice(-p), mid = s.reduce((a, b) => a + b, 0) / p;
  const v = s.reduce((a, b) => a + (b - mid) ** 2, 0) / p, std = Math.sqrt(v);
  return { upper: mid + sd * std, mid, lower: mid - sd * std };
}

export function superTrend(h, l, c, p = 10, m = 3) {
  const n = c.length; if (n < p + 1) return 'bearish';
  const tr = [];
  for (let i = 1; i < n; i++) tr.push(Math.max(h[i] - l[i], Math.abs(h[i] - c[i - 1]), Math.abs(l[i] - c[i - 1])));
  const atr = new Array(tr.length).fill(0);
  let a = tr.slice(0, p).reduce((s, x) => s + x, 0) / p; atr[p - 1] = a;
  for (let i = p; i < tr.length; i++) { a = (a * (p - 1) + tr[i]) / p; atr[i] = a; }
  let dir = 1, pu = 0, pl = 0;
  for (let i = p; i < n; i++) {
    const av = atr[i - 1], hl = (h[i] + l[i]) / 2;
    const bu = hl + m * av, bl = hl - m * av;
    const u = (bu < pu || c[i - 1] > pu) ? bu : pu;
    const lo = (bl > pl || c[i - 1] < pl) ? bl : pl;
    if (dir === 1 && c[i] < lo) dir = -1;
    else if (dir === -1 && c[i] > u) dir = 1;
    pu = u; pl = lo;
  }
  return dir === 1 ? 'bullish' : 'bearish';
}

export function VWAP(h, l, c, v) {
  if (!h || !h.length) return null;
  let tpv = 0, tv = 0;
  for (let i = 0; i < h.length; i++) { const tp = (h[i] + l[i] + c[i]) / 3; tpv += tp * (v[i] || 1); tv += (v[i] || 1); }
  return tv ? tpv / tv : null;
}

// ─── Advanced: ADX (trend strength), Opening Range, Swing S/R ────────────────

/** ADX + Directional Indicators (Wilder). Distinguishes trend vs chop. */
export function ADX(h, l, c, p = 14) {
  const n = h.length;
  if (n < 2 * p + 1) return { adx: null, plusDI: null, minusDI: null };
  const tr = [], plusDM = [], minusDM = [];
  for (let i = 1; i < n; i++) {
    const up = h[i] - h[i - 1], down = l[i - 1] - l[i];
    plusDM.push(up > down && up > 0 ? up : 0);
    minusDM.push(down > up && down > 0 ? down : 0);
    tr.push(Math.max(h[i] - l[i], Math.abs(h[i] - c[i - 1]), Math.abs(l[i] - c[i - 1])));
  }
  const smooth = (arr) => {
    let s = arr.slice(0, p).reduce((a, b) => a + b, 0);
    const out = [s];
    for (let i = p; i < arr.length; i++) { s = s - s / p + arr[i]; out.push(s); }
    return out;
  };
  const str = smooth(tr), sp = smooth(plusDM), sm = smooth(minusDM);
  const dx = [];
  for (let i = 0; i < str.length; i++) {
    const pDI = 100 * sp[i] / (str[i] || 1), mDI = 100 * sm[i] / (str[i] || 1);
    dx.push(100 * Math.abs(pDI - mDI) / ((pDI + mDI) || 1));
  }
  if (dx.length < p) return { adx: null, plusDI: null, minusDI: null };
  let adx = dx.slice(0, p).reduce((a, b) => a + b, 0) / p;
  for (let i = p; i < dx.length; i++) adx = (adx * (p - 1) + dx[i]) / p;
  const li = str.length - 1;
  return { adx, plusDI: 100 * sp[li] / (str[li] || 1), minusDI: 100 * sm[li] / (str[li] || 1) };
}

/** Opening Range Breakout — uses the first candle of the latest session. */
export function openingRange(ts, h, l, c) {
  if (!ts || !ts.length) return null;
  const day = String(ts[ts.length - 1]).substring(0, 10);
  let first = -1;
  for (let i = 0; i < ts.length; i++) { if (String(ts[i]).substring(0, 10) === day) { first = i; break; } }
  if (first < 0) return null;
  const orH = h[first], orL = l[first], ltp = c[c.length - 1];
  let status = 'Inside range';
  if (ltp > orH) status = 'Breakout UP'; else if (ltp < orL) status = 'Breakdown DOWN';
  return { high: r2(orH), low: r2(orL), status };
}

/** Swing-based support/resistance (fractal pivots), nearest to price. */
export function swingSR(h, l, c, ltp, w = 3) {
  const n = h.length; const res = [], sup = [];
  for (let i = w; i < n - w; i++) {
    let isH = true, isL = true;
    for (let j = 1; j <= w; j++) {
      if (h[i] < h[i - j] || h[i] < h[i + j]) isH = false;
      if (l[i] > l[i - j] || l[i] > l[i + j]) isL = false;
    }
    if (isH) res.push(h[i]);
    if (isL) sup.push(l[i]);
  }
  const above = res.filter(x => x > ltp).sort((a, b) => a - b)[0] || null;
  const below = sup.filter(x => x < ltp).sort((a, b) => b - a)[0] || null;
  return { resistance: above ? r2(above) : null, support: below ? r2(below) : null };
}

function normCDF(x) {
  const a1 = 0.254829592, a2 = -0.284496736, a3 = 1.421413741, a4 = -1.453152027, a5 = 1.061405429, p = 0.3275911;
  const sg = x < 0 ? -1 : 1; x = Math.abs(x) / Math.sqrt(2);
  const t = 1 / (1 + p * x);
  const y = 1 - (((((a5 * t + a4) * t) + a3) * t + a2) * t + a1) * t * Math.exp(-x * x);
  return 0.5 * (1 + sg * y);
}
export function bsPrice(type, S, K, t, r, sig) {
  if (t <= 0 || sig <= 0) return type === 'CE' ? Math.max(0, S - K) : Math.max(0, K - S);
  const d1 = (Math.log(S / K) + (r + sig * sig / 2) * t) / (sig * Math.sqrt(t)), d2 = d1 - sig * Math.sqrt(t);
  return type === 'CE' ? S * normCDF(d1) - K * Math.exp(-r * t) * normCDF(d2) : K * Math.exp(-r * t) * normCDF(-d2) - S * normCDF(-d1);
}
export function bsDelta(type, S, K, t, r, sig) {
  if (t <= 0 || sig <= 0) return type === 'CE' ? (S > K ? 1 : 0) : (S < K ? -1 : 0);
  const d1 = (Math.log(S / K) + (r + sig * sig / 2) * t) / (sig * Math.sqrt(t));
  return type === 'CE' ? normCDF(d1) : normCDF(d1) - 1;
}

// ─── Signal Engine ─────────────────────────────────────────────────────────
function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

export function generateSignal(snapshot, opts = {}) {
  // MARKET HOURS GATE — don't generate signals outside 09:15-15:30 IST
  const _istCheck = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  const _dayCheck = _istCheck.getDay();
  const _minCheck = _istCheck.getHours() * 60 + _istCheck.getMinutes();
  if (_dayCheck === 0 || _dayCheck === 6 || _minCheck < 555 || _minCheck > 930) {
    // Same shape contract as every other return path. This one is built inline
    // rather than via noTrade(), and it was also missing `probabilities` — a lock
    // armed at 15:28 is still valid at 15:31, so the lock would restore a direction
    // onto THIS object and the renderer would read probabilities.hitT1 on undefined.
    return { symbol: snapshot.symbol, timestamp: new Date().toISOString(), direction: 'NO_TRADE',
      confidence: 0, netScore: 0,
      ltp: snapshot.closes ? snapshot.closes[snapshot.closes.length-1] : 0,
      components: null, strategy: null, vetoes: [], analytics: null, superTrend: null,
      trendLabel: 'Neutral',
      probabilities: { hitT1: 0, hitT2: 0, hitT3: 0, hitSL: 0 },
      reason: 'Market closed', verdict: '⏸️ Market closed — signals only during 09:15-15:30 IST Mon-Fri' };
  }

  const threshold = opts.confidenceThreshold || 70;
  const { symbol, opens, highs, lows, closes, volumes } = snapshot;
  const ts = new Date().toISOString();
  if (!closes || closes.length < 30) return noTrade(symbol, ts, 'Insufficient data');

  const ltp = closes[closes.length - 1];
  const len = closes.length;

  // Component scores
  const comp = {};

  // Trend (25)
  let tb = 0, tf = 0;
  const e9 = EMA(closes, 9), e21 = EMA(closes, 21), s50 = SMA(closes, 50);
  if (e9 !== null && e21 !== null) { tb += e9 > e21 ? 0.3 : -0.3; tf++; }
  if (s50 !== null) { tb += ltp > s50 ? 0.2 : -0.2; tf++; }
  const st = superTrend(highs, lows, closes);
  tb += st === 'bullish' ? 0.35 : -0.35; tf++;
  comp.trend = { bias: clamp(tb / (tf * 0.28), -1, 1), weight: 25 };

  // Price action (20)
  let pb = 0;
  const rc = (closes[len - 1] - closes[len - 5]) / closes[len - 5];
  if (rc > 0.004) pb += 0.35; else if (rc < -0.004) pb -= 0.35;
  if (len >= 20) {
    const h20 = Math.max(...highs.slice(-20)), l20 = Math.min(...lows.slice(-20)), rng = h20 - l20;
    if (rng > 0) { const pos = (ltp - l20) / rng; if (pos > 0.85) pb += 0.25; if (pos < 0.15) pb -= 0.25; }
  }
  comp.priceAction = { bias: clamp(pb, -1, 1), weight: 20 };

  // ── Options flow (20) ─────────────────────────────────────────────────────
  // The old version was a dead band: `pcr > 1.2 -> +0.4, pcr < 0.7 -> -0.4`, so
  // ANY reading between 0.70 and 1.20 contributed exactly 0. That is the normal
  // range for an index, so a 20-weight component — the joint-largest in the model —
  // sat at zero on most days (live MIDCPNIFTY: PCR 0.735 -> 0.0). It also ignored
  // the dealer-positioning data entirely, which is the most informative options
  // input we have. Now continuous, and it uses GEX only when the chain coverage
  // is good enough to trust (see the reliability gate below).
  let ob = 0;
  const _gexOk = snapshot.gex && (snapshot.gex.reliable === undefined || snapshot.gex.reliable === true
                                  || snapshot.gex.reliable === 1);
  const _g = _gexOk ? snapshot.gex : null;

  if (snapshot.pcr && snapshot.pcr > 0) {
    // Put-heavy (PCR>1) reads as support/bullish, call-heavy as resistance/bearish.
    // Continuous around 1.0 so a 0.9 and a 1.1 are no longer both "nothing".
    ob += clamp((snapshot.pcr - 1.0) * 1.1, -0.45, 0.45);
  } else if (snapshot.ivSkew != null) {
    // Positive skew (puts pricier) => bearish hedging; negative => bullish
    ob += clamp(-snapshot.ivSkew / 6, -0.5, 0.5);
  }

  if (_g) {
    // Which side of the zero-gamma flip are we on? Above it dealer hedging tends to
    // support the tape; below it, hedging accelerates declines. Scaled by distance
    // so sitting exactly on the flip is correctly treated as no information.
    if (_g.flip && atrProxy(highs, lows, closes, ltp) > 0) {
      const dATR = (ltp - _g.flip) / atrProxy(highs, lows, closes, ltp);
      ob += clamp(dATR * 0.12, -0.3, 0.3);
    }
    // Wall proximity: an approaching call wall caps upside, a put wall cushions.
    const a = atrProxy(highs, lows, closes, ltp);
    if (a > 0) {
      if (_g.callWall && Math.abs(_g.callWall - ltp) / a < 1.2 && ltp < _g.callWall) ob -= 0.2;
      if (_g.putWall  && Math.abs(ltp - _g.putWall)  / a < 1.2 && ltp > _g.putWall)  ob += 0.2;
    }
  }
  comp.options = { bias: clamp(ob, -1, 1), weight: 20 };

  // ── Volume (10) ───────────────────────────────────────────────────────────
  // NSE publishes NO traded volume for its indices — the feed returns 0 for every
  // candle. The old code still reached VWAP(), which internally substitutes
  // `v[i] || 1` and therefore returns a time-weighted average; comparing price to
  // that produced a ±0.3 bias, i.e. a ±3.0 score swing invented out of a fake
  // VWAP. Live MIDCPNIFTY was carrying a -3.0 volume penalty on no volume at all.
  // When there is no real volume the component is now declared unavailable, and its
  // weight is redistributed so the score stays on the same -100..100 scale.
  let vb = 0;
  const _volSum = (volumes && volumes.length) ? volumes.reduce((s, x) => s + (x || 0), 0) : 0;
  const _hasVolume = _volSum > 0;
  if (_hasVolume && volumes.length >= 10) {
    const av = volumes.slice(-20).reduce((s, x) => s + x, 0) / Math.min(20, volumes.length);
    const cv = volumes[len - 1];
    if (av > 0) { const vr = cv / av; if (vr > 1.5 && closes[len - 1] > closes[len - 2]) vb += 0.4; else if (vr > 1.5) vb -= 0.4; }
    const vw = VWAP(highs, lows, closes, volumes);
    if (vw) vb += ltp > vw ? 0.3 : -0.3;
  }
  comp.volume = { bias: clamp(vb, -1, 1), weight: _hasVolume ? 10 : 0, unavailable: !_hasVolume };

  // Momentum (10)
  let mb = 0;
  const rsi = RSI(closes);
  if (rsi.latest !== null) { if (rsi.latest > 60) mb += 0.35; else if (rsi.latest < 40) mb -= 0.35; }
  const macd = MACD(closes);
  if (macd.hist !== null) mb += macd.hist > 0 ? 0.35 : -0.35;
  comp.momentum = { bias: clamp(mb, -1, 1), weight: 10 };

  // Institutional (10) — from snapshot fiiFlow if available
  let ib = 0;
  if (snapshot.fiiFlow != null) { if (snapshot.fiiFlow > 0) ib += 0.4; else ib -= 0.4; }
  comp.institutional = { bias: clamp(ib, -1, 1), weight: 10 };

  // News (5)
  comp.news = { bias: snapshot.newsSentiment ? clamp(snapshot.newsSentiment, -1, 1) : 0, weight: 5 };

  // ── Net ───────────────────────────────────────────────────────────────────
  // Weights are renormalised to 100 so that a component being unavailable (index
  // volume) shrinks the model rather than silently rescaling every score.
  const _wSum = Object.values(comp).reduce((s, c) => s + c.weight, 0) || 1;
  const _norm = 100 / _wSum;
  let net = 0;
  for (const k in comp) net += comp[k].bias * comp[k].weight * _norm;
  net = clamp(net, -100, 100);

  // Agreement must be measured only across components that actually HAVE an
  // opinion. Math.sign(0) is 0, which never equals ±1, so the old version counted
  // every neutral component as active disagreement and pushed confidence down —
  // options sitting at 0 (see above) was silently costing confidence on top of
  // contributing nothing.
  const dom = net >= 0 ? 1 : -1;
  const opinions = Object.values(comp).filter(c => c.weight > 0 && Math.sign(c.bias) !== 0);
  const agree = opinions.length ? opinions.filter(c => Math.sign(c.bias) === dom).length / opinions.length : 0;

  let dir = net > 5 ? 'BUY' : net < -5 ? 'SELL' : 'NO_TRADE';
  let conf = Math.min(99, Math.abs(net) * 0.62 + agree * 42);
  let veto = null;
  const atrEarly = ATR(highs, lows, closes) || ltp * 0.012;

  // ── Advanced strategy layer: trend strength, regime, multi-timeframe, ORB ──
  const adxObj = ADX(highs, lows, closes);
  const adx = adxObj.adx;
  const regime = adx == null ? 'Unknown' : adx >= 25 ? 'Trending' : adx >= 18 ? 'Developing' : 'Choppy';
  // Trend strength modulates confidence: strong trend boosts, chop penalizes
  if (adx != null) {
    if (adx >= 28) conf = Math.min(99, conf + 10);
    else if (adx >= 22) conf = Math.min(99, conf + 5);
    else if (adx < 16) conf = Math.max(0, conf - 12);
  }
  // Multi-timeframe (higher TF) alignment
  if (snapshot.mtfBias) {
    const agreeMTF = (snapshot.mtfBias > 0 && dir === 'BUY') || (snapshot.mtfBias < 0 && dir === 'SELL');
    conf = clamp(conf + (agreeMTF ? 8 : -8), 0, 99);
  }
  // ══════════════════════════════════════════════════════════════════════════════
  // BLUNDER PREVENTION LAYER — 14 hard vetoes learned from real losses
  // These override the 7-factor score. Each can independently kill a signal.
  // ══════════════════════════════════════════════════════════════════════════════
  const vetoes = [];
  const ist = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  const timeMin = ist.getHours() * 60 + ist.getMinutes();
  const isExpDay = snapshot.dte != null && snapshot.dte <= 1.2;
  // GEX DATA-QUALITY GATE.
  // Every GEX figure (netGEX, regime, walls, max pain, PCR) is an OI-weighted sum
  // over the option chain. When only part of the chain resolves, those numbers are
  // not merely noisy, they are biased — yet they look perfectly normal. A live
  // NIFTY request was resolving 6 of 50 legs (12% of the chain) and the resulting
  // "Positive Gamma" / wall readings were still being used to veto real setups.
  // proxy.php now reports coverage; refuse to act on GEX unless it is reliable.
  // Absent flag = older cached payload, so fall back to the paired-strike count.
  let gex = snapshot.gex || null;
  let _gexIgnoredNote = null;
  if (gex) {
    const _cov = (gex.reliable !== undefined)
      ? gex.reliable === true || gex.reliable === 1
      : (gex.pcrStrikes == null ? true : gex.pcrStrikes >= 8);
    if (!_cov) {
      // NOTE: `strategy` is not constructed until much further down, so stash the
      // message and attach it after the object exists (referencing it here would
      // be a temporal-dead-zone ReferenceError).
      _gexIgnoredNote = 'GEX ignored — option chain coverage too low ('
        + (gex.coverage != null ? gex.coverage + '%' : 'unknown') + ') to trust OI-derived levels.';
      gex = null; // do not veto, penalise or reward on unreliable positioning data
    }
  }

  // Rule 1: GEX Regime VETO — Positive Gamma + spot below flip = block BUY CE
  if (gex && dir !== 'NO_TRADE') {
    const _flipDist = gex.flip ? Math.abs(ltp - gex.flip) : Infinity;
    const _distATR = atrEarly > 0 ? _flipDist / atrEarly : Infinity;

    if (gex.regime === 'Positive Gamma') {
      // Rule 1: GEX Regime VETO — but ONLY if:
      //   1. Spot is far from flip (>2.5 ATR) AND
      //   2. Confidence is NOT high (< 80%). At 80%+, the multi-factor engine has
      //      strong conviction that overrides GEX dampening. Let it through with penalty.
      // GEX Positive Gamma — SOFT PENALTY (restored after 5 losing trades showed it matters).
      // In Positive Gamma, dealers SUPPRESS moves. Selling (buying puts) in Positive Gamma
      // is fighting the dealers — today all 5 SELL trades in Positive Gamma lost.
      // Penalty: -12 confidence (strong deterrent but not absolute block).
      if (dir === 'SELL' && !gex.aboveFlip && _distATR > 1.5) {
        conf = Math.max(0, conf - 12);
        if (conf < (opts.confidenceThreshold || 60)) {
          vetoes.push('GEX_POSITIVE_SELL_PENALTY: Selling in Positive Gamma (dealers suppress downside). Confidence reduced below threshold.');
          dir = 'NO_TRADE';
        }
      }
      if (dir === 'BUY' && gex.aboveFlip && _distATR > 1.5) {
        conf = Math.max(0, conf - 12);
        if (conf < (opts.confidenceThreshold || 60)) {
          vetoes.push('GEX_POSITIVE_BUY_PENALTY: Buying in Positive Gamma above flip (dealers cap upside). Confidence reduced below threshold.');
          dir = 'NO_TRADE';
        }
      }
    }
    // Rule 3: Wall proximity — ONLY in Positive Gamma (dealers defend walls).
    // In Negative Gamma, walls are TARGETS not barriers — don't block directional trades.
    // Fixed: use ATR-based distance instead of fixed 0.5% (which is ~120 pts on NIFTY — too wide)
    if (gex.regime === 'Positive Gamma') {
      const wallDistATR_call = gex.callWall ? (gex.callWall - ltp) / atrEarly : 999;
      const wallDistATR_put = gex.putWall ? (ltp - gex.putWall) / atrEarly : 999;
      if (gex.callWall && dir === 'BUY' && wallDistATR_call < 0.5) {
        vetoes.push('CALL_WALL_PROXIMITY: Spot only ' + r2(wallDistATR_call) + ' ATR from call wall (' + gex.callWall + '). In Positive Gamma, dealers defend this.');
        dir = 'NO_TRADE';
      }
      if (gex.putWall && dir === 'SELL' && wallDistATR_put < 0.5) {
        vetoes.push('PUT_WALL_PROXIMITY: Spot only ' + r2(wallDistATR_put) + ' ATR from put wall (' + gex.putWall + '). In Positive Gamma, dealers defend support.');
        dir = 'NO_TRADE';
      }
    }
  }

  // Rule 2: Time cutoff — no new entries after 3:15 PM IST (15:15 = 915 min)
  if (dir !== 'NO_TRADE' && timeMin >= 915) {
    vetoes.push('LATE_SESSION: No new entries after 3:15 PM IST (' + ist.getHours() + ':' + String(ist.getMinutes()).padStart(2,'0') + '). Last 15 min = illiquidity + theta crush.');
    dir = 'NO_TRADE';
  }

  // Rule 4: Expiry-day theta trap.
  //
  // Was a flat `conf < 80`. Stacked on top of God Mode's own 80% expiry threshold —
  // which sat on a DISCOUNTED metric that could not reach 80 — this made expiry day
  // a dead zone: 50 of 143 signals were killed here in one session.
  //
  // Expiry day still gets the strictest treatment, but the bar is now the three things
  // that actually determine whether an expiry trade survives, rather than one number:
  //   * conviction high enough to justify the theta burn,
  //   * the higher timeframe not fighting the position, and
  //   * enough of the session left for the move to pay before decay and the close.
  // A 24150 CE can double in minutes on expiry — and round-trip to zero just as fast.
  // These conditions target that asymmetry instead of banning the session outright.
  const _expMtfAgainst = isExpDay && (
    (dir === 'BUY'  && snapshot.mtfBias != null && snapshot.mtfBias < 0) ||
    (dir === 'SELL' && snapshot.mtfBias != null && snapshot.mtfBias > 0));
  const _expTooLate = isExpDay && timeMin > 14 * 60 + 45;   // no new expiry risk after 14:45
  if (dir !== 'NO_TRADE' && isExpDay && (conf < 72 || _expMtfAgainst || _expTooLate)) {
    const why = conf < 72 ? 'confidence ' + conf.toFixed(0) + '% < 72% required on 0-1 DTE'
              : _expMtfAgainst ? 'higher timeframe opposes the trade'
              : 'past 14:45 — too little session left for the move to pay';
    vetoes.push('EXPIRY_DAY_WEAK: ' + why + '. Premium melts too fast for marginal setups on expiry.');
    dir = 'NO_TRADE';
  }

  // Rule 6: Consecutive red candles (momentum death) — 4+ red in a row = don't buy
  if (dir === 'BUY' && closes.length >= 5) {
    let reds = 0;
    for (let i = closes.length - 1; i >= closes.length - 5 && i > 0; i--) {
      if (closes[i] < closes[i - 1]) reds++; else break;
    }
    if (reds >= 4) {
      vetoes.push('MOMENTUM_DEATH: ' + reds + ' consecutive falling candles. Momentum has collapsed — do not chase a dead move.');
      dir = 'NO_TRADE';
    }
  }
  if (dir === 'SELL' && closes.length >= 5) {
    let greens = 0;
    for (let i = closes.length - 1; i >= closes.length - 5 && i > 0; i--) {
      if (closes[i] > closes[i - 1]) greens++; else break;
    }
    if (greens >= 4) {
      vetoes.push('MOMENTUM_DEATH_PUT: ' + greens + ' consecutive rising candles. Too late to short.');
      dir = 'NO_TRADE';
    }
  }

  // Rule 7: Premium already moved >20% from day open — you're late
  if (dir !== 'NO_TRADE' && snapshot.livePremium && snapshot.livePremiumOpen) {
    const pChg = ((snapshot.livePremium - snapshot.livePremiumOpen) / snapshot.livePremiumOpen) * 100;
    if ((dir === 'BUY' && pChg > 20) || (dir === 'SELL' && pChg < -20)) {
      vetoes.push('PREMIUM_ALREADY_MOVED: Option already moved ' + Math.abs(pChg).toFixed(0) + '% from open. You are late — chasing increases risk.');
      dir = 'NO_TRADE';
    }
  }

  // Rule 8: Higher-TF contradiction = HARD BLOCK
  // LESSON FROM TODAY: ALL 5 trades were SELL when 1-hour was BULLISH. All lost.
  // NEVER trade against the higher timeframe. If 1H says UP, don't SELL. Period.
  if (dir !== 'NO_TRADE' && snapshot.mtfBias != null) {
    const mtfAgrees = (snapshot.mtfBias > 0 && dir === 'BUY') || (snapshot.mtfBias < 0 && dir === 'SELL');
    if (!mtfAgrees && snapshot.mtfBias !== 0) {
      vetoes.push('MTF_CONTRADICTION: Higher timeframe is ' + (snapshot.mtfBias > 0 ? 'BULLISH' : 'BEARISH') + ' but signal is ' + dir + '. NEVER trade against the 1-hour trend.');
      dir = 'NO_TRADE';
    }
  }

  // Rule 11: Minimum ATR requirement — dead market
  // Rule 11: Dead Market — BUT adjusted for F&O indices where small spot moves
  // produce large premium moves via gamma. NIFTY 0.1% spot move = ₹24 pts = ~30-50% PE move near ATM.
  // Original 0.25% threshold killed signals on most normal trading days.
  // New logic: for indices, use 0.08% threshold (truly dead). For stocks, keep 0.2%.
  if (dir !== 'NO_TRADE') {
    const atrPct = (atrEarly / ltp) * 100;
    const isIndex = ['NIFTY','BANKNIFTY','FINNIFTY','MIDCPNIFTY'].includes(symbol);
    const deadThreshold = isIndex ? 0.06 : 0.20; // Indices: only kill at <0.06% (truly zero movement)
    // Additional check: if DTE is short AND near ATM, gamma makes even tiny moves profitable
    const gammaOverride = isIndex && (snapshot.dte || 5) <= 2 && atrPct >= 0.04;
    if (atrPct < deadThreshold && !gammaOverride) {
      vetoes.push('DEAD_MARKET: ATR only ' + atrPct.toFixed(2) + '% of spot. Market too quiet — premiums decay faster than spot moves.');
      dir = 'NO_TRADE';
    }
  }

  // Rule 12: PCR extreme — DISABLED as hard veto.
  // Reason: PCR data from GEX endpoint is unreliable (values like 78.54, 0.0, 224.8 seen today).
  // Instead, PCR is already factored into the Options component scoring (soft influence on confidence).
  // A hard veto on PCR blocked 49 signals today with corrupt data. Removed.

  // Rule 13: Session open filter — no signals 9:15–9:30 AM (555–570 min)
  if (dir !== 'NO_TRADE' && timeMin >= 555 && timeMin <= 570) {
    vetoes.push('OPENING_CHAOS: First 15 minutes (9:15–9:30) is gap-fill chaos. Wait for the opening range to form.');
    dir = 'NO_TRADE';
  }

  // Rule 14: Capital protection — DISABLED inside engine.
  // Reason: Live premium is fetched AFTER signal generation, so BS estimate here always
  // overestimates. The Paper Trader and God Mode have their own capital checks with live data.
  // Keeping the code for reference but not blocking signals.
  // The Paper Trader's autoExecute() checks: cost > capital * 0.5 with REAL live premium.

  // Rule 5: VIX spike — checked via snapshot.vix change (if available)
  // Rule 9 & 10: Max signals + losing streak — tracked client-side (stateful)
  // These are noted in the strategy output for client enforcement.

  // Apply vetoes
  if (vetoes.length > 0 && dir === 'NO_TRADE') {
    veto = vetoes.join(' | ');
  }

  // ══════════════════════════════════════════════════════════════════════════════

  // GEX confidence modulation — DISTANCE-SCALED + REGIME-TRANSITION AWARE
  // Old logic: blind -10 for Positive Gamma, -6 for flip misalignment = unfair -16.
  // New logic: penalty scales by how far spot is from the flip. Near the flip = regime
  // is about to change, so the penalty shrinks (breakdown IS plausible). Far from flip
  // = dealers have full control, penalty stays strong.
  //
  // Additionally: detect "transition zone" (within 1 ATR of flip) where the regime
  // can flip any moment — no penalty at all, slight BONUS for momentum trades.
  if (gex && dir !== 'NO_TRADE') {
    const flipDist = gex.flip ? Math.abs(ltp - gex.flip) : Infinity;
    const distATR = atrEarly > 0 ? flipDist / atrEarly : Infinity;

    if (gex.regime === 'Negative Gamma') {
      // Negative Gamma = dealers amplify moves. Always favorable for directional trades.
      conf = Math.min(99, conf + 8);
    } else if (gex.regime === 'Positive Gamma') {
      // Positive Gamma penalty SCALED by distance to flip:
      //   Within 1 ATR of flip ("transition zone"): NO penalty — regime can flip any tick
      //   1-2 ATR from flip: mild penalty (-3)
      //   2-4 ATR from flip: moderate penalty (-6)
      //   >4 ATR from flip: full penalty (-10) — dealers firmly in control
      if (distATR <= 1.0) {
        // TRANSITION ZONE: spot is so close to flip that a single push breaks the regime.
        // This is where ₹15→₹70 moves originate. BONUS for momentum alignment.
        const momentumAligned = (dir === 'SELL' && ltp > gex.flip) || (dir === 'BUY' && ltp < gex.flip);
        if (momentumAligned) conf = Math.min(99, conf + 4); // Heading TOWARD the flip = explosive potential
      } else if (distATR <= 2.0) {
        conf = Math.max(0, conf - 3);
      } else if (distATR <= 4.0) {
        conf = Math.max(0, conf - 6);
      } else {
        conf = Math.max(0, conf - 10);
      }
    }

    // Flip alignment — also distance-scaled
    if (gex.flip) {
      const aligned = (dir === 'BUY' && ltp > gex.flip) || (dir === 'SELL' && ltp < gex.flip);
      if (aligned) {
        // Already past the flip in our direction = dealers now amplify us
        conf = Math.min(99, conf + 6);
      } else if (distATR <= 1.5) {
        // Close to flip but haven't crossed — no penalty, about to cross
        // (old code would penalize -6 here; now 0)
      } else {
        // Far from flip, wrong side — scaled penalty
        const penalty = Math.min(6, Math.round(distATR * 1.2));
        conf = Math.max(0, conf - penalty);
      }
    }

    // OI-velocity boost: if put OI is building fast (from GEX data), boost SELL confidence
    // This captures institutional positioning that raw price action misses
    if (gex.totalPutOI && gex.totalCallOI) {
      const oiRatio = gex.totalPutOI / Math.max(1, gex.totalCallOI);
      if (dir === 'SELL' && oiRatio > 1.3) conf = Math.min(99, conf + 3); // Heavy put writing = institutional bearish
      if (dir === 'BUY' && oiRatio < 0.7) conf = Math.min(99, conf + 3);  // Heavy call writing = institutional bullish
    }
  }

  const orb = openingRange(snapshot.timestamps, highs, lows, closes);
  const swing = swingSR(highs, lows, closes, ltp);
  const winRate = clamp(Math.round((regime === 'Trending' ? 56 : regime === 'Developing' ? 48 : 38) + (conf - 60) * 0.4), 25, 82);
  const strategy = {
    regime,
    adx: adx != null ? Math.round(adx) : null,
    plusDI: adxObj.plusDI != null ? Math.round(adxObj.plusDI) : null,
    minusDI: adxObj.minusDI != null ? Math.round(adxObj.minusDI) : null,
    mtf: snapshot.mtfBias ? (snapshot.mtfBias > 0 ? 'Bullish' : 'Bearish') : 'n/a',
    orb, swing, winRate, gex,
    playbook: regime === 'Trending'
      ? 'Strong trend (ADX≥25): trade WITH the trend — enter on pullbacks toward VWAP/EMA9, hold for T2/T3, trail stops. Avoid counter-trend fades.'
      : regime === 'Developing'
      ? 'Trend developing (ADX 18-25): enter on breakout confirmation of the opening range or swing level; keep tighter stops and book T1 quickly.'
      : 'Choppy/range (ADX<18): directional edge is low — fade extremes near support/resistance or stay flat. Wait for ADX to rise before momentum trades.'
  };
  if (gex) {
    strategy.gexNote = gex.regime === 'Negative Gamma'
      ? `Dealers are SHORT gamma (net GEX ${gex.netGEX}) — they hedge WITH the move, AMPLIFYING volatility. Favor momentum/breakout trades and let winners run toward the walls. Upside magnet (call wall) ${gex.callWall}, downside support (put wall) ${gex.putWall}, zero-gamma flip ${gex.flip}.`
      : `Dealers are LONG gamma (net GEX ${gex.netGEX}) — they hedge AGAINST the move, SUPPRESSING volatility (range day). Fade extremes back toward the flip ${gex.flip}; avoid chasing breakouts. Call wall ${gex.callWall} = resistance, put wall ${gex.putWall} = support.`;
  } else if (_gexIgnoredNote) {
    strategy.gexNote = _gexIgnoredNote;
  }

  // Risk filters
  if (rsi.latest !== null) {
    if (rsi.latest > 85 && dir === 'BUY') { veto = 'RSI_OVERBOUGHT'; dir = 'NO_TRADE'; }
    if (rsi.latest < 15 && dir === 'SELL') { veto = 'RSI_OVERSOLD'; dir = 'NO_TRADE'; }
  }

  if (dir === 'NO_TRADE' || conf < threshold) {
    return noTrade(symbol, ts, vetoes.length ? vetoes[0] : (dir === 'NO_TRADE' ? (veto || 'Neutral bias') : `Confidence ${conf.toFixed(0)}% < ${threshold}%`),
      { comp, net, conf, ltp, rsi: rsi.latest, atr: atrEarly, snapshot, strategy, vetoes, agree });
  }

  // Trade setup
  const atr = ATR(highs, lows, closes) || ltp * 0.012;
  const sl = dir === 'BUY' ? ltp - 1.2 * atr : ltp + 1.2 * atr;
  const targets = [
    { label: 'T1', price: dir === 'BUY' ? ltp + atr : ltp - atr, rr: '1:1' },
    { label: 'T2', price: dir === 'BUY' ? ltp + 2 * atr : ltp - 2 * atr, rr: '1:1.7' },
    { label: 'T3', price: dir === 'BUY' ? ltp + 3.2 * atr : ltp - 3.2 * atr, rr: '1:2.7' },
  ];

  // Option strategy. Prefer official per-expiry metadata and an explicitly
  // selected affordable strike; constants are only a last-resort display fallback.
  const step = Number(snapshot.strikeStep) || STRIKE_STEPS[symbol] || 50;
  const lot = Number(snapshot.lotSize) || LOT_SIZES[symbol] || 50;
  const atm = Number(snapshot.optionStrike) || Math.round(ltp / step) * step;
  const t = 5 / 365, iv = snapshot.iv || 0.15;
  const otype = dir === 'BUY' ? 'CE' : 'PE';
  const prem = bsPrice(otype, ltp, atm, t, RISK_FREE, iv);
  const delta = bsDelta(otype, ltp, atm, t, RISK_FREE, iv);

  const strikes = [];
  for (let o = -2; o <= 2; o++) {
    const k = dir === 'BUY' ? atm - o * step : atm + o * step;
    const pr = bsPrice(otype, ltp, k, t, RISK_FREE, iv);
    const mny = Math.abs(o) < 0.5 ? 'ATM' : (o > 0 ? `ITM${Math.abs(o)}` : `OTM${Math.abs(o)}`);
    strikes.push({ strike: k, type: otype, label: mny, premium: Math.round(pr * 100) / 100, cost: Math.round(pr * lot), primary: o === 0 });
  }

  const bb = bollinger(closes);
  const bbw = bb.mid ? ((bb.upper - bb.lower) / bb.mid) * 100 : null;

  // Pivots
  const ph = highs[len - 2], pl = lows[len - 2], pc = closes[len - 2];
  const pp = (ph + pl + pc) / 3;

  return {
    symbol, timestamp: ts, direction: dir,
    // Integer, matching the noTrade path. Confidence is a heuristic score, not a
    // measurement — one decimal place implied precision it does not have, and it
    // read inconsistently against every other panel ("81.1%" beside "63%").
    confidence: Math.round(conf),
    netScore: Math.round(net * 10) / 10,
    // AGREEMENT — must be exported. God Mode reads `rawSignal.agreement || 0.5`, and
    // because this field never existed it defaulted to 0.5 on EVERY signal, forever.
    // That term is worth up to 42 of the 99 godConfidence points, so it was pinned at
    // 21 and capped typical conviction around 41-66. The expiry-day threshold is 80,
    // making it unreachable — the system could not confirm a trade on expiry day no
    // matter how good the setup was. This one missing field was the off switch.
    agreement: Math.round(agree * 1000) / 1000,
    agreementPct: Math.round(agree * 100),
    components: comp, ltp: Math.round(ltp * 100) / 100,
    rsi: rsi.latest ? Math.round(rsi.latest * 10) / 10 : null,
    atr: Math.round(atr * 100) / 100,
    entryZone: { low: ltp - 0.3 * atr, high: ltp + 0.3 * atr, ideal: ltp },
    stopLoss: Math.round(sl * 100) / 100,
    targets,
    optionStrategy: {
      action: `Buy ${symbol} ${atm} ${otype}`, type: otype, strike: atm,
      estimatedPremium: Math.round(prem * 100) / 100, delta: Math.round(delta * 1000) / 1000,
      lotSize: lot, capitalRequired: Math.round(prem * lot),
    },
    optionPlan: { strikes, recommendation: `Buy ${symbol} ${atm} ${otype} (ATM) as primary` },
    optionTrade: buildOptionTrade(dir, symbol, ltp, atr, snapshot, true),
    trendLabel: leanLabel(net),
    strategy,
    vetoes: [], // passed all vetoes if we got here
    probabilities: {
      hitT1: Math.min(95, Math.round((conf / 100 * 0.82 + 0.12) * 100)),
      hitT2: Math.min(85, Math.round((conf / 100 * 0.65 + 0.05) * 100)),
      hitT3: Math.min(70, Math.round((conf / 100 * 0.48) * 100)),
      hitSL: Math.max(5, Math.round((1 - conf / 100) * 55)),
    },
    analytics: {
      volSqueeze: { detected: bbw !== null && bbw < 1.5, bbWidth: bbw ? Math.round(bbw * 100) / 100 : null },
      momentum: { value: rsi.latest ? Math.round(rsi.latest) : 50, label: rsi.latest > 60 ? 'Bullish' : rsi.latest < 40 ? 'Bearish' : 'Neutral' },
      pivots: {
        pp: r2(pp), r1: r2(2 * pp - pl), r2: r2(pp + (ph - pl)), s1: r2(2 * pp - ph), s2: r2(pp - (ph - pl)),
      },
      risk: { riskPerLot: Math.round(atr * 1.2 * lot), rewardT1PerLot: Math.round(atr * lot), rr: 0.83 },
      strength: conf >= 90 ? 'VERY_STRONG' : conf >= 82 ? 'STRONG' : conf >= 75 ? 'MODERATE' : 'WEAK',
    },
    superTrend: st,
    verdict: `${dir === 'BUY' ? '📈 BUY' : '📉 SELL'} ${symbol} @ ₹${r2(ltp)} | Confidence ${conf.toFixed(0)}% | ${otype} ${atm} @ ₹${Math.round(prem)}`,
  };
}

function r2(x) { return Math.round(x * 100) / 100; }

/**
 * Build a complete option trade plan with premium projections at each level.
 * Works for both actionable trades and "watch" (no-trade) setups.
 * @param lean 'BUY' or 'SELL' (the directional lean, even if not actionable)
 */
export function buildOptionTrade(lean, symbol, ltp, atr, snapshot, actionable) {
  const step = Number(snapshot.strikeStep) || STRIKE_STEPS[symbol] || 50;
  const lot = Number(snapshot.lotSize) || LOT_SIZES[symbol] || 50;
  const atm = Number(snapshot.optionStrike) || Math.round(ltp / step) * step;
  const otype = lean === 'BUY' ? 'CE' : 'PE';
  const iv = snapshot.iv || 0.15;
  const t = snapshot.dte ? Math.max(snapshot.dte, 0.5) / 365 : 5 / 365;

  // Premium at a given spot (intraday: time roughly constant)
  const premAt = (spot) => bsPrice(otype, spot, atm, t, RISK_FREE, iv);

  const entryPremBS = premAt(ltp);
  // Use live LTP as entry premium when available; scale projections by the same ratio
  const livePremium = snapshot.livePremium || null;
  const scale = (livePremium && entryPremBS > 0) ? livePremium / entryPremBS : 1;
  const entryPrem = livePremium || entryPremBS;

  // Spot targets by direction
  const dirMul = lean === 'BUY' ? 1 : -1;
  const spotT1 = ltp + dirMul * 1.0 * atr;
  const spotT2 = ltp + dirMul * 2.0 * atr;
  const spotT3 = ltp + dirMul * 3.2 * atr;
  const spotSL = ltp - dirMul * 1.2 * atr;

  // Entry trigger: ACTIVE signals = buy now (no buffer, confirmation already happened).
  // WATCH signals = wait for a breakout buffer above/below current price.
  const buffer = actionable ? 0 : 0.3 * atr;
  const trigger = ltp + dirMul * buffer;

  const proj = (spot) => {
    const p = premAt(spot) * scale;
    const gain = entryPrem > 0 ? ((p - entryPrem) / entryPrem) * 100 : 0;
    return { spot: r2(spot), premium: r2(p), gainPct: Math.round(gain) };
  };

  const t1 = proj(spotT1), t2 = proj(spotT2), t3 = proj(spotT3);
  const slp = premAt(spotSL) * scale;
  const slLossPct = entryPrem > 0 ? ((slp - entryPrem) / entryPrem) * 100 : 0;

  let entryText, entryStatus;
  const rawQuoteTimestamp = Number(snapshot.liveQuoteAt);
  const quoteAgeMs = snapshot.liveQuoteAt && Number.isFinite(rawQuoteTimestamp)
    ? Date.now() - rawQuoteTimestamp
    : null;
  const executionGates = {
    actionable: !!actionable,
    positiveLivePremium: Number(livePremium) > 0,
    exactExpiry: !!snapshot.expiry,
    contractEligible: snapshot.contractEligible === true,
    quoteNotFuture: quoteAgeMs !== null && quoteAgeMs >= 0,
    quoteFresh: quoteAgeMs !== null && quoteAgeMs <= 45000,
  };
  const executionGateCodes = {
    actionable: 'SIGNAL_NOT_ACTIONABLE',
    positiveLivePremium: 'LIVE_PREMIUM_NOT_POSITIVE',
    exactExpiry: 'EXACT_EXPIRY_MISSING',
    contractEligible: 'CONTRACT_NOT_ELIGIBLE',
    quoteNotFuture: 'QUOTE_FROM_FUTURE',
    quoteFresh: 'QUOTE_STALE_OR_MISSING',
  };
  const failedExecutionGates = Object.entries(executionGates)
    .filter(([, passed]) => !passed)
    .map(([gate]) => executionGateCodes[gate]);
  const executable = Object.values(executionGates).every(Boolean);
  if (executable) {
    entryStatus = 'BUY NOW — LIVE CONTRACT';
    entryText = lean === 'BUY'
      ? `Buy the ${atm} CE at the verified live premium. Stay long while ${symbol} holds above ${r2(spotSL)}; exit if it breaks below.`
      : `Buy the ${atm} PE at the verified live premium. Stay in while ${symbol} holds below ${r2(spotSL)}; exit if it breaks above.`;
  } else if (actionable) {
    entryStatus = 'WAIT — CONTRACT NOT EXECUTABLE';
    entryText = `The directional setup is confirmed, but no fresh live quote with a listed expiry is available for ${symbol} ${atm} ${otype}. Do not trade the theoretical price.`;
  } else {
    entryStatus = 'WAITING';
    entryText = lean === 'BUY'
      ? `Do NOT buy yet. Only enter IF ${symbol} rises and holds above ${r2(trigger)} (currently ${r2(ltp)}).`
      : `Do NOT buy yet. Only enter IF ${symbol} falls and holds below ${r2(trigger)} (currently ${r2(ltp)}).`;
  }

  return {
    strike: atm,
    type: otype,
    expiry: snapshot.expiry || null,
    expiryDisplay: snapshot.expiryDisplay || null,
    lotSize: lot,
    strikeStep: step,
    entryPremium: r2(entryPrem),
    premiumSource: livePremium ? 'live' : 'theoretical',
    livePremium: livePremium ? r2(livePremium) : null,
    quoteTimestamp: snapshot.liveQuoteAt || null,
    quoteAgeMs,
    executionGates,
    failedExecutionGates,
    executable,
    entryTrigger: r2(trigger),
    entryStatus,
    entryCondition: entryText,
    capitalPerLot: Math.round(entryPrem * lot),
    actionable,
    targets: [
      { label: 'T1', spot: t1.spot, premium: t1.premium, gainPct: t1.gainPct, sellText: `Sell/Book 50% when premium ≈ ₹${t1.premium} (spot ${t1.spot}, +${t1.gainPct}%)` },
      { label: 'T2', spot: t2.spot, premium: t2.premium, gainPct: t2.gainPct, sellText: `Sell 30% more when premium ≈ ₹${t2.premium} (spot ${t2.spot}, +${t2.gainPct}%)` },
      { label: 'T3', spot: t3.spot, premium: t3.premium, gainPct: t3.gainPct, sellText: `Trail rest to premium ≈ ₹${t3.premium} (spot ${t3.spot}, +${t3.gainPct}%)` },
    ],
    stopLoss: { spot: r2(spotSL), premium: r2(slp), lossPct: Math.round(slLossPct), exitText: `Exit fully if premium falls to ≈ ₹${r2(slp)} (spot ${r2(spotSL)})` },
  };
}

function noTrade(symbol, ts, reason, extra = {}) {
  const r = { symbol, timestamp: ts, direction: 'NO_TRADE', confidence: extra.conf ? Math.round(extra.conf) : 0, netScore: extra.net ? Math.round(extra.net * 10) / 10 : 0, ltp: extra.ltp ? r2(extra.ltp) : null, atr: extra.atr ? r2(extra.atr) : null, rsi: extra.rsi ? Math.round(extra.rsi * 10) / 10 : null, components: extra.comp || null, strategy: extra.strategy || null, vetoes: extra.vetoes || [], reason, verdict: `⏸️ NO TRADE — ${symbol}: ${reason}` };

  // SHAPE CONSISTENCY.
  // The actionable return path carries `probabilities`, and signal.html renders it
  // whenever `direction !== 'NO_TRADE'`. The signal LOCK legitimately restores a
  // held direction onto an object that came through THIS path, which made the page
  // actionable while `probabilities` was undefined:
  //     TypeError: Cannot read properties of undefined (reading 'hitT1')
  // Rather than only guarding the renderer, both paths now return the same shape,
  // so no present or future consumer can trip over a missing field. Derived from
  // the same formula as the actionable path, so the numbers agree.
  // Same reason as the actionable path: God Mode defaults a missing `agreement` to
  // 0.5, which silently pinned its largest confidence term.
  if (extra.agree != null) {
    r.agreement = Math.round(extra.agree * 1000) / 1000;
    r.agreementPct = Math.round(extra.agree * 100);
  }
  const _c = extra.conf ? extra.conf : 0;
  r.probabilities = {
    hitT1: Math.min(95, Math.round((_c / 100 * 0.82 + 0.12) * 100)),
    hitT2: Math.min(85, Math.round((_c / 100 * 0.65 + 0.05) * 100)),
    hitT3: Math.min(70, Math.round((_c / 100 * 0.48) * 100)),
    hitSL: Math.max(5, Math.round((1 - _c / 100) * 55)),
  };
  // Analytics/superTrend are not computed on this path; null is honest and every
  // reader already guards on them.
  if (r.analytics === undefined) r.analytics = null;
  if (r.superTrend === undefined) r.superTrend = null;

  // Add a "watch" option plan for the leaning direction
  if (extra.snapshot && extra.ltp && extra.atr) {
    const lean = (extra.net || 0) >= 0 ? 'BUY' : 'SELL';
    r.trendLabel = leanLabel(extra.net || 0);
    r.watchTrade = buildOptionTrade(lean, symbol, extra.ltp, extra.atr, extra.snapshot, false);
  }
  if (r.trendLabel === undefined) r.trendLabel = leanLabel(extra.net || 0);
  return r;
}

function leanLabel(net) {
  if (net > 40) return 'Strong Bullish';
  if (net > 10) return 'Bullish';
  if (net > 5) return 'Sideways Bullish';
  if (net >= -5) return 'Neutral';
  if (net >= -10) return 'Sideways Bearish';
  if (net >= -40) return 'Bearish';
  return 'Strong Bearish';
}

/** Plain-English expert advice */
export function expertAdvice(sig) {
  if (sig.direction === 'NO_TRADE') {
    const lean = (sig.netScore || 0) >= 0 ? 'mildly bullish' : 'bearish';
    return `In plain English: right now there is no clear, high-confidence trade in ${sig.symbol} (reading ${lean}, confidence ${sig.confidence}%). The smartest move — especially with limited capital — is to stay out and protect your money until a cleaner setup appears. Not trading is also a decision. If you want to prepare, watch the entry trigger above; only act when price confirms.`;
  }
  const t = sig.optionTrade;
  const act = sig.direction === 'BUY' ? 'bullish (Call/CE)' : 'bearish (Put/PE)';
  if (!t || !t.executable) {
    return `In plain English: ${sig.symbol} has a ${act} directional setup, but the option contract is not executable because a fresh live premium and exact listed expiry are not both available. Do not act on the theoretical premium; wait for the live contract gate to clear.`;
  }
  return `In plain English: ${sig.symbol} is showing a ${act} setup with a verified live contract. Buy the ${t.strike} ${t.type} around ₹${t.entryPremium} once ${sig.direction === 'BUY' ? 'price holds above ' + t.entryTrigger : 'price stays below ' + t.entryTrigger}. Book partial profits at T1 (₹${t.targets[0].premium}), more at T2 (₹${t.targets[1].premium}), and trail the rest to T3 (₹${t.targets[2].premium}). Cut the trade if premium drops to ₹${t.stopLoss.premium}. Never risk more than 2% of your capital on one trade.`;
}

// ─── Build snapshot from proxy ────────────────────────────────────────────
export async function buildSnapshot(symbol, withQuote = true) {
  const candles = await getCandles(symbol, 'FIFTEEN_MINUTE', 12);
  if (!candles || candles.count < 30) return null;
  const lastClose = candles.closes[candles.closes.length - 1];
  // Reconcile the displayed price with the live quote (action=quote) so the signal
  // page shows the SAME LTP as the dashboard. getCandles returns the last 15-min
  // bar's close, which lags the true last-traded price (most visible after hours,
  // e.g. RELIANCE candle 1302.5 vs quote 1298). Ranking scans pass withQuote=false
  // to stay fast (candles only).
  let ltp = lastClose;
  if (withQuote) {
    try {
      const q = await getQuotes([symbol]);
      const qLtp = (q && q[0]) ? Number(q[0].ltp) : NaN;
      // Trust the quote only if finite, positive, and within 20% of the last candle
      // close — guards against a bad/mismapped feed corrupting the series.
      if (isFinite(qLtp) && qLtp > 0 && Math.abs(qLtp - lastClose) / lastClose < 0.20) {
        ltp = qLtp;
        candles.closes[candles.closes.length - 1] = qLtp; // align series so math + display agree
      }
    } catch (e) { /* keep candle close on any failure */ }
  }
  return {
    symbol,
    opens: candles.opens, highs: candles.highs, lows: candles.lows,
    closes: candles.closes, volumes: candles.volumes, timestamps: candles.timestamps,
    ltp,
    pcr: null, iv: 0.15, fiiFlow: null, newsSentiment: null,
    isIndex: FNO_INDICES.includes(symbol),
    lotSize: LOT_SIZES[symbol] || 50,
    strikeStep: STRIKE_STEPS[symbol] || 50,
    optionStrike: null,
    livePremium: null,
    liveQuoteAt: null,
  };
}

export function fmt(n, d = 2) {
  if (n === null || n === undefined) return '—';
  return Number(n).toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d });
}
export function inr(n) { return n == null ? '—' : '₹' + fmt(n); }
