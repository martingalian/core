# BTC Correlation & Elasticity Analysis System

## Overview

Multi-timeframe statistical analysis system that measures how tokens move relative to Bitcoin. Calculates correlation (strength of relationship) and elasticity (amplification/dampening) for optimal position selection.

---

## Key Design: BTC Bias-Based Token Selection

The system uses BTC's current direction as a market bias to select optimal tokens:

1. **BTC direction determines correlation sign needed**:
   - Same direction (BTC=LONG, position=LONG) → want **positive** correlation
   - Opposite direction (BTC=LONG, position=SHORT) → want **negative** correlation

2. **Single timeframe scoring**: Uses only the timeframe where BTC's direction signal was detected

3. **Conservative approach**: If BTC has no direction → no positions can be opened

**Configuration**: `config('martingalian.token_discovery.correlation_type')` - Options: `pearson`, `spearman`, `rolling`

---

## Core Concepts

### Correlation

Measures the strength and direction of the linear relationship between a token's price and BTC's price.

**Types Calculated**:

| Type | Description | Range |
|------|-------------|-------|
| Pearson | Linear relationship | -1 to 1 |
| Spearman | Rank-based relationship (robust to outliers) | -1 to 1 |
| Rolling | Windowed Pearson correlation over recent candles | -1 to 1 |

**Interpretation**:

| Value | Meaning |
|-------|---------|
| 1.0 | Perfect positive correlation (moves exactly with BTC) |
| 0.0 | No correlation (independent movement) |
| -1.0 | Perfect negative correlation (moves opposite to BTC) |

**Window Configurations**:

| Setting | Default | Description |
|---------|---------|-------------|
| `window_size` | 500 | Total candles analyzed |
| `rolling.window_size` | 100 | Sliding window size |
| `rolling.method` | 'recent' | Aggregation: 'recent', 'average', or 'weighted' |
| `rolling.step_size` | 10 | Window sliding step |

---

### Elasticity

Measures how much a token's percentage price change amplifies or dampens relative to BTC's percentage change.

**Formula**: `Elasticity = (Token % Change) / (BTC % Change)`

**Directional Metrics**:

| Metric | Description |
|--------|-------------|
| `elasticity_long` | Average elasticity during BTC upward movements |
| `elasticity_short` | Average elasticity during BTC downward movements |

**Interpretation**:

| Value | Meaning |
|-------|---------|
| > 1 | Token amplifies BTC movement (moves more than BTC) |
| = 1 | Token moves exactly with BTC |
| < 1 | Token dampens BTC movement (moves less than BTC) |
| < 0 | Inverted correlation - token moves opposite to BTC |

**Examples**:

| Scenario | Meaning |
|----------|---------|
| `elasticity_long = 2.5` | When BTC rises 1%, token rises 2.5% |
| `elasticity_short = 3.0` | When BTC falls 1%, token falls 3% |
| `elasticity_short = -10.0` | When BTC falls 1%, token RISES 10% (inverted) |

---

### Asymmetry (Risk/Reward Profile)

**Formula**: `Asymmetry = elasticity_long - elasticity_short`

| Asymmetry | Profile | Assessment |
|-----------|---------|------------|
| 10 - 2 = 8 | High upside, low downside | Excellent LONG candidate |
| 5 - (-10) = 15 | Moderate upside, rises when BTC falls | Premium LONG candidate |
| 2 - 8 = -6 | Low upside, high downside | Poor LONG candidate |

---

## Architecture

### Data Flow

```
RefreshCoreDataCommand (Cron Job)
    ↓
DiscoverExchangeSymbolsJob (Block A - Parent)
    ↓
├── GetAllSymbolsFromExchangeJob (Index 1)
│   └── Creates UpsertSymbolEligibilityJob children
│       └── Creates UpsertExchangeSymbolJob (creates ExchangeSymbols)
│
└── TriggerCorrelationCalculationsJob (Index 2)
    └── Creates CalculateBtcCorrelationJob and CalculateBtcElasticityJob per symbol
```

