# BTC Correlation & Elasticity Analysis System

## Overview
Multi-timeframe statistical analysis system that measures how tokens move relative to Bitcoin. Calculates correlation (strength of relationship) and elasticity (amplification/dampening) to identify asymmetric risk/reward profiles for optimal position selection.

## Core Concepts

### Correlation
Measures the strength and direction of the linear relationship between a token's price and BTC's price.

**Types Calculated**:
- **Pearson Correlation**: Linear relationship (-1 to 1)
- **Spearman Correlation**: Rank-based relationship (-1 to 1)
- **Rolling Correlation**: Windowed Pearson correlation over recent candles

**Interpretation**:
- `1.0`: Perfect positive correlation (moves exactly with BTC)
- `0.0`: No correlation (independent movement)
- `-1.0`: Perfect negative correlation (moves opposite to BTC)

**Window Configurations**:
- `window_size`: Total candles analyzed (default: 500)
- `rolling.window_size`: Sliding window size (default: 100)
- `rolling.method`: Aggregation method - 'recent' (latest window only), 'average', or 'weighted'
- `rolling.step_size`: Window sliding step (default: 10 candles)

### Elasticity
Measures how much a token's percentage price change amplifies or dampens relative to BTC's percentage change.

**Formula**: `Elasticity = (Token % Change) / (BTC % Change)`

**Two Directional Metrics**:
- **elasticity_long**: Average elasticity during BTC upward movements (BTC % change > 0)
- **elasticity_short**: Average elasticity during BTC downward movements (BTC % change < 0)

**Interpretation**:
- `elasticity > 1`: Token amplifies BTC movement (moves more than BTC)
- `elasticity = 1`: Token moves exactly with BTC
- `elasticity < 1`: Token dampens BTC movement (moves less than BTC)
- `elasticity < 0`: **Inverted correlation** - token moves opposite to BTC

**Examples**:
- `elasticity_long = 2.5`: When BTC rises 1%, token rises 2.5% (2.5x upside amplification)
- `elasticity_short = 3.0`: When BTC falls 1%, token falls 3% (3x downside amplification)
- `elasticity_short = -10.0`: When BTC falls 1%, token **RISES** 10% (inverted, defensive)

### Asymmetry (Risk/Reward Profile)
**Formula**: `Asymmetry = elasticity_long - elasticity_short`

**Use Case**: Identifies tokens with favorable LONG profiles

**Examples**:
- `Asymmetry = 10 - 2 = 8`: High upside (10x), low downside (2x) → **Excellent LONG candidate**
- `Asymmetry = 5 - (-10) = 15`: Moderate upside (5x), rises when BTC falls (-10x) → **Premium LONG candidate**
- `Asymmetry = 2 - 8 = -6`: Low upside (2x), high downside (8x) → **Poor LONG candidate**

## Architecture

### Data Flow

```
┌────────────────────────────────────────────────────────────────────────┐
│ RefreshCoreDataCommand (Cron Job)                                      │
│ - Creates DiscoverExchangeSymbolsJob for each exchange                 │
└───────────────────────────────┬────────────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────────────┐
│ DiscoverExchangeSymbolsJob (Block A - Parent)                          │
│ - Index 1: GetAllSymbolsFromExchangeJob (creates UpsertSymbol jobs)    │
│ - Index 2: TriggerCorrelationCalculationsJob (queries ExchangeSymbols) │
└───────────────────────────────┬────────────────────────────────────────┘
                                │
                    ┌───────────┴───────────┐
                    ▼                       ▼
┌──────────────────────────────┐  ┌─────────────────────────────────────┐
│ GetAllSymbolsFromExchangeJob │  │ TriggerCorrelationCalculationsJob   │
│ (Block A.1 - Child Block B)  │  │ (Block A.2)                         │
│                              │  │                                     │
│ Creates per-symbol:          │  │ Queries exchange_symbols table      │
│ - UpsertSymbolEligibilityJob │  │ Creates correlation/elasticity jobs │
│   (Index 1, Block B)         │  │ for all USDT symbols                │
│                              │  │                                     │
│ Each UpsertSymbolEligibility │  │ ⚠️ RACE CONDITION:                  │
│ creates child block C with:  │  │ Runs at index 2 in parent block,   │
│ - Index 1: UpsertSymbolJob   │  │ but ExchangeSymbols created in     │
│ - Index 2: UpsertExchange    │  │ child blocks (C) at different      │
│         SymbolJob ← Creates! │  │ timing! Job sees 0 symbols.        │
│ - Index 3: CheckEligibility  │  │                                     │
│ - Index 4: UpdateStatus      │  │                                     │
└──────────────────────────────┘  └─────────────────────────────────────┘
                                              │
                                              ▼
                                  ⚠️ CURRENT ISSUE: Returns 0 jobs created
                                     because ExchangeSymbols don't exist yet
                                     when this job executes


## Core Models

### ExchangeSymbol Model
**Location**: `Martingalian\Core\Models\ExchangeSymbol`
**New Columns**:

```php
// Correlation metrics (JSON arrays indexed by timeframe)
'btc_correlation_pearson' => ['1h' => 0.87, '4h' => 0.92, '6h' => 0.88, ...]
'btc_correlation_spearman' => ['1h' => 0.85, '4h' => 0.90, '6h' => 0.86, ...]
'btc_correlation_rolling' => ['1h' => 0.89, '4h' => 0.91, '6h' => 0.87, ...]

