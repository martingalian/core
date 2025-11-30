# Technical Indicators System

## Overview
Comprehensive technical analysis system using 12+ indicators to generate trading signals. Integrates with TAAPI.io for real-time indicator calculations, stores historical data for backtesting, and monitors data quality.

## Architecture

### Indicator Types

#### By Category

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
- **CandleComparisonIndicator** - Price action confirmation

**History Indicators** - Historical data storage:
- **CandleIndicator** - OHLCV candle storage and retrieval

**Reports Indicators** - Analytics and monitoring:
- **PriceVolatilityIndicator** - Price movement analysis

#### By Interface/Role

Indicators implement specific interfaces to define their role in direction conclusion:

**DirectionIndicator** - Determines market direction (LONG/SHORT):
- **EMAsSameDirection** - Computed indicator analyzing all EMA trends
- **CandleComparisonIndicator** - Price action validation
- **OBV** - Volume-based direction
- **EMA-40, EMA-80, EMA-120** - Individual EMA trend directions

**ValidationIndicator** - Validates market conditions (true/false):
- **ADX** - Confirms sufficient trend strength for trading

**Non-Conclusive Indicators** - Store data without providing direction/validation:
- **CandleIndicator** (History) - Stores raw candle data
- **PriceVolatilityIndicator** (Reports) - Analytics only

#### Computed vs API-Queried

**API-Queried Indicators** (`is_computed = false`):
- Query TAAPI.io directly for indicator values
- Examples: ADX, OBV, EMA-40, EMA-80, EMA-120, CandleComparisonIndicator
- Stored first in QuerySymbolIndicatorsJob

**Computed Indicators** (`is_computed = true`):
- Calculated from other indicators' results
- Do NOT query TAAPI - receive indicator data as input
- Examples: EMAsSameDirection (analyzes EMA-40, EMA-80, EMA-120)
- Processed after API-queried indicators in QuerySymbolIndicatorsJob

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
**Type**: DirectionIndicator
**Purpose**: Price action confirmation - ensures price movement agrees with other indicators
**Logic**: Compares close prices between older and newer candles

**Implementation**:
- Queries TAAPI `candle` endpoint with `results=2` parameter
- Receives columnar format: `{"close": [older, newer], "open": [older, newer], ...}`
- Compares `close[0]` (older) vs `close[1]` (newer)
- Returns LONG if price increased, SHORT if price decreased

**Role in Direction Conclusion**:
Acts as final validation that price action aligns with other directional indicators. If all other indicators say LONG but price is falling (SHORT), the symbol becomes INCONCLUSIVE, preventing trades against actual price movement.

**Configuration**:
- `is_computed`: false (queries TAAPI directly, not computed from other indicators)
- `parameters`: `{"results": 2}` (fetch 2 most recent candles)

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
**Purpose**: Fetch fresh indicator data from TAAPI.io for a single symbol

**Flow**:
1. Get active ExchangeSymbols
2. For each symbol/timeframe combination
3. Query TAAPI.io for all active indicators
4. Store results in IndicatorHistory
5. Trigger direction conclusion if conditions met

### QuerySymbolIndicatorsBulkJob
**Location**: `Jobs/Models/Indicator/QuerySymbolIndicatorsBulkJob.php`
**Purpose**: Batch-query indicator data for multiple exchange symbols in a single TAAPI API call

**Constructor Parameters**:
- `exchangeSymbolIds` (array) - Array of ExchangeSymbol IDs to process
- `timeframe` (string) - Indicator timeframe (1h, 4h, 1d, etc.)
- `shouldCleanup` (bool) - Whether to cleanup indicator histories after conclusion (default: true)

**Flow**:
1. Load ExchangeSymbols from provided IDs with relationships
2. Load active non-computed indicators (type: refresh-data)
3. Build bulk request constructs (one per symbol, all indicators)
4. Send single POST request to TAAPI `/bulk` endpoint
5. Parse response and match indicators by endpoint + period
6. Upsert results to IndicatorHistory
7. Process computed indicators using fetched data
8. Create `ConcludeSymbolDirectionAtTimeframeJob` steps for each symbol