---

## Core Models

### ExchangeSymbol Correlation Columns

| Column | Type | Description |
|--------|------|-------------|
| `btc_correlation_pearson` | JSON | Pearson correlations indexed by timeframe |
| `btc_correlation_spearman` | JSON | Spearman correlations indexed by timeframe |
| `btc_correlation_rolling` | JSON | Rolling correlations indexed by timeframe |
| `btc_elasticity_long` | JSON | Long elasticities indexed by timeframe |
| `btc_elasticity_short` | JSON | Short elasticities indexed by timeframe |

**Example Structure**: `{'1h': 0.87, '4h': 0.92, '6h': 0.88, '12h': 0.90, '1d': 0.85}`

### Candle Model

Used for correlation and elasticity calculations.

**Critical Index**: `(exchange_symbol_id, timeframe, timestamp)` for fast aligned queries

---

## Jobs

### TriggerCorrelationCalculationsJob

| Aspect | Details |
|--------|---------|
| Location | `Jobs/Models/ApiSystem/TriggerCorrelationCalculationsJob.php` |
| Trigger | After exchange sync complete |
| Purpose | Creates parallel calculation jobs for all USDT symbols |

**Logic**:
1. Check if correlation or elasticity feature enabled
2. For each USDT symbol on the exchange, create calculation jobs
3. All jobs run in parallel (same index, same block_uuid)

---

### CalculateBtcCorrelationJob

| Aspect | Details |
|--------|---------|
| Location | `Jobs/Models/ExchangeSymbol/CalculateBtcCorrelationJob.php` |
| Purpose | Calculate Pearson, Spearman, and Rolling correlations |

**Algorithm** (per timeframe):
1. Fetch last N candles for token and BTC
2. Align candles by timestamp (only use common timestamps)
3. Extract close prices into arrays
4. Calculate Pearson correlation using standard formula
5. Calculate Spearman correlation (rank-based)
6. Calculate Rolling correlation using sliding window
7. Store results indexed by timeframe

---

### CalculateBtcElasticityJob

| Aspect | Details |
|--------|---------|
| Location | `Jobs/Models/ExchangeSymbol/CalculateBtcElasticityJob.php` |
| Purpose | Calculate elasticity_long and elasticity_short |

**Algorithm** (per timeframe):
1. Fetch last N candles for token and BTC
2. Align candles by timestamp
3. For each consecutive candle pair, calculate percentage changes
4. Skip noise (BTC movement below `min_movement_threshold`)
5. Separate elasticities by BTC direction (positive vs negative movement)
6. Average elasticities by direction
7. Store results indexed by timeframe

---

## Token Selection Algorithm

### BTC Bias-Based Selection

| BTC Direction | Position Direction | Desired Correlation | Why |
|---------------|-------------------|---------------------|-----|
| LONG | LONG | Highest positive (→ +1) | Token should rise WITH BTC |
| LONG | SHORT | Highest negative (→ -1) | Token should fall AGAINST BTC |
| SHORT | LONG | Highest negative (→ -1) | Token should rise AGAINST BTC |
| SHORT | SHORT | Highest positive (→ +1) | Token should fall WITH BTC |

**Rules**:
- `BTC direction == position direction` → want **positive** correlation
- `BTC direction != position direction` → want **negative** correlation

---

### assignBestTokenToNewPositions()

| Aspect | Details |
|--------|---------|
| Location | `Concerns/Account/HasTokenDiscovery.php` |
| Purpose | Assign optimal tokens to new positions based on BTC bias |

**Execution Flow**:
1. Get BTC ExchangeSymbol for same api_system and quote
2. Check if BTC has a direction signal - if not, delete all empty slots
3. Load available symbols with complete data for BTC's timeframe
4. Get new positions waiting for token assignment
5. Assign tokens using BTC bias scoring
6. Delete unassigned position slots

---

### selectBestTokenByBtcBias()

| Aspect | Details |
|--------|---------|
| Location | `Concerns/Account/HasTokenDiscovery.php` |
| Purpose | Score and rank symbols using BTC bias + elasticity + correlation |

