# Trading Strategy System

## Overview
The trading strategy system determines when and how to enter/exit positions based on technical analysis, risk management rules, and market conditions. It integrates indicator signals, direction conclusions, and trade configurations to generate actionable trading signals.

## Architecture

### Signal Generation Flow
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

## Core Models

### TradeConfiguration Model
**Location**: `Martingalian\Core\Models\TradeConfiguration`
**Purpose**: Configures trading parameters and rules for accounts

**Schema**:
- `id` - Identifier
- `is_default` - Whether this is the default configuration
- `disable_exchange_symbol_from_negative_pnl_position` - Disable symbol after loss
- `indicator_timeframes` (JSON) - Timeframes to use for indicators
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(Account)` - Accounts using this configuration

**Concerns**:
- `HasGetters` - Retrieval helpers (`getDefault()`)
- `HasScopes` - Query scopes (`default()`)

**Usage**:
```php
// Get default trade configuration
$config = TradeConfiguration::getDefault();

// Check indicator timeframes
$timeframes = $config->indicator_timeframes; // ['1h', '4h', '1d']
```

### ExchangeSymbol Direction
**Location**: `Martingalian\Core\Models\ExchangeSymbol`
**Direction Field**: `direction` - LONG, SHORT, or NULL

**Direction Metadata**:
- `indicators_values` (JSON) - Raw indicator values used for conclusion
- `indicators_timeframe` - Timeframe used for direction
- `indicators_synced_at` - When indicators were last refreshed
- `mark_price` - Current market price
- `mark_price_synced_at` - When price was last updated

**Concerns**:
- `HasTradingComputations` - Position sizing, risk calculations
- `InteractsWithApis` - Exchange API interactions

## Direction Conclusion

### ConcludeSymbolDirectionAtTimeframeJob
**Location**: `Jobs/Models/ExchangeSymbol/ConcludeSymbolDirectionAtTimeframeJob.php`
**Purpose**: Analyzes indicator data to determine trading direction
**Triggered**: After indicator refresh (QuerySymbolIndicatorsJob completes)

**Logic Overview**:
1. Load recent IndicatorHistory for symbol/timeframe
2. Extract indicator values:
   - RSI: momentum strength
   - MACD: trend direction (histogram sign)
   - EMA: trend alignment (multiple periods)
   - MFI: volume confirmation
   - ADX: trend strength validation
   - OBV: volume momentum
3. Apply scoring system:
   - Each indicator contributes to LONG or SHORT score
   - Weight indicators by importance and reliability
   - Calculate confidence percentage
4. Determine direction:
   - If confidence >= threshold: Set direction (LONG/SHORT)
   - If confidence < threshold: Set NULL (neutral)
5. Store conclusion:
   - Update ExchangeSymbol.direction
   - Store indicators_values for audit
   - Update indicators_synced_at timestamp

**Example Indicator Scoring**:
```php
// RSI Analysis
if ($rsi < 30) {
    $longScore += 20; // Oversold = bullish signal
} elseif ($rsi > 70) {
    $shortScore += 20; // Overbought = bearish signal
}

// MACD Analysis
if ($macd['histogram'] > 0) {
    $longScore += 15; // Positive histogram = bullish
} else {
    $shortScore += 15; // Negative histogram = bearish
}

// EMA Alignment
if ($ema9 > $ema21 && $ema21 > $ema50) {
    $longScore += 25; // Bullish alignment
} elseif ($ema9 < $ema21 && $ema21 < $ema50) {
    $shortScore += 25; // Bearish alignment
}

// ADX Strength Validation
if ($adx > 25) {
    // Strong trend, multiply confidence by 1.2
    $totalScore *= 1.2;
}

// Calculate confidence
$totalScore = $longScore + $shortScore;
$confidence = max($longScore, $shortScore) / $totalScore;

