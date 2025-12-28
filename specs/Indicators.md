# Technical Indicators System

## Overview

Technical analysis system using 12+ indicators to generate trading signals. Integrates with TAAPI.io for real-time indicator calculations, stores historical data for backtesting, and monitors data quality.

---

## Architecture

### Data Flow

```
TAAPI.io API
    ↓
QuerySymbolIndicatorsJob (scheduled)
    ↓
Fetch indicators for symbol/timeframe
    ↓
IndicatorHistory records created
    ↓
Used for direction conclusion
```

---

## Indicator Types

### By Category

| Category | Type | Description |
|----------|------|-------------|
| Conclude-Indicators | `type: conclude-indicators` | Live indicators for direction conclusion |
| History Indicators | | Historical data storage |
| Reports Indicators | | Analytics and monitoring |

---

### Conclude-Indicators

**Active Indicators**:

| Indicator | Purpose | Cross-Exchange Proof |
|-----------|---------|---------------------|
| MACD | Trend following (signal line crossovers) | Uses close prices only |
| EMA | Trend direction (40, 80, 120 periods) | Uses close prices only |
| ADX | Trend strength measurement | Uses OHLC only |
| EMAsSameDirection | EMA trend consistency | Computed from EMA values |
| CandleComparisonIndicator | Price action confirmation | Uses close prices only |
| Supertrend | ATR-based trend indicator | Uses OHLC only |
| StochRSI | Momentum oscillator with crossovers | Uses close prices only |

**Cross-Exchange Proof**: All active conclude-indicators use only price data (OHLC or close prices), not volume. This ensures consistent signals across exchanges since volume calculations vary by exchange.

**Removed Indicators**:

| Indicator | Reason |
|-----------|--------|
| OBV (On-Balance Volume) | Volume-based - inconsistent results across exchanges |

---

### By Interface/Role

| Interface | Indicators | Purpose |
|-----------|------------|---------|
| DirectionIndicator | EMAsSameDirection, CandleComparisonIndicator, EMA-40/80/120, MACD, Supertrend, StochRSI | Determines LONG/SHORT |
| ValidationIndicator | ADX | Validates market conditions (true/false) |
| Non-Conclusive | CandleIndicator, PriceVolatilityIndicator | Store data without direction |

---

### Computed vs API-Queried

| Type | is_computed | Description | Examples |
|------|-------------|-------------|----------|
| API-Queried | false | Query TAAPI.io directly | ADX, EMA-40, EMA-80, EMA-120 |
| Computed | true | Calculated from other indicators | EMAsSameDirection |

**Order of Processing**: API-queried indicators stored first, then computed indicators processed.

---

## Core Models

### Indicator Model

| Attribute | Description |
|-----------|-------------|
| `canonical` | Unique identifier (e.g., 'rsi', 'macd', 'ema_50') |
| `name` | Display name |
| `description` | Indicator explanation |
| `category` | RefreshData, History, or Reports |
| `parameters` (JSON) | Configuration (periods, thresholds) |
| `is_active` | Whether enabled |
| `priority` | Calculation order |

**Contract**: All indicators implement `IndicatorContract`

---

### IndicatorHistory Model

| Attribute | Description |
|-----------|-------------|
| `exchange_symbol_id` | FK to exchange_symbols |
| `indicator_id` | FK to indicators |
| `timeframe` | Candle interval (1m, 5m, 15m, 1h, 4h, 1d) |
| `timestamp` | Data point time |
| `value` (JSON) | Indicator values (varies by type) |
| `metadata` (JSON) | Additional context |

**Unique Index**: `exchange_symbol_id` + `indicator_id` + `timeframe` + `timestamp`

---

## Indicator Specifications

### RSI (Relative Strength Index)

| Aspect | Details |
|--------|---------|
| Purpose | Measures momentum, identifies overbought/oversold |
| Default Period | 14 |
| Range | 0-100 |
| Overbought | >70 |
| Oversold | <30 |

---