// Elasticity metrics (JSON arrays indexed by timeframe)
'btc_elasticity_long' => ['1h' => 2.5, '4h' => 3.1, '6h' => 2.8, ...]
'btc_elasticity_short' => ['1h' => 1.8, '4h' => 2.2, '6h' => 1.9, ...]
```

**Migration**: `2025_01_14_000001_add_btc_correlation_elasticity_to_exchange_symbols.php`

### Candle Model
**Location**: `Martingalian\Core\Models\Candle`
**Used By**: Correlation and elasticity calculations
**Schema**:
- `exchange_symbol_id` - FK to exchange_symbols
- `timeframe` - '1h', '4h', '6h', '12h', '1d'
- `timestamp` - Candle open time
- `open`, `high`, `low`, `close`, `volume` - OHLCV data

**Critical Index**: `(exchange_symbol_id, timeframe, timestamp)` for fast aligned queries

## Jobs & Implementation

### TriggerCorrelationCalculationsJob
**Location**: `Jobs/Models/ApiSystem/TriggerCorrelationCalculationsJob.php`
**Trigger**: After exchange sync complete (last job in sync pipeline)
**Purpose**: Creates parallel calculation jobs for all USDT symbols

**Logic**:
```php
public function compute()
{
    // Check if either feature enabled
    if (!config('martingalian.correlation.enabled')
        && !config('martingalian.elasticity.enabled')) {
        return ['skipped' => true];
    }

    // For each USDT symbol on this exchange
    ExchangeSymbol::where('api_system_id', $this->apiSystemId)
        ->where('quote_id', 1) // USDT only
        ->each(function ($symbol) {
            // Create correlation job if enabled
            if (config('martingalian.correlation.enabled')) {
                Step::create([
                    'class' => CalculateBtcCorrelationJob::class,
                    'block_uuid' => $this->step->block_uuid,
                    'index' => $this->step->index + 1, // All run parallel
                    'arguments' => ['exchangeSymbolId' => $symbol->id],
                ]);
            }

            // Create elasticity job if enabled
            if (config('martingalian.elasticity.enabled')) {
                Step::create([
                    'class' => CalculateBtcElasticityJob::class,
                    'block_uuid' => $this->step->block_uuid,
                    'index' => $this->step->index + 1, // All run parallel
                    'arguments' => ['exchangeSymbolId' => $symbol->id],
                ]);
            }
        });
}
```

### CalculateBtcCorrelationJob
**Location**: `Jobs/Models/ExchangeSymbol/CalculateBtcCorrelationJob.php`
**Purpose**: Calculate Pearson, Spearman, and Rolling correlations between token and BTC

**Algorithm** (per timeframe):
1. Fetch last N candles for token and BTC (N = `window_size`)
2. Align candles by timestamp (only use common timestamps)
3. Extract close prices into arrays
4. Calculate Pearson correlation using standard formula
5. Calculate Spearman correlation (rank-based)
6. Calculate Rolling correlation using sliding window
7. Store results indexed by timeframe

**Configuration**:
```php
config('martingalian.correlation') => [
    'enabled' => true,
    'window_size' => 500,  // Total candles to analyze
    'rolling' => [
        'window_size' => 100,     // Sliding window size
        'method' => 'recent',     // 'recent', 'average', or 'weighted'
        'step_size' => 10,        // Window step size
    ],
    'btc_token' => 'BTC',
    'min_candles' => 0,  // Minimum candles required (0 = calculate with available)
]
```

**Output Example**:
```php
[
    'exchange_symbol_id' => 42,
    'symbol' => 'ADA',
    'timeframes_calculated' => 5,
    'timeframes' => [
        '1h' => [
            'pearson' => 0.8654,
            'spearman' => 0.8432,
            'rolling' => 0.8721,
            'candles_analyzed' => 500,
        ],
        '4h' => [...],
    ]
]
```

### CalculateBtcElasticityJob
**Location**: `Jobs/Models/ExchangeSymbol/CalculateBtcElasticityJob.php`
**Purpose**: Calculate elasticity_long and elasticity_short for each timeframe

**Algorithm** (per timeframe):
1. Fetch last N candles for token and BTC (N = `window_size`)
2. Align candles by timestamp
3. For each consecutive candle pair:
   ```php
   $tokenPctChange = ($currClose - $prevClose) / $prevClose;
   $btcPctChange = ($currClose - $prevClose) / $prevClose;

   // Skip noise (BTC movement too small)
   if (abs($btcPctChange) < $config['min_movement_threshold']) continue;

   // Calculate elasticity
   $elasticity = $tokenPctChange / $btcPctChange;

   // Separate by BTC direction
   if ($btcPctChange > 0) {
       $longElasticities[] = $elasticity;  // BTC went UP
   } else {
       $shortElasticities[] = $elasticity; // BTC went DOWN
   }
   ```
4. Average elasticities by direction:
   ```php
   $elasticity_long = avg($longElasticities);
   $elasticity_short = avg($shortElasticities);
   ```
5. Store results indexed by timeframe

**Configuration**:
```php
config('martingalian.elasticity') => [
    'enabled' => true,
    'window_size' => 500,
    'btc_token' => 'BTC',
    'min_candles' => 0,
    'min_movement_threshold' => 0.0001,  // 0.01% minimum BTC movement
]
```

**Output Example**:
```php
[
    'exchange_symbol_id' => 42,
    'symbol' => 'SQD',
    'timeframes_calculated' => 3,
    'timeframes' => [
        '1d' => [
            'elasticity_long' => -0.97,      // Falls 0.97% when BTC rises 1%
            'elasticity_short' => 25.96,     // Falls 25.96% when BTC falls 1%
            'movements_analyzed_long' => 124,
            'movements_analyzed_short' => 118,
        ],
        '1h' => [...],
    ]
]
```

## Token Selection Algorithm

### assignBestTokenToNewPositions()
**Location**: `Concerns/Account/HasTokenDiscovery.php`
**Purpose**: Assign optimal tokens to new positions based on elasticity and correlation

**Execution Flow**:

```php
public function assignBestTokenToNewPositions()
{
    // STEP 1: Load available symbols (excludes already opened positions)
    $this->availableExchangeSymbols = $this->availableExchangeSymbols();

    // STEP 2: Filter to only symbols with complete data
    $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(
        fn($s) => filled($s->btc_elasticity_long)
               && filled($s->btc_elasticity_short)
               && filled($s->btc_correlation_rolling)
    );

    // STEP 3: Get all new positions with direction set but no token
    $newPositions = $this->positions()
        ->where('status', 'new')
        ->whereNotNull('direction')
        ->whereNull('exchange_symbol_id')
        ->get();

    // STEP 4: Track batch exclusions (prevent duplicates)
    $batchExclusions = [];

    // STEP 5: Iterate each position and assign best token
    foreach ($newPositions as $position) {
        $bestToken = null;

        // PRIORITY 1: Fast-tracked symbols (recently profitable)
        $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection(
            $position->direction,
            $batchExclusions
        );
        if ($fastTrackedSymbol) {
            $bestToken = $fastTrackedSymbol;
        }

        // PRIORITY 2: Best elasticity-based symbol
        if (!$bestToken) {
            $bestToken = $this->selectBestTokenByElasticity(
                $position->direction,
                $batchExclusions
            );
        }

        // Skip if no token available
        if (!$bestToken) continue;

        // Assign token and add to exclusions
        $position->updateSaving([
            'exchange_symbol_id' => $bestToken->id,
            'direction' => $bestToken->direction,
        ]);
        $batchExclusions[] = $bestToken->id;
    }
}
```

### selectBestTokenByElasticity()
**Location**: `Concerns/Account/HasTokenDiscovery.php`
**Purpose**: Score and rank symbols by elasticity for given direction

**Scoring Formulas**:

#### SHORT Positions
```php
// Goal: Maximize downside amplification, weighted by correlation reliability
$score = abs($elasticity_short) × abs($correlation_rolling)
```

**Logic**: We want tokens that fall HARD when BTC falls (high `elasticity_short`), but only if the relationship is reliable (high `correlation`).

**Example**:
- SQD: `elasticity_short = 25.96`, `correlation = 0.48` → Score = `25.96 × 0.48 = 12.46` ✅ **Winner**
- VOLATILE: `elasticity_short = 30.0`, `correlation = 0.1` → Score = `30.0 × 0.1 = 3.0` ❌ Unreliable

#### LONG Positions
```php
// Goal: Maximize upside capture while minimizing downside risk
$asymmetry = $elasticity_long - $elasticity_short;
$score = $asymmetry × abs($correlation_rolling)
```

**Logic**: We want tokens with high upside (`elasticity_long`) and low downside (`elasticity_short`). Inverted correlations (negative `elasticity_short`) are premium candidates.

**Examples**:
- MYX: `elasticity_long = 29.3`, `elasticity_short = -57.4`, `correlation = 0.52`
  - Asymmetry = `29.3 - (-57.4) = 86.7`
  - Score = `86.7 × 0.52 = 45.08` ✅ **Premium LONG** (rises when BTC falls!)

- SAFE: `elasticity_long = 6.0`, `elasticity_short = 0.5`, `correlation = 0.8`
  - Asymmetry = `6.0 - 0.5 = 5.5`
  - Score = `5.5 × 0.8 = 4.4` ✅ **Good LONG** (high upside, low downside)

- RISKY: `elasticity_long = 10.0`, `elasticity_short = 9.0`, `correlation = 0.8`
  - Asymmetry = `10.0 - 9.0 = 1.0`
  - Score = `1.0 × 0.8 = 0.8` ❌ **Poor LONG** (high downside risk)

**Best Timeframe Selection**:
```php
// For each symbol, evaluate ALL timeframes and take the BEST score
$timeframes = ['1h', '4h', '6h', '12h', '1d'];
$bestScore = 0;
$bestTimeframe = null;