// Determine direction
if ($confidence >= 0.7) { // 70% threshold
    $direction = $longScore > $shortScore ? 'LONG' : 'SHORT';
} else {
    $direction = null; // Not confident enough
}
```

### ConfirmPriceAlignmentWithDirectionJob
**Location**: `Jobs/Lifecycles/ExchangeSymbols/ConfirmPriceAlignmentWithDirectionJob.php`
**Purpose**: Validates that price action confirms the concluded direction
**Triggered**: After direction conclusion or significant price movement

**Logic**:
1. Check ExchangeSymbol.direction (LONG or SHORT)
2. Analyze recent price movement:
   - Load last N candles (e.g., 10 candles)
   - Calculate price trend (linear regression or EMA slope)
3. Compare direction with price trend:
   - LONG direction: Price should be trending up
   - SHORT direction: Price should be trending down
4. Measure alignment strength:
   - Strong alignment: Price trend matches direction with high correlation
   - Weak alignment: Price trend contradicts direction
5. Take action:
   - Strong alignment: Keep direction, increase confidence
   - Weak alignment: Set direction to NULL (invalidate signal)
   - Log alignment check results

**Example**:
```php
// Get recent candles
$candles = Candle::where('exchange_symbol_id', $exchangeSymbol->id)
    ->where('timeframe', $exchangeSymbol->indicators_timeframe)
    ->orderBy('timestamp', 'desc')
    ->limit(10)
    ->get();

// Calculate price trend
$closes = $candles->pluck('close')->toArray();
$trend = $this->calculateTrend($closes); // positive = up, negative = down

// Check alignment
if ($exchangeSymbol->direction === 'LONG') {
    if ($trend < 0) {
        // Price is trending down but direction is LONG = misalignment
        $exchangeSymbol->update(['direction' => null]);
        // Log invalidation
    }
} elseif ($exchangeSymbol->direction === 'SHORT') {
    if ($trend > 0) {
        // Price is trending up but direction is SHORT = misalignment
        $exchangeSymbol->update(['direction' => null]);
        // Log invalidation
    }
}
```

## Entry Criteria

### Pre-Trade Validation
Before opening a position, all conditions must be met:

#### 1. Direction Confirmation
- ExchangeSymbol.direction must be LONG or SHORT (not NULL)
- indicators_synced_at must be recent (<5 minutes old)
- Price alignment must be confirmed

#### 2. Symbol Status
- Symbol must pass `tradeable()` scope check:
  - Manually enabled (is_manually_enabled is NULL or true)
  - Auto-enabled (auto_disabled = false)
  - Receives indicator data (receives_indicator_data = true)
  - Has trading direction assigned (direction is not NULL)
  - Respects cooldown periods (tradeable_at is NULL or <= now)
- Symbol must not be delisted or in maintenance
- Sufficient liquidity (check volume and order book depth)

#### 3. Account Eligibility
- Account.can_trade = true
- No existing position on the same symbol (unless pyramiding enabled)
- Not exceeding max_positions_per_account limit
- Sufficient balance for margin requirements

#### 4. Risk Management
- No recent loss on this symbol (if disable_exchange_symbol_from_negative_pnl_position = true)
- Account drawdown within acceptable limits
- Position size respects risk_per_trade percentage
- Leverage within allowed range

#### 5. Market Conditions
- No extreme volatility detected
- No major economic events (if calendar integration exists)
- Exchange operational (no maintenance mode)

### Entry Timing
**Immediate Entry**: Use MARKET orders when:
- Strong direction signal with high confidence
- Price approaching key support/resistance
- Volatility is low (tight spreads)

**Delayed Entry**: Use LIMIT orders when:
- Moderate confidence in direction
- Price has moved away from optimal entry
- High volatility (wide spreads)
- Patience for better price available

## Symbol Selection & Position Assignment

### Overview
**Location**: `Martingalian\Core\Concerns\Account\HasTokenDiscovery`
**Purpose**: Assigns specific trading pairs (ExchangeSymbols) to abstract position slots created by the trading strategy

### Current Implementation Analysis

The `HasTokenDiscovery` trait bridges portfolio-level strategy ("open 5 LONG positions") with symbol-level execution ("buy BTC/USDT, ETH/USDT..."). While architecturally clean, the current implementation has critical financial risk concerns that need addressing.

#### Flow
```
Strategy creates N empty positions (status='new', exchange_symbol_id=NULL)
    ↓