### MACD (Moving Average Convergence Divergence)

| Aspect | Details |
|--------|---------|
| Purpose | Trend following momentum indicator |
| Fast Period | 12 |
| Slow Period | 26 |
| Signal Period | 9 |

**Components**:
- MACD line (fast EMA - slow EMA)
- Signal line (9-period EMA of MACD)
- Histogram (MACD - Signal)

**Signals**:
- Bullish: MACD crosses above signal
- Bearish: MACD crosses below signal

---

### EMA (Exponential Moving Average)

| Aspect | Details |
|--------|---------|
| Purpose | Smoothed trend indicator |
| Periods Used | 9 (short), 21 (intermediate), 50 (medium), 200 (long) |
| Active for Conclusion | 40, 80, 120 |

---

### ADX (Average Directional Index)

| Aspect | Details |
|--------|---------|
| Purpose | Measures trend strength (not direction) |
| Default Period | 14 |

**Interpretation**:

| ADX Value | Meaning |
|-----------|---------|
| 0-25 | Weak or no trend |
| 25-50 | Strong trend |
| 50-75 | Very strong trend |
| 75-100 | Extremely strong trend |

---

### Supertrend

| Aspect | Details |
|--------|---------|
| Type | DirectionIndicator |
| Purpose | ATR-based trend following |
| Default Period | 7 |
| Default Multiplier | 3 |

**TAAPI Response**: `{"value": 37459.26, "valueAdvice": "long"}`
- LONG: valueAdvice === "long"
- SHORT: valueAdvice === "short"

---

### StochRSI (Stochastic RSI)

| Aspect | Details |
|--------|---------|
| Type | DirectionIndicator |
| Purpose | Combines Stochastic with RSI for sensitive momentum detection |
| kPeriod | 5 |
| dPeriod | 3 |
| rsiPeriod | 14 |
| stochasticPeriod | 14 |

**Logic**:
- LONG: FastK crosses above FastD AND FastK < 80
- SHORT: FastK crosses below FastD AND FastK > 20
- null: No clear crossover or extreme conditions

---

### CandleComparisonIndicator

| Aspect | Details |
|--------|---------|
| Type | DirectionIndicator |
| Purpose | Price action confirmation |
| is_computed | false (queries TAAPI) |

**Logic**: Compares close prices between older and newer candles
- Returns LONG if price increased
- Returns SHORT if price decreased

**Volume Handling**: TAAPI returns volume data which is stored but NOT used for direction conclusions.

---

### EMAsSameDirection

| Aspect | Details |
|--------|---------|
| Type | DirectionIndicator |
| Purpose | Confirms all EMAs trending same direction |
| is_computed | true |

**Logic**: Compares slope of each EMA (40, 80, 120)
- All rising = strong uptrend
- All falling = strong downtrend

---

## Jobs & Scheduling

### QuerySymbolIndicatorsJob

| Aspect | Details |
|--------|---------|
| Frequency | Every 1-5 minutes |
| Purpose | Fetch fresh indicator data from TAAPI.io |

**Flow**:
1. Get active ExchangeSymbols
2. For each symbol/timeframe combination
3. Query TAAPI.io for all active indicators
4. Store results in IndicatorHistory
5. Trigger direction conclusion

---

### QuerySymbolIndicatorsBulkJob

| Aspect | Details |
|--------|---------|
| Purpose | Batch-query indicators in single TAAPI API call |

**Constructor Parameters**:
- `exchangeSymbolIds` (array)
- `timeframe` (string)
- `shouldCleanup` (bool)

**TAAPI Plan Limits**:

| Plan | Constructs per Request |
|------|----------------------|
| Pro | 3 |
| Expert | 10 |
| Max | 20 |

---

### CleanupIndicatorHistoriesJob

| Aspect | Details |
|--------|---------|
| Purpose | Remove old conclude-indicators histories |
| Reason | Conclude-indicators are point-in-time, don't need full history |

---

## TAAPI.io Integration

### Configuration