**Construct Format**:
```php
[
    'id' => (string) $exchangeSymbol->id,
    'exchange' => 'binancefutures',
    'symbol' => 'BTC/USDT',
    'interval' => '1h',
    'indicators' => [
        ['indicator' => 'ema', 'period' => 40],
        ['indicator' => 'ema', 'period' => 80],
        ['indicator' => 'adx', 'period' => 14],
        // ... all active indicators
    ],
]
```

**Response ID Parsing**:
Response IDs follow format: `binancefutures_BTC/USDT_1h_ema_40_2_1`
- Parses exchange, symbol, interval from ID parts
- Matches indicator by endpoint (e.g., 'ema') and optional period parameter
- Uses `BaseAssetMapper` for symbol token mapping

**Computed Indicators**:
After fetching API indicators, processes computed indicators (e.g., `EMAsSameDirection`):
- Collects all fetched indicator data per symbol
- Instantiates computed indicator with all indicator data
- Stores computed conclusion to IndicatorHistory

**TAAPI Plan Limits** (constructs per request):
- Pro: 3 constructs
- Expert: 10 constructs
- Max: 20 constructs

**Error Handling**:
- Plan limit errors ("constructs than your plan allows") are NOT ignored - job fails to alert about configuration issue
- Invalid symbols or missing data logged but processing continues
- Returns result array with stored count, errors, total responses

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

## Bulk Candle Fetching System

### Overview
Efficient bulk candle data fetching using TAAPI's `/bulk` endpoint. This system replaces individual candle requests with batched requests, significantly reducing API calls and improving throughput.

### Components

#### FetchAndStoreCandlesBulkJob
**Location**: `Jobs/Models/Indicator/FetchAndStoreCandlesBulkJob.php`
**Purpose**: Fetch candle data for multiple exchange symbols in a single TAAPI bulk request

**Constructor Parameters**:
- `exchangeSymbolIds` (array) - Array of ExchangeSymbol IDs to fetch candles for
- `timeframe` (string) - Candle timeframe (1h, 4h, 1d, etc.)
- `results` (int) - Number of candles to fetch per symbol (max 20 for bulk)
- `backtrack` (int) - Number of candles to skip (for pagination)

**Flow**:
1. Load ExchangeSymbols from provided IDs
2. Build bulk request constructs (one per symbol)
3. Send single POST request to TAAPI `/bulk` endpoint
4. Parse response (handles both column and row formats)
5. Upsert candles to database (no duplicates on same timestamp)
6. Return statistics: stored count, errors, symbols processed

**Response Handling**:
The job handles two TAAPI response formats:

```php
// Column format (default)
'result' => [
    'timestamp' => [1764446400, 1764442800],
    'open' => [50000.0, 49900.0],
    'high' => [50100.0, 50000.0],
    // ...
]

// Row format (array of objects)
'result' => [
    ['timestamp' => 1764446400, 'open' => 50000.0, ...],
    ['timestamp' => 1764442800, 'open' => 49900.0, ...],
]
```

**Symbol Matching**:
Uses `BaseAssetMapper` to match TAAPI response IDs back to exchange symbols:
- Response ID format: `binancefutures_BTC/USDT_1h_candle_20_0_true`
- Extracts token from ID using regex
- Maps token to exchange_symbol_id via `BaseAssetMapper::getTaapiCanonical()`

**Upsert Logic**:
- Uses `updateOrCreate()` with composite key: `exchange_symbol_id` + `timestamp` + `timeframe`
- Updates existing candles with new data (no duplicates)
- Creates `candle_time` from Unix timestamp

#### StoreCandlesCommand
**Location**: `App/Console/Commands/Cronjobs/Taapi/StoreCandlesCommand.php`
**Signature**: `taapi:store-candles`