LaunchCreatedPositionsJob triggers
    ↓
QueryPositionsJob, QueryOrdersJob, QueryBalanceJob (sync exchange state)
    ↓
AssignTokensToNewPositionsJob → HasTokenDiscovery::assignBestTokenToNewPositions()
    ↓
Positions assigned concrete trading pairs
    ↓
DispatchNewPositionsWithTokensAssignedJob (execute trades)
```

### Critical Issues Requiring Attention

#### 🚨 Issue #1: Random Symbol Selection (HIGH PRIORITY)

**Current Behavior:**
```php
// Line 183 in HasTokenDiscovery
$exchangeSymbolId = Arr::random($ids);  // Random pick from available LONG/SHORT signals
```

**The Problem:**
Symbols are selected **completely at random** from whatever happens to have a directional signal. This creates severe portfolio risk:

**Example Scenario:**
```
Available 1h LONG signals: [BTC, ETH, SOL, DOGE, SHIB, PEPE, FLOKI, APE]
Random picks for 3 positions: [SHIB, PEPE, FLOKI]

Result:
- 100% memecoin exposure
- 0.95+ correlation (all move identically)
- Market dumps 3% → All positions down 15%+ with leverage
```

**Why This Matters:**
- **No correlation awareness** - All selected symbols might be highly correlated
- **No volatility adjustment** - SHIB (±20% daily) treated same as BTC (±3% daily)
- **No liquidity consideration** - Low-liquidity symbols get equal weight
- **No signal strength ranking** - Weak LONG signal = Strong LONG signal

**Impact:**
Works okay in strong bull markets (everything rises), but **catastrophic in bear/choppy markets** when correlation spikes and random selection = holding 5 correlated losers.

**Recommended Solution:**
Replace random selection with **signal strength scoring**:

```php
protected function selectBestToken(string $direction)
{
    $indexes = $this->tradeConfiguration->indicator_timeframes;

    // Try fast-tracked first (existing logic)
    $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction);
    if ($fastTrackedSymbol) {
        return $fastTrackedSymbol;
    }

    // NEW: Score-based selection instead of random
    foreach ($indexes as $timeframe) {
        $ids = data_get($this->sortedExchangeSymbols, $timeframe.'.'.$direction, []);

        if (!empty($ids)) {
            // Score each symbol
            $scored = collect($ids)->map(function($id) {
                $symbol = ExchangeSymbol::find($id);
                return [
                    'id' => $id,
                    'symbol' => $symbol,
                    'score' => $this->calculateSignalStrength($symbol),
                ];
            })
            ->sortByDesc('score')
            ->filter(function($item) {
                // Filter out if would create high correlation with existing positions
                return !$this->wouldCreateHighCorrelation($item['symbol']);
            });

            if ($scored->isNotEmpty()) {
                $best = $scored->first();
                // Remove from pool and return
                $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(
                    fn($s) => $s->id !== $best['id']
                );
                $this->generateStructuredDataFromAvailableExchangeSymbols();
                return $best['symbol'];
            }
        }
    }

    return null;
}