foreach ($timeframes as $timeframe) {
    $score = calculateScore($symbol, $timeframe, $direction);
    if ($score > $bestScore) {
        $bestScore = $score;
        $bestTimeframe = $timeframe;
    }
}

return $bestScore; // Use symbol's best timeframe, not average
```

### Fast-Track Priority
**Location**: `Concerns/Account/HasTokenDiscovery.php → getFastTrackedSymbolForDirection()`
**Purpose**: Prioritize recently profitable positions over elasticity scoring

**Logic**:
```php
// Fast-tracked = recently closed profitable positions
// Duration < 10 minutes, closed within last hour
$fastTracked = $this->fastTrackedPositions()->where('direction', $direction);

// Return first available fast-tracked symbol not in batch exclusions
foreach ($fastTracked as $trackedPosition) {
    if (!in_array($trackedPosition->exchange_symbol_id, $batchExclusions)) {
        return $availableSymbols->firstWhere('id', $trackedPosition->exchange_symbol_id);
    }
}
```

**Priority Order**:
1. ✅ Fast-tracked symbol (recently profitable) → **Assign immediately**
2. ✅ Best elasticity-based symbol → **Calculate scores, assign best**
3. ❌ No symbols available → **Skip position** (will be deleted by cleanup job)

## Configuration

### Correlation Settings
**Location**: `config/martingalian.php → correlation`

```php
'correlation' => [
    'enabled' => (bool) env('CORRELATION_ENABLED', true),
    'window_size' => (int) env('CORRELATION_WINDOW_SIZE', 500),
    'rolling' => [
        'window_size' => (int) env('CORRELATION_ROLLING_WINDOW_SIZE', 100),
        'method' => env('CORRELATION_ROLLING_METHOD', 'recent'),
        'step_size' => (int) env('CORRELATION_ROLLING_STEP_SIZE', 10),
    ],
    'btc_token' => env('CORRELATION_BTC_TOKEN', 'BTC'),
    'min_candles' => (int) env('CORRELATION_MIN_CANDLES', 0),
],
```

**Parameters**:
- `enabled`: Global toggle for correlation calculation
- `window_size`: Number of candles to analyze per timeframe (default: 500)
- `rolling.window_size`: Size of sliding window for rolling correlation (default: 100)
- `rolling.method`: Aggregation method - 'recent', 'average', or 'weighted'
- `rolling.step_size`: Sliding step size (1 = every candle, 10 = every 10th candle)
- `btc_token`: Token to correlate against (default: 'BTC')
- `min_candles`: Minimum candles required before calculating (0 = use available data)

### Elasticity Settings
**Location**: `config/martingalian.php → elasticity`

```php
'elasticity' => [
    'enabled' => (bool) env('ELASTICITY_ENABLED', true),
    'window_size' => (int) env('ELASTICITY_WINDOW_SIZE', 500),
    'btc_token' => env('ELASTICITY_BTC_TOKEN', 'BTC'),
    'min_candles' => (int) env('ELASTICITY_MIN_CANDLES', 0),
    'min_movement_threshold' => (float) env('ELASTICITY_MIN_MOVEMENT_THRESHOLD', 0.0001),
],
```

**Parameters**:
- `enabled`: Global toggle for elasticity calculation
- `window_size`: Number of candles to analyze per timeframe (default: 500)
- `btc_token`: Token to measure elasticity against (default: 'BTC')
- `min_candles`: Minimum candles required (0 = use available data)
- `min_movement_threshold`: Minimum BTC % change to include in calculation (default: 0.01%)

### Environment Variables

```env
# Correlation
CORRELATION_ENABLED=true
CORRELATION_WINDOW_SIZE=500
CORRELATION_ROLLING_WINDOW_SIZE=100
CORRELATION_ROLLING_METHOD=recent
CORRELATION_ROLLING_STEP_SIZE=10
CORRELATION_BTC_TOKEN=BTC
CORRELATION_MIN_CANDLES=0