**Options**:
- `--results=` - Number of candles to fetch (default: 20, max 20 for bulk)
- `--exchange-symbol-id=` - Optional specific symbol ID to fetch
- `--clean` - Truncate tables and clear logs before running (includes `horizon:terminate`)
- `--legacy` - Use legacy single-request jobs instead of bulk API

**Modes**:

1. **Bulk Mode (default)** - Uses `FetchAndStoreCandlesBulkJob`
   - Groups symbols into chunks (default 10 symbols per bulk request)
   - Creates one Step per chunk per timeframe
   - Much more efficient: 10 symbols = 1 API call instead of 10

2. **Legacy Mode (`--legacy`)** - Uses `FetchAndStoreOnCandleJob`
   - Individual job per symbol/timeframe
   - Supports up to 500 results per request
   - Use when needing more than 20 candles per symbol

**Example Usage**:
```bash
# Bulk mode (default) - fetch 20 candles for all eligible symbols
php artisan taapi:store-candles

# Fetch specific symbol
php artisan taapi:store-candles --exchange-symbol-id=1

# Clean run (truncate data first)
php artisan taapi:store-candles --clean

# Legacy mode for more than 20 candles
php artisan taapi:store-candles --legacy --results=100
```

#### Candle Model
**Location**: `Martingalian/Core/Models/Candle.php`
**Purpose**: Stores OHLCV candle data

**Schema**:
- `exchange_symbol_id` - FK to exchange_symbols
- `timeframe` - Candle interval (1h, 4h, 1d, etc.)
- `timestamp` - Unix timestamp
- `candle_time` - DateTime representation
- `open`, `high`, `low`, `close` - Price data (decimal)
- `volume` - Trading volume

**Factory**: `Martingalian/Core/Database/Factories/CandleFactory`

**Factory States**:
- `hourly()` - 1h timeframe
- `fourHourly()` - 4h timeframe
- `daily()` - 1d timeframe
- `atTimestamp(int $timestamp)` - Set specific timestamp

### Configuration

```php
// config/martingalian.php
'candles' => [
    'default_results' => 20,           // Default candles per symbol
    'bulk_max_results' => 20,          // TAAPI bulk limit per construct
],

'throttlers' => [
    'taapi' => [
        'bulk_constructs_limit' => 10, // Max symbols per bulk request
    ],
],
```

### Testing

**Test File**: `tests/Feature/Jobs/FetchAndStoreCandlesBulkJobTest.php`

**Test Cases**:
1. `stores candles from TAAPI bulk API response` - Happy path
2. `does not duplicate candles on re-run with same timestamps` - Upsert verification
3. `adds new candles for new timestamps without affecting existing` - Incremental updates
4. `handles multiple exchange symbols in single bulk request` - Batch processing
5. `handles TAAPI response with row format (array of objects)` - Alternate format
6. `returns empty result when no exchange symbols provided` - Edge case
7. `stores candles with correct timeframe value` - Timeframe verification

**Test Pattern** (Data Isolation):
```php
// Create unique fixture per test
$exchangeSymbol = createExchangeSymbolForBulkTest('UNIQUE_TOKEN');

// Query by specific identifiers, NOT global counts
$candles = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
    ->where('timeframe', '1h')
    ->get();
expect($candles)->toHaveCount(3);

// Verify exact values
expect((float) $candle->open)->toBe(50000.0);
```

### API Efficiency

**Before (Legacy Mode)**:
- 50 symbols × 5 timeframes = 250 API calls
- Each call fetches one symbol's candles

**After (Bulk Mode)**:
- 50 symbols / 10 per chunk = 5 chunks
- 5 chunks × 5 timeframes = 25 API calls
- **90% reduction in API calls**

### Error Handling

- Missing exchange symbols → Returns error in result array
- API failures → Handled by `TaapiExceptionHandler`
- Malformed response → Logged, continues with valid data
- Symbol matching failure → Skipped, logged for debugging

## Future Enhancements
- Custom indicator formulas (not TAAPI-dependent)
- Machine learning integration
- Real-time streaming indicators
- Advanced pattern recognition
- Multi-timeframe analysis
- Indicator backtesting framework