protected function calculateSignalStrength(ExchangeSymbol $symbol): float
{
    $indicators = $symbol->indicators_values ?? [];
    $score = 0;

    // RSI extremes = stronger signal
    if ($symbol->direction === 'LONG' && isset($indicators['rsi'])) {
        $rsi = $indicators['rsi'];
        if ($rsi < 30) $score += 30;      // Deep oversold
        elseif ($rsi < 40) $score += 20;  // Oversold
    } elseif ($symbol->direction === 'SHORT' && isset($indicators['rsi'])) {
        $rsi = $indicators['rsi'];
        if ($rsi > 70) $score += 30;      // Deep overbought
        elseif ($rsi > 60) $score += 20;  // Overbought
    }

    // MACD histogram magnitude
    if (isset($indicators['macd']['histogram'])) {
        $score += abs($indicators['macd']['histogram']) * 10;
    }

    // ADX > 25 = strong trend confirmation
    if (isset($indicators['adx']) && $indicators['adx'] > 25) {
        $score += 20;
    }

    // Signal freshness (penalize stale signals)
    $age = now()->diffInMinutes($symbol->indicators_synced_at);
    $score -= $age * 2;

    // Volatility penalty (prefer stable assets)
    if (isset($symbol->volatility_24h)) {
        $score -= $symbol->volatility_24h * 5;
    }

    return max(0, $score);  // Ensure non-negative
}

protected function wouldCreateHighCorrelation(ExchangeSymbol $candidateSymbol): bool
{
    $openPositions = $this->positions()->opened()->with('exchangeSymbol')->get();

    if ($openPositions->isEmpty()) {
        return false;
    }

    // Check if candidate symbol is in same sector as existing positions
    // This is simplified - real implementation would use correlation matrix
    $candidateSector = $candidateSymbol->sector ?? 'unknown';

    $sectorCounts = $openPositions->groupBy(fn($p) => $p->exchangeSymbol->sector ?? 'unknown')
        ->map(fn($group) => $group->count());

    $maxSectorPositions = 2;  // Don't allow more than 2 positions in same sector

    if (($sectorCounts[$candidateSector] ?? 0) >= $maxSectorPositions) {
        return true;  // Would create concentration
    }

    return false;
}
```

#### 🟡 Issue #2: Fast-Track May Chase Exhausted Moves

**Current Behavior:**
```php
// Fast-tracked symbols are reused immediately without additional validation
$fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction);
if ($fastTrackedSymbol) {
    return $fastTrackedSymbol;  // Immediate reuse
}
```

**The Problem:**
Fast profitable moves often signal **exhaustion**, not continuation. You're essentially **buying what just pumped**.

**Example:**
```
Yesterday 15:00: BTC pumps +5% in 10 minutes (fast trade closed profitable)
Yesterday 15:05: BTC added to fast-track list
Today 09:00: BTC still shows LONG, assigned via fast-track
Today 09:01: BTC tops out, reverses -8% (late entry into exhausted move)
```

**Recommended Solution:**
Weight fast-track symbols higher but validate signal is still strong:

```php
protected function getFastTrackedSymbolForDirection(string $direction)
{
    $fastTracked = $this->fastTrackedPositions()->where('direction', $direction);

    if ($fastTracked->isNotEmpty()) {
        foreach ($fastTracked as $trackedPosition) {
            $symbol = $this->availableExchangeSymbols
                ->where('direction', $direction)
                ->first(fn($s) => $s->id === $trackedPosition->exchange_symbol_id);

            if ($symbol) {
                // NEW: Validate signal is still strong
                $signalStrength = $this->calculateSignalStrength($symbol);

                // Only use fast-track if signal strength is above threshold
                if ($signalStrength >= 50) {  // Configurable threshold
                    return $symbol;
                }

                // Otherwise, continue to normal selection (signal weakened)
            }
        }
    }

    return null;
}
```

#### 🟡 Issue #3: Stale Signal Risk

**Current Behavior:**
Trait doesn't check `indicators_synced_at` freshness. Could assign symbols with 4-minute-old signals that are executed at minute 5 (right at the staleness threshold).

**Recommended Solution:**
Filter symbols by freshness before building the sorted structure:

```php
public function assignBestTokenToNewPositions()
{
    $this->availableExchangeSymbols = $this->availableExchangeSymbols();

    // Filter complete metadata (existing)
    $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(function ($symbol) {
        return filled($symbol->min_notional)
            && filled($symbol->tick_size)
            && filled($symbol->price_precision)
            && filled($symbol->quantity_precision);
    });

    // NEW: Filter by signal freshness
    $maxAgeMinutes = config('martingalian.strategy.max_indicator_age_minutes', 5);
    $freshnessThreshold = now()->subMinutes($maxAgeMinutes / 2);  // Use half (2.5 min) for safety

    $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(function ($symbol) use ($freshnessThreshold) {
        return $symbol->indicators_synced_at >= $freshnessThreshold;
    });

    $this->generateStructuredDataFromAvailableExchangeSymbols();

    // ... rest of existing logic
}
```

#### 🟠 Issue #4: No Volatility-Adjusted Position Sizing

**Current Behavior:**
All symbols assigned equally. BTC (3% volatility) gets same margin as SHIB (20% volatility).

**The Problem:**
Equal margin = vastly different dollar risk per position.

**Recommended Solution:**
This is actually a **position sizing concern**, not symbol selection. The trait should store volatility metadata that the sizing logic uses:

```php
// In assignBestTokenToNewPositions(), after assignment:
$position->updateSaving([
    'exchange_symbol_id' => $bestToken->id,
    'direction' => $bestToken->direction,
    'parsed_trading_pair' => $position->getParsedTradingPair(),

    // NEW: Store volatility for sizing logic
    'indicators_values' => [
        'volatility_24h' => $bestToken->volatility_24h ?? 0,
        'signal_strength' => $this->calculateSignalStrength($bestToken),
    ],
]);
```

Then in your position sizing logic (separate from this trait):
```php
// Lower volatility = larger position size for same dollar risk
$volatilityAdjustment = 1 / max($position->indicators_values['volatility_24h'], 0.01);
$adjustedMargin = $baseMargin * $volatilityAdjustment;
```

#### 🟠 Issue #5: No Market Regime Awareness

**Current Behavior:**
Same selection logic regardless of market conditions.

**The Problem:**
- **Bull market**: Random works okay (rising tide lifts all boats)
- **Bear/choppy market**: Need to be highly selective (correlation spikes, most signals fail)

**Recommended Solution:**
Add market regime detection and adjust selection criteria:

```php
protected function selectBestToken(string $direction)
{
    $marketRegime = $this->detectMarketRegime();  // 'bull', 'bear', 'choppy'

    // In bear markets, only use top-tier signals
    $minSignalStrength = match($marketRegime) {
        'bull' => 30,     // Lower bar
        'choppy' => 50,   // Moderate bar
        'bear' => 70,     // High bar (very selective)
        default => 40,
    };

    // ... then in scoring logic, filter by min strength
}