# Elasticity
ELASTICITY_ENABLED=true
ELASTICITY_WINDOW_SIZE=500
ELASTICITY_BTC_TOKEN=BTC
ELASTICITY_MIN_CANDLES=0
ELASTICITY_MIN_MOVEMENT_THRESHOLD=0.0001
```

## Testing

### Unit Tests
**Location**: `tests/Unit/Account/AssignBestTokenToNewPositionsTest.php`

**Coverage** (12 scenarios, 24 assertions):

1. ✓ **SHORT Assignment**: Assigns symbol with highest `elasticity_short × correlation` score
2. ✓ **No Duplicates**: Assigns different symbols to multiple positions (batch exclusions)
3. ✓ **LONG Assignment**: Assigns symbol with highest asymmetry score
4. ✓ **Mixed Directions**: Correctly assigns both LONG and SHORT positions
5. ✓ **Fast-Track Priority**: Prioritizes fast-tracked symbol over higher elasticity
6. ✓ **Exclusion Logic**: Excludes symbols already in opened positions
7. ✓ **No Symbols Available**: Skips position when no matching direction symbols
8. ✓ **Best Timeframe**: Selects symbol based on best timeframe score (not average)
9. ✓ **Correlation Weighting**: Prioritizes reliable moderate symbol over unreliable high elasticity
10. ✓ **Inverted Correlation**: Correctly calculates asymmetry for negative `elasticity_short`
11. ✓ **Downside Penalization**: Penalizes high downside risk for LONGs
12. ✓ **Missing Data**: Skips symbols without complete elasticity data

**Test Helpers**:
```php
// Create symbol with elasticity/correlation metrics
function createExchangeSymbolWithElasticity(
    string $token,
    string $direction,
    array $metrics
): ExchangeSymbol

