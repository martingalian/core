# Technical Indicators System

## Overview
Comprehensive technical analysis system using 12+ indicators to generate trading signals. Integrates with TAAPI.io for real-time indicator calculations, stores historical data for backtesting, and monitors data quality.

## Architecture

### Indicator Types

**RefreshData Indicators** - Live indicators refreshed periodically:
- **RSI** (Relative Strength Index) - Momentum oscillator (0-100)
- **MACD** (Moving Average Convergence Divergence) - Trend following
- **EMA** (Exponential Moving Average) - Trend direction
- **MFI** (Money Flow Index) - Volume-weighted RSI
- **ADX** (Average Directional Index) - Trend strength
- **OBV** (On-Balance Volume) - Volume momentum
- **EMAsConvergence** - Multiple EMA alignment analysis
- **EMAsSameDirection** - EMA trend consistency
- **AmplitudeThresholdIndicator** - Price volatility detection
- **CandleComparisonIndicator** - Candle pattern analysis

**History Indicators** - Historical data storage:
- **CandleIndicator** - OHLCV candle storage and retrieval

**Reports Indicators** - Analytics and monitoring:
- **PriceVolatilityIndicator** - Price movement analysis

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

## Core Models

### Indicator Model
**Location**: `Martingalian\Core\Models\Indicator`
**Purpose**: Registry of available technical indicators

**Schema**:
- `canonical` - Unique identifier (e.g., 'rsi', 'macd', 'ema_50')
- `name` - Display name
- `description` - Indicator explanation
- `category` - RefreshData, History, or Reports
- `parameters` (JSON) - Configuration (periods, thresholds, etc.)
- `is_active` - Whether indicator is enabled
- `priority` - Calculation order

**Contracts**: All indicators implement `Martingalian\Core\Contracts\Indicators\IndicatorContract`

### IndicatorHistory Model
**Location**: `Martingalian\Core\Models\IndicatorHistory`
**Purpose**: Time-series storage of indicator values

**Schema**:
- `exchange_symbol_id` - FK to exchange_symbols
- `indicator_id` - FK to indicators
- `timeframe` - Candle interval (1m, 5m, 15m, 1h, 4h, 1d)
- `timestamp` - Data point time
- `value` (JSON) - Indicator values (varies by indicator type)
- `metadata` (JSON) - Additional context
- `created_at` - When stored

**Example Value Structure**:
```json
// RSI
{"rsi": 45.67}

// MACD
{"macd": 1.23, "signal": 0.98, "histogram": 0.25}

// EMA
{"ema": 45678.90}

// Candle
{"open": 45000, "high": 46000, "low": 44500, "close": 45800, "volume": 1234.56}
```

**Indexes**:
- `exchange_symbol_id` + `indicator_id` + `timeframe` + `timestamp` (composite unique)
- `timestamp` (for range queries)

## Indicator Implementations

### Location
`packages/martingalian/core/src/Indicators/`

### Base Pattern
All indicators extend base class and implement contract:

```php
abstract class BaseIndicator
{
    abstract public function calculate(array $candles): mixed;
    abstract public function getName(): string;
    abstract public function getCanonical(): string;
}
```

### RefreshData Indicators

#### RSI (Relative Strength Index)
**File**: `RefreshData/RSIIndicator.php`
**Purpose**: Measures momentum, identifies overbought (>70) and oversold (<30) conditions
**Parameters**:
- Period (default: 14)
- Timeframe

**Calculation**: Via TAAPI.io `/rsi` endpoint

**Usage**: Identifies reversal points and momentum strength

#### MACD (Moving Average Convergence Divergence)
**File**: `RefreshData/MACDIndicator.php`
**Purpose**: Trend following momentum indicator
**Parameters**:
- Fast period (default: 12)
- Slow period (default: 26)
- Signal period (default: 9)

**Components**:
- MACD line (fast EMA - slow EMA)
- Signal line (9-period EMA of MACD)
- Histogram (MACD - Signal)

**Signals**:
- Bullish: MACD crosses above signal
- Bearish: MACD crosses below signal

#### EMA (Exponential Moving Average)
**File**: `RefreshData/EMAIndicator.php`
**Purpose**: Smoothed trend indicator, more responsive than SMA
**Parameters**: Period (common: 9, 21, 50, 100, 200)

