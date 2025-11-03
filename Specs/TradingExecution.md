# Trading Execution System

## Overview
Automated order placement, execution monitoring, and position management across multiple exchanges (Binance, Bybit). Handles the complete lifecycle from signal generation to order execution to position closure.

## Architecture

### Execution Flow
```
Trading Signal Generated
    ↓
TradeConfiguration validates conditions
    ↓
CreateOrderJob dispatched
    ↓
Order submitted to exchange
    ↓
OrderHistory tracks fills
    ↓
Position opened/updated
    ↓
Position monitoring begins
    ↓
Exit conditions met
    ↓
ClosePositionJob dispatched
    ↓
Order closed on exchange
    ↓
PositionHistory finalized
```

## Core Models

### Order Model
**Location**: `Martingalian\Core\Models\Order`
**Purpose**: Represents a trading order on an exchange

**Schema**:
- `id`, `uuid` - Identifiers
- `account_id` - FK to accounts
- `exchange_symbol_id` - FK to exchange_symbols
- `exchange_order_id` - Exchange's order ID
- `type` - MARKET, LIMIT, STOP_MARKET, TAKE_PROFIT_MARKET
- `side` - BUY, SELL
- `position_side` - LONG, SHORT (for futures)
- `quantity` - Amount to trade
- `price` - Limit price (null for MARKET)
- `stop_price` - Stop trigger price
- `reduce_only` - Boolean (close position only)
- `status` - NEW, PARTIALLY_FILLED, FILLED, CANCELED, REJECTED, EXPIRED
- `time_in_force` - GTC, IOC, FOK
- `filled_quantity` - Amount executed
- `average_fill_price` - Weighted average price
- `commission` - Trading fees paid
- `commission_asset` - Fee currency
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Account)`
- `belongsTo(ExchangeSymbol)`
- `hasMany(OrderHistory)` - Fill history
- `morphMany(Step)` - Job steps

**Concerns**:
- `HasScopes` - Query helpers
- `HasAccessors` - Calculated properties
- `HasStatuses` - Status checks

### OrderHistory Model
**Location**: `Martingalian\Core\Models\OrderHistory`
**Purpose**: Tracks partial fills and order updates

**Schema**:
- `order_id` - FK to orders
- `status` - Order status at this point
- `filled_quantity` - Cumulative filled
- `remaining_quantity` - Unfilled amount
- `average_price` - Fill price
- `commission` - Fees this update
- `timestamp` - Exchange timestamp
- `raw_data` (JSON) - Full exchange response

**Purpose**: Audit trail of order execution

### Position Model
**Location**: `Martingalian\Core\Models\Position`
**Purpose**: Represents an open trading position

**Schema**:
- `id`, `uuid` - Identifiers
- `account_id` - FK to accounts
- `exchange_symbol_id` - FK to exchange_symbols
- `side` - LONG, SHORT
- `entry_price` - Average entry price
- `current_price` - Latest market price
- `quantity` - Position size
- `leverage` - Leverage multiplier (1-125x)
- `unrealized_pnl` - Current profit/loss
- `realized_pnl` - Closed profit/loss
- `liquidation_price` - Forced closure price
- `margin` - Collateral used
- `margin_type` - ISOLATED, CROSS
- `opened_at` - Position open time
- `closed_at` - Position close time (null if open)
- `status` - OPEN, CLOSED, LIQUIDATED
- `stop_loss_price` - SL trigger
- `take_profit_price` - TP trigger

**Relationships**:
- `belongsTo(Account)`
- `belongsTo(ExchangeSymbol)`
- `hasMany(Order)` - Orders for this position
- `hasMany(Funding)` - Funding payments

**Concerns**:
- `HasScopes` - Active positions, by side
- `HasAccessors` - PNL calculations
- `HasRiskMetrics` - Risk-reward ratios

## Order Types

### MARKET Order
**Use**: Immediate execution at best available price
**Risk**: Slippage in volatile markets
**When**: Quick entry/exit needed

```php
Order::create([
    'type' => 'MARKET',
    'side' => 'BUY',
    'position_side' => 'LONG',
    'quantity' => 0.1,
]);
```

### LIMIT Order
**Use**: Execute at specific price or better
**Risk**: May not fill if price not reached
**When**: Patience, specific entry price desired

```php
Order::create([
    'type' => 'LIMIT',
    'side' => 'BUY',
    'position_side' => 'LONG',
    'quantity' => 0.1,
    'price' => 45000.00,
    'time_in_force' => 'GTC', // Good Till Canceled
]);
```

### STOP_MARKET Order
**Use**: Stop-loss, triggered at stop price, executes as MARKET
**Risk**: Slippage after trigger
**When**: Protecting downside

```php
Order::create([
    'type' => 'STOP_MARKET',
    'side' => 'SELL',
    'position_side' => 'LONG',
    'quantity' => 0.1,
    'stop_price' => 44000.00,
    'reduce_only' => true,
]);
```

### TAKE_PROFIT_MARKET Order
**Use**: Take profit, triggered at take-profit price
**Risk**: May miss optimal exit
**When**: Locking in gains

```php
Order::create([
    'type' => 'TAKE_PROFIT_MARKET',
    'side' => 'SELL',
    'position_side' => 'LONG',
    'quantity' => 0.1,
    'stop_price' => 48000.00,
    'reduce_only' => true,
]);
```

## Position Management

### Opening Position

#### Entry Flow
1. **Signal Generated**: Indicators conclude LONG or SHORT
2. **Risk Check**: Validate account balance, leverage limits
3. **Symbol Selection**: Choose exchange symbol with liquidity
4. **Size Calculation**: Based on risk percentage and stop-loss
5. **Order Creation**: Submit MARKET or LIMIT order
6. **Fill Monitoring**: Wait for exchange confirmation
7. **Position Tracking**: Create Position record
8. **Protection Orders**: Set stop-loss and take-profit

#### Position Sizing
```php
// Risk 2% of account balance per trade
$accountBalance = $account->balance; // $10,000
$riskPercentage = 0.02; // 2%
$riskAmount = $accountBalance * $riskPercentage; // $200