// Create position for assignment testing
function createPositionForAssignment(
    int $accountId,
    array $attributes = []
): Position
```

**Running Tests**:
```bash
php artisan test --filter=AssignBestTokenToNewPositionsTest
```

## Performance & Optimization

### Database Indexes
Critical indexes for fast queries:
- `candles (exchange_symbol_id, timeframe, timestamp)` - Aligned timestamp queries
- `exchange_symbols (direction, api_system_id)` - Direction filtering
- `positions (account_id, status, direction)` - Position queries

### Calculation Performance
- **Batch Processing**: All symbols calculated in parallel (same index, same block_uuid)
- **Timeframe Efficiency**: Each timeframe calculated independently
- **Memory Usage**: ~500 candles × 2 symbols × 5 timeframes = ~5,000 rows per calculation
- **Execution Time**: ~0.5-2 seconds per symbol per timeframe

### Data Freshness
- Calculations trigger after each exchange sync (every 1-5 minutes depending on data)
- Only USDT pairs calculated (quote_id = 1)
- BTC symbol excluded (cannot correlate with itself)

## Common Patterns

### Analyzing a Specific Symbol
```php
use Martingalian\Core\Models\ExchangeSymbol;

$symbol = ExchangeSymbol::find(42);

// Check correlation across timeframes
foreach (['1h', '4h', '6h', '12h', '1d'] as $tf) {
    echo "[$tf] Correlation: {$symbol->btc_correlation_rolling[$tf]}\n";
    echo "[$tf] Long: {$symbol->btc_elasticity_long[$tf]}\n";
    echo "[$tf] Short: {$symbol->btc_elasticity_short[$tf]}\n";
    echo "[$tf] Asymmetry: " .
        ($symbol->btc_elasticity_long[$tf] - $symbol->btc_elasticity_short[$tf]) .
        "\n\n";
}
```

### Finding Best SHORT Candidates
```php
$bestShorts = ExchangeSymbol::query()
    ->where('direction', 'SHORT')
    ->tradeable()
    ->get()
    ->map(function ($symbol) {
        $bestScore = 0;
        foreach (['1h', '4h', '6h', '12h', '1d'] as $tf) {
            if (isset($symbol->btc_elasticity_short[$tf])) {
                $score = abs($symbol->btc_elasticity_short[$tf]) *
                         abs($symbol->btc_correlation_rolling[$tf] ?? 0);
                $bestScore = max($bestScore, $score);
            }
        }
        return ['symbol' => $symbol->symbol->token, 'score' => $bestScore];
    })
    ->sortByDesc('score')
    ->take(10);
