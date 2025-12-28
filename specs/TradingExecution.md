# Trading Execution System

## Overview

Automated order placement, execution monitoring, and position management across multiple exchanges (Binance, Bybit). Handles the complete lifecycle from signal generation to order execution to position closure.

---

## Execution Flow

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

---

## Core Models

### Order Model

**Purpose**: Represents a trading order on an exchange

**Key Fields**:
| Field | Type | Description |
|-------|------|-------------|
| `uuid` | char | Unique identifier |
| `account_id` | FK | Links to account |
| `exchange_symbol_id` | FK | Trading pair |
| `exchange_order_id` | string | Exchange's order ID |
| `type` | enum | MARKET, LIMIT, STOP_MARKET, TAKE_PROFIT_MARKET |
| `side` | enum | BUY, SELL |
| `position_side` | enum | LONG, SHORT |
| `quantity` | decimal | Amount to trade |
| `price` | decimal | Limit price (null for MARKET) |
| `stop_price` | decimal | Stop trigger price |
| `reduce_only` | boolean | Close position only |
| `status` | enum | NEW, PARTIALLY_FILLED, FILLED, CANCELED, REJECTED, EXPIRED |
| `filled_quantity` | decimal | Amount executed |
| `average_fill_price` | decimal | Weighted average price |
| `commission` | decimal | Trading fees paid |

**Relationships**:
- `belongsTo(Account)`
- `belongsTo(ExchangeSymbol)`
- `hasMany(OrderHistory)` - Fill history

### OrderHistory Model

**Purpose**: Tracks partial fills and order updates (audit trail)

**Key Fields**:
| Field | Type | Description |
|-------|------|-------------|
| `order_id` | FK | Parent order |
| `status` | enum | Order status at this point |
| `filled_quantity` | decimal | Cumulative filled |
| `average_price` | decimal | Fill price |
| `commission` | decimal | Fees this update |
| `raw_data` | JSON | Full exchange response |

### Position Model

**Purpose**: Represents an open trading position

**Key Fields**:
| Field | Type | Description |
|-------|------|-------------|
| `uuid` | char | Unique identifier |
| `account_id` | FK | Links to account |
| `exchange_symbol_id` | FK | Trading pair |
| `side` | enum | LONG, SHORT |
| `entry_price` | decimal | Average entry price |
| `current_price` | decimal | Latest market price |
| `quantity` | decimal | Position size |
| `leverage` | int | Leverage multiplier (1-125x) |
| `unrealized_pnl` | decimal | Current profit/loss |
| `realized_pnl` | decimal | Closed profit/loss |
| `liquidation_price` | decimal | Forced closure price |
| `margin` | decimal | Collateral used |
| `margin_type` | enum | ISOLATED, CROSS |
| `status` | enum | OPEN, CLOSED, LIQUIDATED |
| `stop_loss_price` | decimal | SL trigger |
| `take_profit_price` | decimal | TP trigger |

---

## Order Types

| Type | Use Case | Risk | When to Use |
|------|----------|------|-------------|
| **MARKET** | Immediate execution at best price | Slippage in volatile markets | Quick entry/exit needed |
| **LIMIT** | Execute at specific price or better | May not fill | Patience for better price |
| **STOP_MARKET** | Stop-loss protection | Slippage after trigger | Protecting downside |
| **TAKE_PROFIT_MARKET** | Lock in gains | May miss optimal exit | Profit targets |

---

## Position Management

### Opening Position Flow

1. **Signal Generated**: Indicators conclude LONG or SHORT
2. **Risk Check**: Validate account balance, leverage limits
3. **Symbol Selection**: Choose symbol with liquidity
4. **Size Calculation**: Based on risk percentage and stop-loss
5. **Order Creation**: Submit MARKET or LIMIT order
6. **Fill Monitoring**: Wait for exchange confirmation
7. **Position Tracking**: Create Position record
8. **Protection Orders**: Set stop-loss and take-profit

### Position Sizing Formula

| Variable | Description |
|----------|-------------|
| `accountBalance` | Total account balance |
| `riskPercentage` | % of balance to risk (typically 2%) |
| `entryPrice` | Entry price |
| `stopLossPrice` | Stop-loss price |
| `riskPerUnit` | entryPrice - stopLossPrice |
| `quantity` | (accountBalance × riskPercentage) / riskPerUnit |

### Position Monitoring

**Jobs**:
- `SyncPositionJob`: Refreshes position data from exchange
- `UpdatePositionPnLJob`: Recalculates unrealized PNL
- `MonitorPositionRiskJob`: Checks margin, liquidation distance

**Frequency**:
- Every 30 seconds for OPEN positions
- Every 5 minutes for recent CLOSED positions
- Ad-hoc on significant price moves

### Exit Triggers

1. **Take Profit Hit**: Price reaches TP level
2. **Stop Loss Hit**: Price reaches SL level
3. **Manual Close**: User-initiated
4. **Strategy Signal**: Direction reversal
5. **Liquidation**: Exchange force-closes
6. **Account Disabled**: System-wide close

---

## Position Creation Workflow

### Entry Point
Command: `cronjobs:create-positions`

### Pre-Flight Checks

| Check | Source | Condition |
|-------|--------|-----------|
| User tradeable | `users.can_trade` | Must be true |
| Account tradeable | `accounts.can_trade` | Must be true |
| Global guard | `martingalian.allow_opening_positions` | Must be true |
| Slots available | DB count check | openPositions < maxSlots |
| Directional guard | `canOpenLongs() OR canOpenShorts()` | At least one true |

### Workflow Sequence