protected function detectMarketRegime(): string
{
    // Check BTC trend as market proxy
    $btc = ExchangeSymbol::where('parsed_trading_pair', 'BTC/USDT')->first();

    if (!$btc || !isset($btc->indicators_values['ema'])) {
        return 'unknown';
    }

    $ema9 = $btc->indicators_values['ema']['9'] ?? 0;
    $ema21 = $btc->indicators_values['ema']['21'] ?? 0;
    $ema50 = $btc->indicators_values['ema']['50'] ?? 0;
    $adx = $btc->indicators_values['adx'] ?? 0;

    // Strong uptrend
    if ($ema9 > $ema21 && $ema21 > $ema50 && $adx > 25) {
        return 'bull';
    }

    // Strong downtrend
    if ($ema9 < $ema21 && $ema21 < $ema50 && $adx > 25) {
        return 'bear';
    }

    // Weak trend / range-bound
    return 'choppy';
}
```

### Portfolio-Level Risk Management (Future Enhancement)

**Current Gap:**
Trait operates per-account, doesn't consider cross-position portfolio risk.

**Needed Enhancements:**

1. **Correlation Matrix**
   - Calculate pairwise correlation between candidate symbol and existing positions
   - Reject if correlation > 0.8 (prevents clustered blow-ups)

2. **Sector Diversification**
   - Track sector exposure (Layer-1, DeFi, Meme, AI, etc.)
   - Enforce limits: Max 40% in any single sector

3. **Market Cap Spread**
   - Ensure mix of large-cap (stable), mid-cap (growth), small-cap (high-risk)
   - Don't allow 100% small-cap or 100% memecoins

4. **Kelly Criterion for Selection**
   - Calculate Kelly fraction per symbol based on historical win rate
   - Prefer symbols with higher Kelly scores (better risk/reward history)

**Example Implementation (Future):**
```php
protected function selectBestToken(string $direction)
{
    // ... existing logic ...

    // Portfolio-level checks before finalizing selection
    if ($scored->isNotEmpty()) {
        $best = $scored->first(function($item) {
            // Check correlation
            if ($this->getCorrelationWithPortfolio($item['symbol']) > 0.8) {
                return false;
            }

            // Check sector exposure
            if ($this->getSectorExposure($item['symbol']->sector) > 0.4) {
                return false;
            }

            // Check market cap balance
            if (!$this->maintainsMarketCapBalance($item['symbol'])) {
                return false;
            }

            return true;
        });

        return $best ? $best['symbol'] : null;
    }
}
```

### Configuration Updates Needed

Add to `config/martingalian.php` → `strategy`:

```php
'symbol_selection' => [
    // Signal quality
    'min_signal_strength' => 40,          // Minimum score to be considered
    'signal_freshness_minutes' => 2,      // Max age for indicators (stricter than 5)

    // Fast-track
    'fast_track_min_signal_strength' => 50, // Revalidate fast-track symbols
    'fast_track_decay_hours' => 24,        // How long fast-track is valid

    // Correlation & diversification
    'max_correlation' => 0.8,              // Reject if correlation > 0.8
    'max_sector_exposure' => 0.4,          // Max 40% in one sector
    'enable_sector_diversification' => true,

    // Market regime
    'enable_regime_detection' => true,
    'regime_signal_thresholds' => [
        'bull' => 30,
        'choppy' => 50,
        'bear' => 70,
    ],

    // Volatility
    'max_symbol_volatility_24h' => 0.25,   // Reject symbols with >25% daily volatility
    'volatility_penalty_weight' => 5,      // How much to penalize high volatility in scoring
],
```

### Testing Requirements

**Unit Tests** (`tests/Unit/Concerns/Account/HasTokenDiscoveryTest.php`):
- Signal strength calculation accuracy
- Correlation detection logic
- Market regime detection across scenarios
- Fast-track signal validation
- Freshness filtering

**Integration Tests** (`tests/Integration/Trading/SymbolSelectionTest.php`):
- Full flow: empty positions → assigned symbols → verify quality
- Portfolio construction under different market regimes
- Diversification enforcement (no >3 correlated symbols)
- Backtest: random vs. scored selection performance comparison

### Migration Strategy

**Phase 1: Immediate (Safety)**
- Add signal freshness filter (2.5 min max age)
- Add volatility max limit (reject >25% daily vol)

**Phase 2: Core Improvement (1-2 weeks)**
- Implement signal strength scoring
- Replace random selection with top-scored picks
- Add fast-track signal revalidation

**Phase 3: Portfolio Risk (Future)**
- Correlation matrix calculation
- Sector diversification enforcement
- Market regime detection
- Kelly criterion integration

### Related Components
- `LaunchCreatedPositionsJob` - Triggers this flow
- `AssignTokensToNewPositionsJob` - Wraps the trait method
- `ConcludeSymbolDirectionAtTimeframeJob` - Generates the signals used here
- `ConfirmPriceAlignmentWithDirectionJob` - Validates signals before assignment

## Exit Criteria

### Profit Targets
1. **Fixed Take Profit**:
   - Set at configured percentage above entry (e.g., +4%)
   - Risk-reward ratio (e.g., 2:1, 3:1)
   - Multiple targets: 50% at 2%, 50% at 4%

2. **Trailing Take Profit**:
   - Move TP up as price increases
   - Lock in profits while allowing upside
   - Trail by fixed percentage or ATR multiple

### Stop Loss
1. **Fixed Stop Loss**:
   - Set at configured percentage below entry (e.g., -2%)
   - Based on recent volatility (ATR)
   - Below/above key support/resistance levels

2. **Dynamic Stop Loss**:
   - Breakeven stop: Move SL to entry after certain profit
   - Trailing stop: Follow price with fixed distance
   - Volatility-adjusted: Use ATR-based stops

### Direction Reversal
- Indicators conclude opposite direction
- Close position immediately (MARKET order)
- Prevents riding a position into losses

### Manual Exit
- User-initiated closure
- Emergency exit (account issues, exchange problems)
- Portfolio rebalancing

### Liquidation Prevention
- Monitor margin ratio continuously
- Force-reduce position if margin <30%
- Alert admin if liquidation risk >50%

## Position Sizing

### Risk-Based Sizing
Calculate position size to risk fixed percentage of account balance:

```php
// Risk 2% of account per trade
$accountBalance = $account->balance; // $10,000
$riskPercentage = 0.02; // 2%
$riskAmount = $accountBalance * $riskPercentage; // $200