```

### Finding Best LONG Candidates
```php
$bestLongs = ExchangeSymbol::query()
    ->where('direction', 'LONG')
    ->tradeable()
    ->get()
    ->map(function ($symbol) {
        $bestScore = 0;
        foreach (['1h', '4h', '6h', '12h', '1d'] as $tf) {
            if (isset($symbol->btc_elasticity_long[$tf])
                && isset($symbol->btc_elasticity_short[$tf])) {
                $asymmetry = $symbol->btc_elasticity_long[$tf] -
                            $symbol->btc_elasticity_short[$tf];
                $score = $asymmetry * abs($symbol->btc_correlation_rolling[$tf] ?? 0);
                $bestScore = max($bestScore, $score);
            }
        }
        return ['symbol' => $symbol->symbol->token, 'score' => $bestScore];
    })
    ->sortByDesc('score')
    ->take(10);
```

### Debugging Missing Data
```php
// Check symbols without elasticity data
$incomplete = ExchangeSymbol::query()
    ->whereNull('btc_elasticity_long')
    ->orWhereNull('btc_elasticity_short')
    ->orWhereNull('btc_correlation_rolling')
    ->get();

echo "Symbols without complete data: {$incomplete->count()}\n";

// Check BTC symbol availability
$btcSymbol = Symbol::where('token', 'BTC')->first();
if (!$btcSymbol) {
    echo "ERROR: BTC symbol not found!\n";
}

// Check candle data availability
$btcExchangeSymbol = ExchangeSymbol::where('symbol_id', $btcSymbol->id)->first();
$candleCount = Candle::where('exchange_symbol_id', $btcExchangeSymbol->id)
    ->where('timeframe', '1d')
    ->count();