```
cronjobs:create-positions
    ↓
PreparePositionsOpeningJob(accountId)
    ├─ [1] VerifyMinAccountBalanceJob
    │       └─ API: Query balance, store in api_snapshots
    │       └─ SHOWSTOPPER if balance < minimum
    │
    ├─ [2] QueryAccountPositionsJob (parallel)
    │       └─ API: Fetch open positions
    │
    ├─ [2] QueryAccountOpenOrdersJob (parallel)
    │       └─ API: Fetch open orders
    │
    ├─ [3] AssignBestTokensToPositionSlotsJob
    │       └─ Create Position records with direction
    │       └─ Assign optimal tokens via HasTokenDiscovery
    │       └─ SHOWSTOPPER if no slots/tokens assigned
    │
    └─ [4] DispatchPositionSlotsJob
            └─ [per position] DispatchPositionJob
                    └─ [1] VerifyTradingPairNotOpenJob
                            └─ SHOWSTOPPER if pair already open
```

### Showstoppers

| Step | Job | Condition |
|------|-----|-----------|
| 1 | VerifyMinAccountBalanceJob | available_balance < min_account_balance |
| 3 | AssignBestTokensToPositionSlotsJob | totalCreated = 0 OR assignedCount = 0 |
| 4.1 | DispatchPositionJob | Missing direction/token OR status ≠ 'new' |
| 4.1.1 | VerifyTradingPairNotOpenJob | Trading pair already open |

---

## Leverage & Margin

### Leverage Settings

| Setting | Range | Note |
|---------|-------|------|
| Leverage | 1x - 125x | Exchange-dependent |
| ISOLATED | Position-specific margin | Recommended |
| CROSS | Full account as margin | Higher risk |

### Margin Calculation

| Formula | Example |
|---------|---------|
| Position Value = quantity × entryPrice | 0.1 × $45,000 = $4,500 |
| Margin Required = positionValue / leverage | $4,500 / 10 = $450 |
| Liquidation (LONG) = entryPrice × (1 - 1/leverage) | $45,000 × 0.9 = $40,500 |

### Margin Monitoring Thresholds

| Level | Action |
|-------|--------|
| 50% remaining | Alert |
| 20% remaining | Force-reduce |
| 0-5% remaining | Exchange liquidates |

---

## Funding Rates (Futures)

| Setting | Value |
|---------|-------|
| Frequency | Every 8 hours (00:00, 08:00, 16:00 UTC) |
| LONG + positive rate | You PAY funding |
| SHORT + positive rate | You RECEIVE funding |
| Calculation | positionSize × fundingRate |

---

## Exchange Integration

### Binance Futures

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/fapi/v1/order` | POST | Create order |
| `/fapi/v2/positionRisk` | GET | Get positions |
| `/fapi/v1/allOrders` | GET | Order history |
| `/fapi/v1/order` | DELETE | Cancel order |

**Rate Limits**: 2400 requests/minute (weight-based), 50 orders/10s per symbol

### Bybit Futures

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/v5/order/create` | POST | Create order |
| `/v5/position/list` | GET | Get positions |
| `/v5/order/history` | GET | Order history |
| `/v5/order/cancel` | POST | Cancel order |

**Rate Limits**: 120 requests/second, 100 orders/5s per symbol

---

## Risk Management

### Pre-Trade Checks

1. Account Balance sufficient
2. Position Limits not exceeded
3. Symbol Status enabled
4. Leverage within range
5. Maintenance Margin adequate
6. API Credentials valid

### In-Trade Monitoring

1. Margin Health
2. PnL Tracking
3. Liquidation Distance
4. Volatility Spikes
5. Funding Costs

### Post-Trade Analysis

| Metric | Description |
|--------|-------------|
| Win Rate | % of profitable trades |
| Risk-Reward | Average profit vs average loss |
| Slippage | Execution vs expected price |
| Commission Impact | Fees as % of PnL |
| Hold Time | Average position duration |

---

## Error Handling

### Order Rejection Causes

| Cause | Action |
|-------|--------|
| Insufficient balance | Log, notify |
| Invalid quantity/price | Adjust parameters |
| Symbol not tradeable | Skip symbol |
| Leverage too high | Reduce leverage |
| Rate limit exceeded | Retry with backoff |

### Position Sync Failures

| Cause | Action |
|-------|--------|
| Exchange API down | Retry with backoff |
| Network timeout | Use cached data |
| Rate limiting | Exponential backoff |

### Liquidation Events

1. Log liquidation event
2. Notify admin immediately (CRITICAL)
3. Mark position as LIQUIDATED
4. Calculate total loss
5. Update account balance
6. Analyze cause

---

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

---

## Performance Targets

| Metric | Target |
|--------|--------|
| Order submission | <100ms |
| Fill confirmation | <1s |
| Position sync | <500ms |
| Order success rate | >99.5% |
| Slippage | <0.1% (market orders) |

---

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `max_positions_per_account` | 5 | Maximum concurrent positions |
| `default_leverage` | 10 | Default leverage multiplier |
| `max_leverage` | 20 | Maximum allowed leverage |
| `risk_per_trade` | 0.02 | 2% risk per trade |
| `default_stop_loss_percent` | 0.02 | 2% stop-loss |
| `default_take_profit_percent` | 0.04 | 4% (2:1 RR) |
| `margin_type` | ISOLATED | Default margin type |
| `sync_interval` | 30 | Seconds between syncs |

---

## Related Systems

- **StepDispatcher**: Orchestrates job execution
- **ExceptionHandling**: Handles API errors and retries
- **Throttling**: Rate limit coordination
- **NotificationSystem**: Alerts for critical events