$entryPrice = 45000;
$stopLossPrice = 44000; // 2.22% stop
$riskPerUnit = $entryPrice - $stopLossPrice; // $1000

$quantity = $riskAmount / $riskPerUnit; // 0.2 BTC
```

### Position Monitoring

#### Jobs
- **SyncPositionJob**: Refreshes position data from exchange
- **UpdatePositionPnLJob**: Recalculates unrealized PNL
- **MonitorPositionRiskJob**: Checks margin, liquidation distance

#### Monitoring Frequency
- Every 30 seconds for OPEN positions
- Every 5 minutes for recent CLOSED positions
- Ad-hoc on significant price moves

### Closing Position

#### Exit Triggers
1. **Take Profit Hit**: Price reaches TP level
2. **Stop Loss Hit**: Price reaches SL level
3. **Manual Close**: User-initiated closure
4. **Strategy Signal**: Direction reversal
5. **Liquidation**: Exchange force-closes (margin depleted)
6. **Account Disabled**: System-wide close

#### Close Flow
1. **Trigger Detected**: Exit condition met
2. **Close Order Created**: MARKET order to exit
3. **Order Execution**: Wait for fill
4. **Position Finalized**: Update to CLOSED status
5. **PnL Calculated**: Final realized profit/loss
6. **History Recorded**: Archive position data

## Lifecycle Jobs

### Account Lifecycle
**Location**: `Jobs/Lifecycles/Accounts/`
**Jobs**:
- **SyncAccountBalanceJob**: Update account balance from exchange
- **UpdateAccountMarginJob**: Recalculate available margin
- **CheckAccountStatusJob**: Verify account can trade

### Order Lifecycle
**Location**: `Jobs/Lifecycles/Orders/`
**Jobs**:
- **CreateOrderJob**: Submit order to exchange
- **SyncOrderStatusJob**: Check order status
- **CancelOrderJob**: Cancel pending order
- **UpdateOrderFillsJob**: Process partial fills

### Position Lifecycle
**Location**: `Jobs/Lifecycles/Positions/`
**Jobs**:
- **OpenPositionJob**: Create new position
- **SyncPositionJob**: Update position from exchange
- **ClosePositionJob**: Exit position
- **UpdatePositionStopLossJob**: Modify SL
- **UpdatePositionTakeProfitJob**: Modify TP
- **LiquidatePositionJob**: Handle forced closure

### ExchangeSymbol Lifecycle
**Location**: `Jobs/Lifecycles/ExchangeSymbols/`
**Jobs**:
- **SyncExchangeSymbolJob**: Update symbol metadata
- **ConfirmPriceAlignmentWithDirectionJob**: Validate direction
- **CleanupIndicatorHistoriesJob**: Remove old indicator data
- **ConcludeSymbolDirectionAtTimeframeJob**: Determine trade direction

## Leverage & Margin

### Leverage Settings
**Range**: 1x to 125x (exchange-dependent)
**Types**:
- **ISOLATED**: Margin limited to position (recommended)
- **CROSS**: Uses full account balance as margin

**Risk**:
- Higher leverage = higher risk of liquidation
- Lower margin required, but faster losses
- System typically uses 5x-20x leverage

### Margin Calculation
```php
// ISOLATED margin
$positionValue = $quantity * $entryPrice; // 0.1 * $45000 = $4500
$leverage = 10;
$marginRequired = $positionValue / $leverage; // $4500 / 10 = $450