echo "BTC candles available: $candleCount\n";
```

## Real-World Examples

### Example 1: SQD (Excellent SHORT Candidate)
```
Token: SQD/USDT
Direction: SHORT

Timeframe Analysis:
[1d] Correlation: 0.48, Long: -0.97, Short: 25.96, Asymmetry: -26.93
[1h] Correlation: 0.74, Long: 0.88,  Short: 6.72,  Asymmetry: -5.84
[4h] Correlation: 0.84, Long: 0.12,  Short: 3.10,  Asymmetry: -3.22

Scoring (SHORT):
[1d] Score = |25.96| × |0.48| = 12.46 ← Best timeframe
[1h] Score = |6.72| × |0.74| = 4.97
[4h] Score = |3.10| × |0.84| = 2.60

Selection: SQD selected with score 12.46 (1d timeframe)
Profile: "Weak hands" - massive downside amplification (25.96x), poor upside
```

### Example 2: MYX (Premium LONG Candidate)
```
Token: MYX/USDT
Direction: LONG

Timeframe Analysis:
[6h]  Correlation: 0.52,  Long: 29.3,  Short: -57.4, Asymmetry: 86.7
[1h]  Correlation: -0.02, Long: 5.56,  Short: 2.89,  Asymmetry: 2.67
[1d]  Correlation: 0.25,  Long: 2.66,  Short: -57.37, Asymmetry: 60.03

Scoring (LONG):
[6h] Score = (29.3 - (-57.4)) × |0.52| = 86.7 × 0.52 = 45.08 ← Best timeframe
[1d] Score = (2.66 - (-57.37)) × |0.25| = 60.03 × 0.25 = 15.01
[1h] Score = (5.56 - 2.89) × |-0.02| = 2.67 × 0.02 = 0.05

Selection: MYX selected with score 45.08 (6h timeframe)
Profile: "Defensive powerhouse" - RISES when BTC falls, good upside when BTC rises
```

### Example 3: Correlation Weighting in Action
```
VOLATILE vs STABLE (both SHORT candidates):

VOLATILE:
- elasticity_short: 30.0 (very high)
- correlation: 0.1 (very low - unreliable!)
- Score: 30.0 × 0.1 = 3.0

STABLE:
- elasticity_short: 8.0 (moderate)
- correlation: 0.95 (very high - reliable!)
- Score: 8.0 × 0.95 = 7.6 ← Winner

Result: STABLE selected despite lower elasticity due to reliable correlation
```

## Troubleshooting

### Positions Not Getting Tokens Assigned
**Check**:
1. Are correlation/elasticity calculations running?
   ```bash
   php artisan tinker
   >>> ExchangeSymbol::whereNotNull('btc_elasticity_long')->count();
   ```
2. Are positions marked with direction?
   ```bash
   >>> Position::where('status', 'new')->whereNull('direction')->count();
   ```
3. Are there available symbols in the correct direction?
   ```bash
   >>> ExchangeSymbol::where('direction', 'SHORT')->tradeable()->count();
   ```

### Calculations Not Running
**Check**:
1. Config enabled:
   ```bash
   php artisan tinker
   >>> config('martingalian.correlation.enabled');
   >>> config('martingalian.elasticity.enabled');
   ```
2. BTC symbol exists:
   ```bash
   >>> Symbol::where('token', 'BTC')->exists();
   ```
3. Candle data available:
   ```bash
   >>> Candle::where('timeframe', '1d')->count();
   ```

### All Scores Are Zero
**Check**:
1. Correlation values populated:
   ```bash
   >>> ExchangeSymbol::first()->btc_correlation_rolling;
   ```
2. Elasticity values populated:
   ```bash
   >>> ExchangeSymbol::first()->btc_elasticity_long;
   ```
3. Direction filter working:
   ```bash
   >>> ExchangeSymbol::where('direction', 'LONG')->count();
   ```

## Current Integration Status

### ⚠️ ACTIVE ISSUE: Race Condition in Workflow Integration

**Status**: Work in Progress (as of 2025-11-15)

**Problem**: TriggerCorrelationCalculationsJob executes before ExchangeSymbols are created in the database.

**Current Implementation**:
- TriggerCorrelationCalculationsJob runs at index 2 in parent block (DiscoverExchangeSymbolsJob)
- ExchangeSymbols created by UpsertExchangeSymbolJob at index 2 in CHILD blocks (multiple nested levels deep)
- Result: Job queries `exchange_symbols` table while it's still empty → Creates 0 correlation/elasticity jobs

**Test Results**:
```
TriggerCorrelationCalculationsJob executed: 23:34:05 - 23:34:10
ExchangeSymbols created: 23:34:20 - 23:34:45
Result: 0 correlation jobs, 0 elasticity jobs
```

**Attempted Solutions**:
1. ❌ Mock parent step pattern → Race condition (children ran before BTC created)
2. ❌ Index 2 in GetAllSymbolsFromExchangeJob → Still ran before children completed
3. ❌ Move to DiscoverExchangeSymbolsJob at index 2 → Same issue (parent completes before children)
4. ✅ NEW child block pattern → Fixed circular dependency but timing still wrong

**Root Cause**:
The Step system executes a parent job's `compute()` method to completion before dispatching its children. Index controls ordering WITHIN a block, not across parent-child boundaries. When TriggerCorrelationCalculationsJob runs at index 2 in the parent block, it executes after GetAllSymbolsFromExchangeJob's `compute()` finishes, but BEFORE the UpsertSymbolEligibilityJob children (and their nested children) execute.

**Block Hierarchy**:
```
Block A (Parent):
  - Index 1: GetAllSymbolsFromExchangeJob (compute() finishes immediately)
  - Index 2: TriggerCorrelationCalculationsJob ← Runs HERE

