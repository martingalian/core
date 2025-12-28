# Trading Strategy System

## Overview

The trading strategy system determines when and how to enter/exit positions based on technical analysis, risk management rules, and market conditions. It integrates indicator signals, direction conclusions, and trade configurations to generate actionable trading signals.

---

## Signal Generation Flow

```
Indicator Data Collected
    ↓
Direction Concluded (ConcludeSymbolDirectionAtTimeframeJob)
    ↓
Price Alignment Confirmed (ConfirmPriceAlignmentWithDirectionJob)
    ↓
Trade Configuration Validates Entry
    ↓
Risk Management Checks Applied
    ↓
Position Sizing Calculated
    ↓
Order Created
```

---

## Direction Conclusion

### ConcludeSymbolDirectionAtTimeframeJob

**Purpose**: Analyzes indicator data to determine trading direction

**Triggered**: After indicator refresh

**Logic**:
1. Load recent IndicatorHistory for symbol/timeframe
2. Extract indicator values (RSI, MACD, EMA, MFI, ADX, OBV)
3. Apply scoring system with weights
4. Calculate confidence percentage
5. If confidence ≥ threshold: Set direction (LONG/SHORT)
6. If confidence < threshold: Set NULL (neutral)
7. Store conclusion and update timestamps

### Indicator Scoring

| Indicator | LONG Signal | SHORT Signal | Weight |
|-----------|-------------|--------------|--------|
| RSI | < 30 (oversold) | > 70 (overbought) | 20 |
| MACD Histogram | Positive | Negative | 15 |
| EMA Alignment | EMA9 > EMA21 > EMA50 | EMA9 < EMA21 < EMA50 | 25 |
| ADX | > 25 (strong trend) | > 25 (strong trend) | Multiplier |

**Confidence Calculation**: max(longScore, shortScore) / totalScore

**Direction Threshold**: 70% confidence required

### ConfirmPriceAlignmentWithDirectionJob

**Purpose**: Validates that price action confirms the concluded direction

**Logic**:
1. Check current direction (LONG/SHORT)
2. Analyze recent price movement (10 candles)
3. Calculate price trend (linear regression or EMA slope)
4. Compare direction with price trend
5. Strong alignment: Keep direction
6. Weak alignment: Invalidate signal (set NULL)

---

## Entry Criteria

### Pre-Trade Validation Checklist

| Category | Requirement |
|----------|-------------|
| **Direction** | ExchangeSymbol.direction is LONG or SHORT |
| **Freshness** | indicators_synced_at < 5 minutes old |
| **Price Alignment** | Price trend matches direction |
| **Symbol Status** | is_manually_enabled, !auto_disabled, has direction |
| **Cooldown** | tradeable_at is NULL or in past |
| **Account** | can_trade = true, position slots available |
| **Balance** | Sufficient margin for position |
| **Risk** | No recent loss if disable_on_negative_pnl enabled |

### Entry Timing

| Timing | Order Type | When |
|--------|------------|------|
| Immediate | MARKET | High confidence, low volatility |
| Delayed | LIMIT | Moderate confidence, high volatility |

---

## Symbol Selection (HasTokenDiscovery)

### Overview

Bridges portfolio-level strategy ("open 5 LONG positions") with symbol-level execution ("buy BTC/USDT, ETH/USDT").

### Flow

```
Strategy creates N empty positions (status='new')
    ↓
LaunchCreatedPositionsJob triggers
    ↓
API sync jobs (positions, orders, balance)
    ↓
AssignTokensToNewPositionsJob
    ↓
Positions assigned concrete trading pairs
    ↓
DispatchNewPositionsWithTokensAssignedJob
```

### Critical Issues Identified

#### Issue 1: Random Symbol Selection (HIGH PRIORITY)

**Current**: Symbols selected randomly from available signals

**Problem**: Creates portfolio concentration risk (all memecoins, high correlation)

**Recommendation**: Replace with signal strength scoring
- Score by RSI extremes, MACD magnitude, ADX strength
- Penalize signal staleness and volatility
- Filter by correlation with existing positions

#### Issue 2: Fast-Track May Chase Exhausted Moves

**Current**: Fast profitable symbols reused immediately

**Problem**: Fast moves often signal exhaustion, not continuation

**Recommendation**: Validate signal strength before fast-track reuse

#### Issue 3: Stale Signal Risk

**Current**: No freshness check on indicator data

**Recommendation**: Filter symbols by signal freshness (< 2.5 minutes)

#### Issue 4: No Volatility Adjustment

**Current**: All symbols get equal margin regardless of volatility

**Recommendation**: Store volatility metadata for position sizing