// Liquidation price (LONG)
$liquidationPrice = $entryPrice * (1 - (1 / $leverage));
// $45000 * (1 - 0.1) = $40,500
```

### Margin Monitoring
- Alert at 50% margin remaining
- Force-reduce at 20% margin remaining
- Exchange liquidates at ~0-5% margin

## Funding Rates (Futures)

### Funding Model
**Location**: `Martingalian\Core\Models\Funding`
**Purpose**: Tracks periodic funding payments

**Schema**:
- `position_id` - FK to positions
- `rate` - Funding rate percentage
- `amount` - Payment amount (negative = paid, positive = received)
- `timestamp` - Payment time

**Frequency**: Every 8 hours (00:00, 08:00, 16:00 UTC)

**Calculation**:
```php
$fundingAmount = $positionSize * $fundingRate;
// If LONG and rate is positive: You PAY funding
// If SHORT and rate is positive: You RECEIVE funding
```

## Exchange Integration

### Binance Futures
**API**: Binance USDⓈ-M Futures
**Endpoints**:
- `POST /fapi/v1/order` - Create order
- `GET /fapi/v2/positionRisk` - Get positions
- `GET /fapi/v1/allOrders` - Order history
- `DELETE /fapi/v1/order` - Cancel order

**Rate Limits**:
- 2400 requests per minute (weight-based)
- Order limits: 50 per 10 seconds per symbol

### Bybit Futures
**API**: Bybit V5 Unified Trading
**Endpoints**:
- `POST /v5/order/create` - Create order
- `GET /v5/position/list` - Get positions
- `GET /v5/order/history` - Order history
- `POST /v5/order/cancel` - Cancel order

**Rate Limits**:
- 120 requests per second
- Order limits: 100 per 5 seconds per symbol

## Risk Management

### Pre-Trade Checks
1. **Account Balance**: Sufficient funds?
2. **Position Limits**: Max positions per account?
3. **Symbol Status**: Is trading enabled?
4. **Leverage Limits**: Within allowed range?
5. **Maintenance Margin**: Enough buffer?
6. **API Credentials**: Valid and authorized?

### In-Trade Monitoring
1. **Margin Health**: Monitor margin ratio
2. **PnL Tracking**: Real-time profit/loss
3. **Liquidation Distance**: How close to liquidation?
4. **Volatility Spikes**: Unusual price movement?
5. **Funding Costs**: Accumulating fees?

### Post-Trade Analysis
1. **Win Rate**: Percentage of profitable trades
2. **Risk-Reward**: Average profit vs average loss
3. **Slippage**: Execution price vs expected
4. **Commission Impact**: Fee percentage of PnL
5. **Hold Time**: Average position duration

## Error Handling

### Order Rejection
**Causes**:
- Insufficient balance
- Invalid quantity/price
- Symbol not tradeable
- Leverage too high
- Rate limit exceeded

**Actions**:
- Log rejection reason
- Notify admin if critical
- Retry with adjusted parameters if transient
- Skip symbol if persistent issue

### Position Sync Failures
**Causes**:
- Exchange API down
- Network timeout
- Rate limiting

**Actions**:
- Retry with exponential backoff
- Use cached position data temporarily
- Alert if sync fails >5 minutes

### Liquidation Events
**Detection**: Position status = LIQUIDATED
**Actions**:
1. Log liquidation event
2. Notify admin immediately (CRITICAL)
3. Mark position as LIQUIDATED
4. Calculate total loss
5. Update account balance
6. Analyze cause (leverage too high? SL not hit?)

## State Machines

### Order States
```
NEW → PARTIALLY_FILLED → FILLED
 ↓         ↓               ↓
CANCELED  CANCELED    (complete)
 ↓
REJECTED
```

### Position States
```
OPEN → CLOSED
  ↓
LIQUIDATED
```

## Performance Metrics

### Execution Speed
- Order submission: <100ms (target)
- Fill confirmation: <1s (typical)
- Position sync: <500ms

### Reliability
- Order success rate: >99.5%
- Position sync accuracy: 100%
- Slippage: <0.1% (market orders)

## Configuration

### Trading Settings
**Location**: `config/martingalian.php` → `trading`

```php
'trading' => [
    'max_positions_per_account' => 5,
    'default_leverage' => 10,
    'max_leverage' => 20,
    'risk_per_trade' => 0.02, // 2%
    'default_stop_loss_percent' => 0.02, // 2%
    'default_take_profit_percent' => 0.04, // 4% (2:1 RR)
    'margin_type' => 'ISOLATED',
    'min_margin_buffer' => 0.3, // 30%
    'sync_interval' => 30, // seconds
],
```

## Testing

### Unit Tests
**Location**: `tests/Unit/Trading/`
- Order creation logic
- Position size calculation
- PnL calculations
- Risk checks

### Integration Tests
**Location**: `tests/Integration/Trading/`
- Full order lifecycle (mocked exchange)
- Position management
- Funding rate application

### Simulation Mode
- Paper trading with real prices
- No actual exchange orders
- Validates strategy without risk

## Troubleshooting

### Order Not Filling
1. Check symbol liquidity
2. Verify price is reasonable
3. Check time in force setting
4. Review order book depth

### Position Not Syncing
1. Check API credentials
2. Verify exchange connection
3. Check rate limits
4. Review API permissions

### Unexpected Liquidation
1. Check leverage setting
2. Review volatility during position
3. Verify stop-loss was placed
4. Check funding rate impact

## Future Enhancements
- Advanced order types (trailing stop, iceberg)
- Multi-leg strategies (hedging)
- Dynamic leverage adjustment
- Auto-compounding profits
- Portfolio rebalancing
- Copy trading functionality