// Entry and stop loss prices
$entryPrice = $exchangeSymbol->mark_price; // $45,000
$stopLossPrice = $entryPrice * 0.98; // 2% stop loss = $44,100
$riskPerUnit = $entryPrice - $stopLossPrice; // $900

// Calculate quantity
$quantity = $riskAmount / $riskPerUnit; // $200 / $900 = 0.222 BTC
```

### Leverage Application
```php
// With 10x leverage
$leverage = 10;
$positionValue = $quantity * $entryPrice; // 0.222 * $45,000 = $9,990
$marginRequired = $positionValue / $leverage; // $9,990 / 10 = $999

// Verify margin available
if ($account->available_margin >= $marginRequired) {
    // Can open position
}
```

### Kelly Criterion (Optional)
For advanced sizing based on win rate and risk-reward:

```php
// Historical stats
$winRate = 0.6; // 60% win rate
$avgWin = 400; // $400 average win
$avgLoss = 200; // $200 average loss

// Kelly formula: f = (p * b - q) / b
// f = fraction of capital to risk
// p = win probability, q = loss probability
// b = win/loss ratio
$b = $avgWin / $avgLoss; // 2
$q = 1 - $winRate; // 0.4
$kellyFraction = ($winRate * $b - $q) / $b;
// f = (0.6 * 2 - 0.4) / 2 = 0.4 = 40% (too aggressive, usually use half-Kelly)

