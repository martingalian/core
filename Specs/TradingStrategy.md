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
- ExchangeSymbol.is_active = true
- Symbol must be tradeable (not delisted, not maintenance)
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