**Multiple EMAs**: System tracks several periods simultaneously
- EMA 9 - Short-term trend
- EMA 21 - Intermediate trend
- EMA 50 - Medium-term trend
- EMA 200 - Long-term trend

#### MFI (Money Flow Index)
**File**: `RefreshData/MFIIndicator.php`
**Purpose**: Volume-weighted RSI, measures buying/selling pressure
**Parameters**: Period (default: 14)

**Range**: 0-100
- Above 80: Overbought
- Below 20: Oversold

#### ADX (Average Directional Index)
**File**: `RefreshData/ADXIndicator.php`
**Purpose**: Measures trend strength (not direction)
**Parameters**: Period (default: 14)

**Interpretation**:
- 0-25: Weak or no trend
- 25-50: Strong trend
- 50-75: Very strong trend
- 75-100: Extremely strong trend

#### OBV (On-Balance Volume)
**File**: `RefreshData/OBVIndicator.php`
**Purpose**: Cumulative volume indicator, confirms price trends
**Calculation**: Running total of volume (add on up days, subtract on down days)

**Usage**: Divergence between OBV and price signals potential reversal

#### EMAsConvergence
**File**: `RefreshData/EMAsConvergence.php`
**Purpose**: Analyzes alignment of multiple EMAs
**Logic**: Checks if EMAs (9, 21, 50, 200) are converging or diverging

**Signal**: Strong trend when all EMAs aligned in same direction

#### EMAsSameDirection
**File**: `RefreshData/EMAsSameDirection.php`
**Purpose**: Confirms all EMAs trending in same direction
**Logic**: Compares slope of each EMA

**Signal**: All EMAs rising = strong uptrend, all falling = strong downtrend

#### AmplitudeThresholdIndicator
**File**: `RefreshData/AmplitudeThresholdIndicator.php`
**Purpose**: Detects significant price movements
**Parameters**: Threshold percentage (e.g., 2%)

**Logic**: Alerts when price moves beyond threshold within period

#### CandleComparisonIndicator
**File**: `RefreshData/CandleComparisonIndicator.php`
**Purpose**: Pattern recognition across candles
**Logic**: Compares current candle to previous candles for patterns

**Patterns Detected**:
- Engulfing patterns
- Doji formations
- Hammer/Shooting star
- Long-body candles

### History Indicators

#### CandleIndicator
**File**: `History/CandleIndicator.php`
**Purpose**: Stores raw OHLCV candle data
**Data**: Open, High, Low, Close, Volume for each timeframe

**Storage**: Used as baseline for all other indicator calculations

### Reports Indicators

#### PriceVolatilityIndicator
**File**: `Reports/PriceVolatilityIndicator.php`
**Purpose**: Measures price volatility over time
**Calculation**: Standard deviation of price changes

**Usage**: Risk assessment, position sizing

## Jobs & Scheduling

### QuerySymbolIndicatorsJob
**Location**: `Jobs/Models/Indicator/QuerySymbolIndicatorsJob.php`
**Frequency**: Every 1-5 minutes (varies by timeframe)
**Purpose**: Fetch fresh indicator data from TAAPI.io

**Flow**:
1. Get active ExchangeSymbols
2. For each symbol/timeframe combination
3. Query TAAPI.io for all active indicators
4. Store results in IndicatorHistory
5. Trigger direction conclusion if conditions met

### CleanupIndicatorHistoriesJob
**Location**: `Jobs/Models/ExchangeSymbol/CleanupIndicatorHistoriesJob.php`
**Purpose**: Remove old RefreshData indicator histories

**Logic**: Keeps only recent data (varies by indicator), deletes rest

**Why**: RefreshData indicators are point-in-time, don't need full history

## Integration with TAAPI.io

### Configuration
```env
TAAPI_SECRET=your_taapi_secret_key
TAAPI_BASE_URL=https://api.taapi.io
```

### API Client
**Location**: `Support/ApiClients/REST/TaapiApiClient.php`
**Methods**:
- `getRSI($symbol, $interval)` → RSI value
- `getMACD($symbol, $interval)` → MACD components
- `getEMA($symbol, $interval, $period)` → EMA value
- `getMultipleIndicators($symbol, $interval, $indicators)` → Batch request