$kellyFraction = $kellyFraction * 0.5; // Half-Kelly = 20%
$positionSize = $accountBalance * $kellyFraction; // $10,000 * 0.2 = $2,000
```

## Multi-Timeframe Analysis

### Timeframe Hierarchy
Analyze multiple timeframes to increase confidence:

**Example Configuration**:
```php
'indicator_timeframes' => ['1h', '4h', '1d']
```

**Logic**:
1. Conclude direction on each timeframe independently
2. Check alignment:
   - All timeframes LONG → High confidence LONG
   - All timeframes SHORT → High confidence SHORT
   - Mixed signals → Lower confidence or wait
3. Higher timeframes carry more weight:
   - 1d direction = 50% weight
   - 4h direction = 30% weight
   - 1h direction = 20% weight

**Example**:
```php
// Timeframe directions
$tf_1h = 'LONG';
$tf_4h = 'LONG';
$tf_1d = 'SHORT';

// Weighted score
$longScore = (20 * ($tf_1h === 'LONG')) + (30 * ($tf_4h === 'LONG')) + (50 * ($tf_1d === 'LONG'));
$shortScore = (20 * ($tf_1h === 'SHORT')) + (30 * ($tf_4h === 'SHORT')) + (50 * ($tf_1d === 'SHORT'));