**Scoring Logic**:

| Scenario | Goal | Filter | Score Formula |
|----------|------|--------|---------------|
| BTC=LONG, Position=LONG | Find tokens that RISE with BTC | Positive correlation | `elasticity_long × correlation` |
| BTC=LONG, Position=SHORT | Find tokens that FALL against BTC | Negative correlation | `|elasticity_short| × |correlation|` |
| BTC=SHORT, Position=LONG | Find tokens that RISE against BTC | Negative correlation | `elasticity_long × |correlation|` |
| BTC=SHORT, Position=SHORT | Find tokens that FALL with BTC | Positive correlation | `|elasticity_short| × correlation` |

---

### Edge Cases

| Scenario | Action |
|----------|--------|
| BTC has no direction | Delete all empty position slots |
| No tokens match correlation sign | Position slot deleted |
| All suitable tokens in use | Remaining slots deleted |
| Token missing timeframe data | Excluded from candidates |

---

### Fast-Track Priority

| Aspect | Details |
|--------|---------|
| Location | `Concerns/Account/HasTokenDiscovery.php → getFastTrackedSymbolForDirection()` |
| Purpose | Prioritize recently profitable positions over elasticity scoring |

**Criteria**:
- Duration < 10 minutes
- Closed within last hour
- Positive PnL

**Priority Order**:
1. Fast-tracked symbol (recently profitable) → Assign immediately
2. Best elasticity-based symbol → Calculate scores, assign best
3. No symbols available → Skip position (deleted by cleanup job)

---

## Configuration

### Correlation Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | true | Global toggle |
| `window_size` | 500 | Candles to analyze |
| `rolling.window_size` | 100 | Sliding window size |
| `rolling.method` | 'recent' | Aggregation method |
| `rolling.step_size` | 10 | Window step size |
| `btc_token` | 'BTC' | Token to correlate against |
| `min_candles` | 0 | Minimum candles required |

### Elasticity Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | true | Global toggle |
| `window_size` | 500 | Candles to analyze |
| `btc_token` | 'BTC' | Token to measure against |
| `min_candles` | 0 | Minimum candles required |
| `min_movement_threshold` | 0.0001 | Minimum BTC % change (0.01%) |

---

## Testing

### Unit Tests

**Location**: `tests/Unit/Account/AssignBestTokenToNewPositionsTest.php`

**Coverage** (12 scenarios):
1. SHORT Assignment with highest elasticity × correlation score
2. No duplicates (batch exclusions)
3. LONG Assignment with highest asymmetry score
4. Mixed directions correctly assigned
5. Fast-track priority over higher elasticity
6. Exclusion of symbols in opened positions
7. No symbols available → skip position
8. Best timeframe selection (not average)
9. Correlation weighting over unreliable high elasticity
10. Inverted correlation asymmetry calculation
11. Downside penalization for LONGs
12. Missing data handling

---

## Performance & Optimization

### Database Indexes

| Index | Purpose |
|-------|---------|
| `candles (exchange_symbol_id, timeframe, timestamp)` | Aligned timestamp queries |
| `exchange_symbols (direction, api_system_id)` | Direction filtering |
| `positions (account_id, status, direction)` | Position queries |

### Calculation Performance

| Metric | Value |
|--------|-------|
| Batch Processing | All symbols calculated in parallel |
| Memory per Symbol | ~5,000 rows (500 candles × 2 symbols × 5 timeframes) |
| Execution Time | ~0.5-2 seconds per symbol per timeframe |

### Data Freshness

- Calculations trigger after each exchange sync (every 1-5 minutes)
- Only USDT pairs calculated (quote_id = 1)
- BTC symbol excluded (cannot correlate with itself)

---

## Real-World Examples

### Example 1: BTC = LONG, Opening LONG Position