### Rate Limiting
- TAAPI has per-second rate limits
- Throttling via `TaapiExceptionHandler`
- Rate limit detection via HTTP 429
- Exponential backoff on limits

### Error Handling
- Connection failures → retry with backoff
- Invalid symbol → skip and log
- Rate limit → pause and resume
- API key issues → notify admin

## Direction Conclusion

### ConcludeSymbolDirectionAtTimeframeJob
**Location**: `Jobs/Models/ExchangeSymbol/ConcludeSymbolDirectionAtTimeframeJob.php`
**Triggered**: After indicator data refreshed

**Logic**:
1. Load recent IndicatorHistory for symbol/timeframe
2. Analyze indicator values:
   - RSI trend
   - MACD crossovers
   - EMA alignment
   - Volume confirmation (OBV, MFI)
   - Trend strength (ADX)
3. Apply weighting to each indicator
4. Calculate confidence score
5. Determine direction: LONG, SHORT, or NEUTRAL
6. Store conclusion with timestamp

**Output**: Direction recommendation with confidence level

### ConfirmPriceAlignmentWithDirectionJob
**Location**: `Jobs/Lifecycles/ExchangeSymbols/ConfirmPriceAlignmentWithDirectionJob.php`
**Purpose**: Validates that price action confirms indicator direction

**Logic**:
- Compare concluded direction with recent price movement
- Ensure price trending in same direction as indicators
- Invalidate direction if misalignment detected

## Data Quality & Monitoring

### Coherency Checks
- Missing data detection
- Stale indicator alerts (data too old)
- Outlier detection (values outside expected range)
- Gap detection in time series

### Surveillance Jobs
**Location**: `Jobs/Support/Surveillance/`
- Monitor indicator freshness
- Alert on missing critical indicators
- Validate data integrity

### Notifications
- Stale price data → admin notification
- Missing indicator data → admin notification
- API failures → throttled notifications

## Performance Optimization

### Caching Strategy
- Recent indicator values cached (5 minutes)
- Reduces TAAPI.io API calls
- Cache key: `indicator:{symbol}:{timeframe}:{canonical}`

### Batch Requests
- Fetch multiple indicators in single TAAPI.io call
- Reduces latency and API usage
- Group by symbol/timeframe

### Database Optimization
- Partitioned IndicatorHistory by month
- Indexes on common query patterns
- Regular cleanup of old RefreshData

## Configuration

### Indicator Settings
**Location**: `config/martingalian.php` → `indicators`

```php
'indicators' => [
    'refresh_interval' => [
        '1m' => 60,    // 1 minute
        '5m' => 300,   // 5 minutes
        '15m' => 900,  // 15 minutes
        '1h' => 3600,  // 1 hour
    ],

    'history_retention' => [
        'refresh_data' => 7,    // 7 days
        'history' => 365,       // 1 year
        'reports' => 90,        // 90 days
    ],

    'batch_size' => 10,  // Symbols per batch
    'confidence_threshold' => 0.7,  // 70% confidence minimum
],
```

### Timeframe Hierarchy
- 1m - Scalping, noise filtering
- 5m - Short-term signals
- 15m - Intraday trends
- 1h - Short-term position trading
- 4h - Swing trading
- 1d - Position trading

## Testing

### Unit Tests
**Location**: `tests/Unit/Indicators/`
- Test indicator calculations
- Mock TAAPI.io responses
- Validate edge cases

### Integration Tests
**Location**: `tests/Integration/Indicators/`
- Test full indicator refresh cycle
- Validate database storage
- Test direction conclusion logic

## Common Patterns

### Adding New Indicator
1. Create indicator class in appropriate directory
2. Implement `IndicatorContract`
3. Add to `indicators` table via seeder
4. Configure TAAPI.io mapping if external
5. Update `QuerySymbolIndicatorsJob` to fetch
6. Add to direction conclusion logic
7. Write tests

### Debugging Indicators
1. Check TAAPI.io API logs (`ApiRequestLog`)
2. Verify indicator_histories has recent data
3. Check indicator conclusion jobs ran
4. Validate direction conclusions stored
5. Review notification throttling

## Future Enhancements
- Custom indicator formulas (not TAAPI-dependent)
- Machine learning integration
- Real-time streaming indicators
- Advanced pattern recognition
- Multi-timeframe analysis
- Indicator backtesting framework