#### Issue 5: No Market Regime Awareness

**Current**: Same selection logic in all market conditions

**Recommendation**: Adjust signal thresholds by market regime:
- Bull: Lower bar (30)
- Choppy: Moderate bar (50)
- Bear: High bar (70)

---

## Exit Criteria

### Profit Targets

| Type | Description |
|------|-------------|
| Fixed TP | Set at configured % above entry |
| Trailing TP | Moves up as price increases |
| Multiple Targets | 50% at 2%, 50% at 4% |

### Stop Loss

| Type | Description |
|------|-------------|
| Fixed SL | Set at configured % below entry |
| Dynamic SL | Breakeven after profit threshold |
| Trailing SL | Follows price with fixed distance |
| ATR-based | Uses volatility for stop distance |

### Other Exit Triggers

| Trigger | Action |
|---------|--------|
| Direction Reversal | Close immediately (MARKET) |
| Manual Exit | User-initiated |
| Liquidation Risk | Force-reduce at 30% margin |

---

## Position Sizing

### Risk-Based Sizing

| Step | Formula |
|------|---------|
| Risk Amount | accountBalance × riskPercentage |
| Risk Per Unit | entryPrice - stopLossPrice |
| Quantity | riskAmount / riskPerUnit |

### Leverage Application

| Step | Formula |
|------|---------|
| Position Value | quantity × entryPrice |
| Margin Required | positionValue / leverage |
| Verify | availableMargin ≥ marginRequired |

### Kelly Criterion (Optional)

| Variable | Description |
|----------|-------------|
| p | Win probability |
| b | Win/loss ratio |
| f | Kelly fraction: (p × b - q) / b |
| Half-Kelly | f × 0.5 (conservative) |

---

## Multi-Timeframe Analysis

### Timeframe Hierarchy

| Timeframe | Weight | Role |
|-----------|--------|------|
| 1d | 50% | Primary trend |
| 4h | 30% | Intermediate trend |
| 1h | 20% | Entry timing |

### Alignment Logic

| Scenario | Result |
|----------|--------|
| All timeframes LONG | High confidence LONG |
| All timeframes SHORT | High confidence SHORT |
| Mixed signals | Lower confidence or wait |

---

## Martingale Strategy (Caution)

**Warning**: Pure martingale (doubling after loss) is extremely risky.

### Safe Alternatives

| Strategy | Description |
|----------|-------------|
| Anti-Martingale | Increase after wins, decrease after losses |
| Fixed Fractional | Always risk same percentage |
| Kelly Criterion | Size based on edge and win rate |

### If Martingale Required

| Safeguard | Value |
|-----------|-------|
| Max steps | 3 (strict limit) |
| Max drawdown | 20% |
| Emergency exit | After max steps |
| Only on | High-confidence signals |

---

## Configuration

### Strategy Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `confidence_threshold` | 0.7 | 70% minimum confidence |
| `max_indicator_age_minutes` | 5 | Stale data threshold |
| `require_price_alignment` | true | Validate price trend |
| `min_liquidity_volume_24h` | 1000000 | $1M minimum volume |
| `use_trailing_stop` | true | Enable trailing SL |
| `trailing_stop_percentage` | 0.02 | 2% trailing distance |
| `breakeven_activation_percentage` | 0.015 | Move to breakeven after 1.5% |

### Symbol Selection Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `min_signal_strength` | 40 | Minimum score |
| `signal_freshness_minutes` | 2 | Max indicator age |
| `fast_track_min_signal_strength` | 50 | Revalidation threshold |
| `max_correlation` | 0.8 | Reject if > 0.8 |
| `max_sector_exposure` | 0.4 | Max 40% per sector |
| `max_symbol_volatility_24h` | 0.25 | Reject > 25% daily vol |

---

## Portfolio-Level Risk (Future)

### Needed Enhancements

| Feature | Description |
|---------|-------------|
| Correlation Matrix | Reject if correlation > 0.8 |
| Sector Diversification | Max 40% in any sector |
| Market Cap Spread | Mix of large/mid/small cap |
| Kelly for Selection | Prefer higher Kelly scores |

---

## Debugging Signals

1. Check IndicatorHistory: Are values being stored?
2. Check ExchangeSymbol.direction: Is it being set?
3. Check indicators_synced_at: Is data fresh?
4. Review ConcludeSymbolDirectionAtTimeframeJob logs
5. Verify ConfirmPriceAlignmentWithDirectionJob ran

---

## Related Systems

- **Indicators**: Provides RSI, MACD, EMA data
- **TradingExecution**: Executes the signals
- **StepDispatcher**: Orchestrates job execution
- **ExceptionHandling**: Handles errors and retries