| Setting | Value |
|---------|-------|
| TAAPI_SECRET | API key |
| TAAPI_BASE_URL | https://api.taapi.io |

### Rate Limiting

- Per-second rate limits enforced
- Throttling via `TaapiExceptionHandler`
- Rate limit detection via HTTP 429
- Exponential backoff on limits

### Error Handling

| Error | Action |
|-------|--------|
| Connection failure | Retry with backoff |
| Invalid symbol | Skip and log |
| Rate limit | Pause and resume |
| API key issues | Notify admin |

---

## Direction Conclusion

### ConcludeSymbolDirectionAtTimeframeJob

| Aspect | Details |
|--------|---------|
| Trigger | After indicator data refreshed |
| Output | Direction (LONG, SHORT, NEUTRAL) with confidence |

**Logic**:
1. Load recent IndicatorHistory for symbol/timeframe
2. Analyze indicator values
3. Apply weighting to each indicator
4. Calculate confidence score
5. Determine direction

**Note**: Volume-based indicators not used due to cross-exchange inconsistencies.

---

## Bulk Candle Fetching System

### FetchAndStoreCandlesBulkJob

| Aspect | Details |
|--------|---------|
| Purpose | Fetch candles for multiple symbols in single request |
| Max Results | 20 per symbol (bulk limit) |

**Response Formats Handled**:
- Column format (default)
- Row format (array of objects)

---

### StoreCandlesCommand

| Option | Description |
|--------|-------------|
| `--results=` | Candles per symbol (default: 20) |
| `--exchange-symbol-id=` | Specific symbol ID |
| `--clean` | Truncate tables before running |
| `--legacy` | Use single-request jobs (up to 500 results) |

---

### Candle Model

| Attribute | Description |
|-----------|-------------|
| `exchange_symbol_id` | FK to exchange_symbols |
| `timeframe` | Interval (1h, 4h, 1d) |
| `timestamp` | Unix timestamp |
| `candle_time` | DateTime representation |
| `open`, `high`, `low`, `close` | Price data |
| `volume` | Trading volume |

---

## Configuration

### Indicator Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `refresh_interval.1m` | 60s | 1-minute refresh |
| `refresh_interval.5m` | 300s | 5-minute refresh |
| `refresh_interval.1h` | 3600s | 1-hour refresh |
| `history_retention.refresh_data` | 7 days | Data retention |
| `history_retention.history` | 365 days | History retention |
| `batch_size` | 10 | Symbols per batch |
| `confidence_threshold` | 0.7 | Minimum confidence |

### Timeframe Hierarchy

| Timeframe | Use Case |
|-----------|----------|
| 1m | Scalping, noise filtering |
| 5m | Short-term signals |
| 15m | Intraday trends |
| 1h | Short-term position trading |
| 4h | Swing trading |
| 1d | Position trading |

---

## Testing

### Unit Tests

**Location**: `tests/Unit/Indicators/`

**Coverage**: Indicator calculations, mock TAAPI responses, edge cases

### Integration Tests

**Location**: `tests/Integration/Indicators/`

**Coverage**: Full refresh cycle, database storage, direction conclusion

---

## Adding New Indicators

1. Create indicator class in appropriate directory
2. Implement `IndicatorContract`
3. Add to `indicators` table via seeder
4. Configure TAAPI.io mapping if external
5. Update `QuerySymbolIndicatorsJob` to fetch
6. Add to direction conclusion logic
7. Write tests

---

## Debugging Indicators

| Step | Check |
|------|-------|
| 1 | TAAPI.io API logs (`ApiRequestLog`) |
| 2 | indicator_histories has recent data |
| 3 | Direction conclusion jobs ran |
| 4 | Direction conclusions stored |
| 5 | Notification throttling |

---

## Future Enhancements

- Custom indicator formulas (not TAAPI-dependent)
- Machine learning integration
- Real-time streaming indicators
- Advanced pattern recognition
- Multi-timeframe analysis
- Indicator backtesting framework