// $longScore = 20 + 30 + 0 = 50
// $shortScore = 0 + 0 + 50 = 50
// Result: Neutral (conflicting signals, don't trade)
```

## Martingale Strategy (Caution)

**Note**: This system is named "Martingalian" but should NOT implement pure martingale (doubling position after loss). That's extremely risky.

**Safe Alternatives**:
1. **Anti-Martingale**: Increase position size after wins, decrease after losses
2. **Fixed Fractional**: Always risk same percentage per trade
3. **Kelly Criterion**: Size based on edge and win rate

**If Martingale is Required** (user-configured):
- Max martingale steps: 3 (strict limit)
- Max account drawdown: 20%
- Emergency exit after max steps
- Only on high-confidence signals

```php
// Example controlled martingale
$martingaleLevel = 0;
$basePositionSize = 0.1;

if ($previousTrade->pnl < 0) {
    $martingaleLevel = min($previousTrade->martingale_level + 1, 3);
    $positionSize = $basePositionSize * pow(2, $martingaleLevel);

    // Safety: Never exceed 20% of account
    $maxSize = $account->balance * 0.2 / $entryPrice;
    $positionSize = min($positionSize, $maxSize);
}
```

## Backtesting Integration

### Historical Testing
- Load historical candles and indicator values
- Simulate signal generation and trade execution
- Calculate metrics: win rate, profit factor, max drawdown
- Optimize parameters: indicator periods, thresholds, risk percentage

### Walk-Forward Testing
- Train on past data (e.g., last 6 months)
- Test on out-of-sample data (next 1 month)
- Roll forward and repeat
- Detect overfitting and parameter instability

### Performance Metrics
- Total return (%)
- Win rate (%)
- Profit factor (gross profit / gross loss)
- Sharpe ratio (risk-adjusted return)
- Max drawdown (%)
- Average trade duration

## Configuration

### Strategy Settings
**Location**: `config/martingalian.php` → `strategy`

```php
'strategy' => [
    // Direction conclusion
    'confidence_threshold' => 0.7, // 70% minimum confidence
    'max_indicator_age_minutes' => 5, // Stale data threshold

    // Entry criteria
    'require_price_alignment' => true,
    'min_liquidity_volume_24h' => 1000000, // $1M min volume

    // Exit criteria
    'use_trailing_stop' => true,
    'trailing_stop_percentage' => 0.02, // 2%
    'breakeven_activation_percentage' => 0.015, // Move to breakeven after 1.5% profit

    // Multi-timeframe
    'timeframes' => ['1h', '4h', '1d'],
    'timeframe_weights' => [
        '1h' => 0.2,
        '4h' => 0.3,
        '1d' => 0.5,
    ],

    // Position sizing
    'use_kelly_criterion' => false,
    'kelly_fraction' => 0.5, // Half-Kelly (conservative)
],
```

## Testing

### Unit Tests
**Location**: `tests/Unit/Strategy/`
- Direction conclusion logic
- Position sizing calculations
- Risk management validations
- Multi-timeframe aggregation

### Integration Tests
**Location**: `tests/Integration/Strategy/`
- Full signal generation to order creation flow
- Backtest simulation
- Edge cases: conflicting signals, stale data, extreme volatility

## Common Patterns

### Adding New Entry Criterion
1. Create validation method in TradeConfiguration concern
2. Add to pre-trade validation checklist
3. Write tests for new criterion
4. Update configuration if needed

### Debugging Signal Issues
1. Check IndicatorHistory: Are values being stored?
2. Check ExchangeSymbol.direction: Is it being set?
3. Check indicators_synced_at: Is data fresh?
4. Review ConcludeSymbolDirectionAtTimeframeJob logs
5. Verify ConfirmPriceAlignmentWithDirectionJob ran

## Future Enhancements
- Machine learning integration for pattern recognition
- Sentiment analysis from social media
- Order flow analysis (limit order book imbalance)
- Cross-asset correlation (BTC dominance, DXY)
- Dynamic parameter optimization based on market regime
- Portfolio-level risk management (correlation between positions)