```
BTC Direction: LONG (on 4h timeframe)
Position Direction: LONG
Rule: Same direction → Want POSITIVE correlation

Candidates (4h timeframe, positive correlation only):
┌─────────┬───────────────┬─────────────┬─────────┐
│ Token   │ Elasticity_L  │ Correlation │ Score   │
├─────────┼───────────────┼─────────────┼─────────┤
│ ETH     │ 1.2           │ 0.95        │ 1.14    │
│ SOL     │ 2.5           │ 0.85        │ 2.13    │
│ DOGE    │ 3.8           │ 0.72        │ 2.74 ←  │ Winner
└─────────┴───────────────┴─────────────┴─────────┘

Selection: DOGE (highest score)
```

### Example 2: BTC = LONG, Opening SHORT Position

```
BTC Direction: LONG (on 4h timeframe)
Position Direction: SHORT
Rule: Opposite direction → Want NEGATIVE correlation

Candidates (4h timeframe, negative correlation only):
┌─────────┬───────────────┬─────────────┬─────────┐
│ Token   │ Elasticity_S  │ Correlation │ Score   │
├─────────┼───────────────┼─────────────┼─────────┤
│ XYZ     │ 5.2           │ -0.65       │ 3.38    │
│ ABC     │ 8.1           │ -0.82       │ 6.64 ←  │ Winner
│ DEF     │ 12.0          │ -0.35       │ 4.20    │
└─────────┴───────────────┴─────────────┴─────────┘

Selection: ABC (highest score with strong negative correlation)
```

### Example 3: No Suitable Tokens Available

```
BTC Direction: LONG (on 4h timeframe)
Position Direction: SHORT
Rule: Want NEGATIVE correlation

Available tokens (all have positive correlation):
┌─────────┬─────────────┐
│ Token   │ Correlation │
├─────────┼─────────────┤
│ ETH     │ 0.95        │ ← Excluded
│ SOL     │ 0.85        │ ← Excluded
│ DOGE    │ 0.72        │ ← Excluded
└─────────┴─────────────┘

Result: No candidates with negative correlation → Position slot DELETED
```

---

## Troubleshooting

### Positions Not Getting Tokens Assigned

| Check | Query |
|-------|-------|
| Correlation/elasticity calculations running | Count symbols with `btc_elasticity_long` not null |
| Positions have direction | Count new positions with null direction |
| Available symbols in correct direction | Count tradeable symbols by direction |

### Calculations Not Running

| Check | Action |
|-------|--------|
| Config enabled | Verify `config('martingalian.correlation.enabled')` |
| BTC symbol exists | Query symbols table for token='BTC' |
| Candle data available | Count candles by timeframe |

### All Scores Are Zero

| Check | Action |
|-------|--------|
| Correlation values populated | Query first exchange_symbol's btc_correlation_rolling |
| Elasticity values populated | Query first exchange_symbol's btc_elasticity_long |
| Direction filter working | Count exchange_symbols by direction |

---

## Current Integration Status

### Known Issue: Race Condition in Workflow Integration

**Problem**: TriggerCorrelationCalculationsJob may execute before ExchangeSymbols are created.

**Root Cause**: The Step system executes a parent job's `compute()` method before dispatching children. TriggerCorrelationCalculationsJob at index 2 runs after GetAllSymbolsFromExchangeJob's compute() finishes, but BEFORE the nested child jobs create ExchangeSymbols.

**Potential Solutions**:
1. Move trigger to higher index in parent block
2. Create a polling wrapper that waits for ExchangeSymbols
3. Dispatch from separate workflow trigger
4. Move dispatch into UpdateSymbolEligibilityStatusJob (last in chain)
5. Use database observers on ExchangeSymbol creation
6. Redesign as separate cron job after refresh-core-data

---

## Future Enhancements

- Multi-asset correlation (ETH, SOL, not just BTC)
- Time-decay weighting (recent data weighted more)
- Volatility-adjusted elasticity scores
- Machine learning for dynamic threshold tuning
- Real-time elasticity updates (not just on sync)
- Elasticity divergence alerts (changing patterns)
- Correlation breakdown detection (relationship changing)