Block B (Child of GetAllSymbols):
  - Index 1: UpsertSymbolEligibilityJob (multiple instances)

Block C (Child of UpsertSymbolEligibility):
  - Index 1: UpsertSymbolOnDatabaseJob
  - Index 2: UpsertExchangeSymbolJob ← Creates ExchangeSymbol HERE (runs AFTER Block A.2!)
  - Index 3: CheckSymbolEligibilityJob
  - Index 4: UpdateSymbolEligibilityStatusJob
```

**Potential Solutions to Explore Tomorrow**:
1. Move TriggerCorrelationCalculationsJob to index 3+ in same parent block (wait for more steps to complete)
2. Create a "WaitForExchangeSymbolsJob" wrapper that polls the database before triggering
3. Dispatch TriggerCorrelationCalculationsJob from a DIFFERENT workflow trigger (after symbol workflow completes)
4. Move correlation/elasticity dispatch into UpdateSymbolEligibilityStatusJob (last job in chain)
5. Use database observers/events on ExchangeSymbol creation to trigger calculations
6. Redesign workflow: Make TriggerCorrelationCalculationsJob a separate cron job that runs AFTER refresh-core-data

**Files Modified During Integration**:
- `/packages/martingalian/core/src/Jobs/Lifecycles/ApiSystem/DiscoverExchangeSymbolsJob.php` - Added TriggerCorrelationCalculationsJob dispatch at index 2
- `/packages/martingalian/core/src/Jobs/Models/ApiSystem/TriggerCorrelationCalculationsJob.php` - Fixed circular dependency (NEW child block pattern)
- `/packages/martingalian/core/src/Jobs/Models/ExchangeSymbol/CalculateBtcCorrelationJob.php` - Changed error returns to graceful skips
- `/packages/martingalian/core/src/Jobs/Models/ExchangeSymbol/CalculateBtcElasticityJob.php` - Changed error returns to graceful skips

**Next Steps**:
1. Review Step system execution order documentation
2. Decide on architectural approach (same workflow vs separate cron)
3. Implement chosen solution
4. Test with `--clean` flag to verify ExchangeSymbols exist when correlation jobs run
5. Verify correlation/elasticity jobs gracefully skip when no candle data available

## Future Enhancements
- Multi-asset correlation (ETH, SOL, not just BTC)
- Time-decay weighting (recent data weighted more)
- Volatility-adjusted elasticity scores
- Machine learning for dynamic threshold tuning
- Real-time elasticity updates (not just on sync)
- Elasticity divergence alerts (changing patterns)
- Correlation breakdown detection (relationship changing)
