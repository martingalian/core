# Data Models Catalog

## Overview

Comprehensive catalog of all Eloquent models in the Martingalian Core package. Documents relationships, concerns/traits, scopes, observers, encryption patterns, and common usage patterns.

---

## Model Organization

### Base Model

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Abstracts\BaseModel` |
| Purpose | Abstract base class for all Core models |
| Features | Common functionality, boot logic for observers, global scopes |

---

## Core Models

### User

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\User` |
| Purpose | Application users (traders, admins) |

**Schema**: `id`, `uuid`, `name`, `email`, `password`, `remember_token`, `created_at`, `updated_at`

**Relationships**:
- `hasMany(Account)` - User's trading accounts

**Morphable**:
- `morphMany(Step)` via `relatable`
- `morphMany(ThrottleLog)` via `contextable`
- `morphMany(NotificationLog)` via `relatable`

**Observer**: `UserObserver`

---

### Account

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Account` |
| Purpose | Trading account connected to exchange |

**Schema**:
- `id`, `uuid` - Identifiers
- `user_id` - FK to users
- `api_system_id` - FK to api_systems (Binance, Bybit)
- `name` (NOT NULL) - User-friendly account name for notifications
- `trade_configuration_id` - FK to trade_configuration (**REQUIRED**)
- `portfolio_quote` - Quote currency for portfolio valuation (e.g., 'USDT')
- `trading_quote` - Quote currency for trading pairs
- `margin` - Available margin
- `can_trade` - Trading enabled flag
- `last_notified_account_balance_history_id` - Last balance notification
- `credentials` (JSON, encrypted) - API credentials
- `credentials_testing` (JSON, encrypted) - Testnet credentials
- `created_at`, `updated_at`, `deleted_at` (soft delete)

**Unique Constraint**: `(user_id, name)` - Each user must have unique account names

**CRITICAL**: `trade_configuration_id` is **REQUIRED** when creating accounts

**Encrypted Columns**: `binance_api_key`, `binance_api_secret`, `bybit_api_key`, `bybit_api_secret`

**Relationships**:
- `belongsTo(User)` - Account owner
- `belongsTo(ApiSystem)` - Exchange/API provider
- `belongsTo(TradeConfiguration)` - Trading rules
- `hasMany(Position)` - Open/closed positions
- `hasMany(ForbiddenHostname)` - Blocked API endpoints
- `hasMany(AccountBalanceHistory)` - Balance snapshots

**Morphable**:
- `morphMany(Step)` via `relatable`
- `morphMany(ApiRequestLog)` via `relatable`
- `morphMany(ThrottleLog)` via `contextable`
- `morphMany(NotificationLog)` via `relatable`
- `morphMany(ApiSnapshot)` via `responsable`

**Concerns**: `HasAccessors`, `HasCollections`, `HasScopes`, `HasStatuses`, `HasTokenDiscovery`, `InteractsWithApis`

**Special Method**: `Account::admin(string $apiSystemCanonical)` - Creates in-memory account with admin credentials

**Observer**: `AccountObserver`

---

### ApiSystem

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\ApiSystem` |
| Purpose | External API providers (Binance, Bybit, TAAPI, etc.) |

**Schema**: `id`, `canonical`, `name`, `is_active`, `created_at`, `updated_at`

**Relationships**:
- `hasMany(Account)` - Accounts using this API
- `hasMany(ExchangeSymbol)` - Symbols on this exchange

**Concerns**: `HasScopes`, `InteractsWithApis`

**Observer**: `ApiSystemObserver`

---

### TradeConfiguration

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\TradeConfiguration` |
| Purpose | Trading strategy configuration |

**Schema**:
- `id` - Identifier
- `is_default` - Default configuration flag
- `canonical` - Unique identifier (e.g., "standard")
- `description` - Human-readable description
- `least_timeframe_index_to_change_indicator` - Minimum timeframe index for direction change
- `fast_trade_position_duration_seconds` - Position duration to consider "fast trade"
- `fast_trade_position_closed_age_seconds` - Age threshold for fast trade consideration
- `disable_exchange_symbol_from_negative_pnl_position` - Disable symbol after loss
- `indicator_timeframes` (JSON) - Timeframes for analysis
- `min_account_balance` (decimal, default 100) - Minimum balance for position dispatching
- `created_at`, `updated_at`

**Relationships**: `hasMany(Account)` - Accounts using this config

**Concerns**: `HasGetters`, `HasScopes`

---

### Symbol

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Symbol` |
| Purpose | Trading pair base asset (BTC, ETH, etc.) |

**Schema**: `id`, `token`, `name`, `cmc_id`, `created_at`, `updated_at`

**IMPORTANT**: Symbols use `token` field, NOT `canonical`

**Relationships**: `hasMany(ExchangeSymbol)` - Symbol on different exchanges

**Concerns**: `HasBaseAssetParsing`, `HasScopes`, `InteractsWithApis`

**Observer**: `SymbolObserver`

---

### ExchangeSymbol

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\ExchangeSymbol` |
| Purpose | Trading pair on specific exchange (BTCUSDT on Binance) |

**Schema**:
- `id` - Identifier
- `token` - Base asset token (e.g., 'BTC', 'ETH')
- `quote` - Quote currency (e.g., 'USDT')
- `symbol_id` - Optional FK to symbols (nullable)
- `api_system_id` - FK to api_systems (exchange)
- `is_manually_enabled` (nullable boolean) - Admin override (NULL=auto, true=force enable, false=force disable)
- `auto_disabled` (boolean) - System automatic deactivation flag
- `auto_disabled_reason` (nullable string) - Why system deactivated
- `receives_indicator_data` (boolean) - Whether to fetch indicators
- `direction` - Trading direction (LONG, SHORT, NULL)
- `percentage_gap_long`, `percentage_gap_short` - Entry thresholds
- `price_precision`, `quantity_precision` - Decimal places
- `min_notional`, `tick_size` - Order constraints
- `symbol_information` (JSON) - Exchange metadata
- `leverage_brackets` (JSON) - Leverage tiers
- `mark_price` - Current market price
- `indicators_values` (JSON) - Latest indicator values
- `indicators_timeframe`, `indicators_synced_at`, `mark_price_synced_at`
- `created_at`, `updated_at`

**Three-Tier Status System**:

| Level | Column | Purpose |
|-------|--------|---------|
| Manual Control | `is_manually_enabled` | Admin override (NULL=auto, true=force on, false=force off) |
| Automatic Control | `auto_disabled` | System-driven deactivation |
| Data Fetching | `receives_indicator_data` | Independent from trading status |

**Tradeable Criteria** (both `scopeTradeable()` and `isTradeable()`):

| Condition | Description |
|-----------|-------------|
| `api_statuses->has_taapi_data = true` | Has indicator data |
| `auto_disabled = false` | Not auto-disabled |
| `is_manually_enabled` is null or true | Not manually blocked |
| `direction` is not null | Has concluded direction |
| `tradeable_at` is null or in past | Not in cooldown |
| `mark_price > 0` | Has valid price |

**Relationships**:
- `belongsTo(Symbol)` - Optional CMC metadata
- `belongsTo(ApiSystem)` - Exchange
- `hasMany(PriceHistory)`, `hasMany(Candle)`, `hasMany(LeverageBracket)`

**Concerns**: `HasAccessors`, `HasScopes`, `HasStatuses`, `HasTradingComputations`, `InteractsWithApis`, `SendsNotifications`

**Observer**: `ExchangeSymbolObserver`

---

### Order

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Order` |
| Purpose | Trading order on exchange |

**Schema**: `id`, `uuid`, `account_id`, `exchange_symbol_id`, `exchange_order_id`, `type`, `side`, `position_side`, `quantity`, `price`, `stop_price`, `reduce_only`, `status`, `time_in_force`, `filled_quantity`, `average_fill_price`, `commission`, `commission_asset`, `created_at`, `updated_at`

**Order Types**: MARKET, LIMIT, STOP_MARKET, TAKE_PROFIT_MARKET

**Status Values**: NEW, PARTIALLY_FILLED, FILLED, CANCELED, REJECTED, EXPIRED

**Relationships**:
- `belongsTo(Account)`, `belongsTo(ExchangeSymbol)`
- `hasMany(OrderHistory)` - Fill history

**Concerns**: `HandlesChanges`, `HasGetters`, `HasScopes`, `HasStatuses`, `HasTradingActions`, `InteractsWithApis`

**Observer**: `OrderObserver`

---

### OrderHistory

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\OrderHistory` |
| Purpose | Audit trail of order updates |

**Schema**: `id`, `order_id`, `status`, `filled_quantity`, `remaining_quantity`, `average_price`, `commission`, `timestamp`, `raw_data` (JSON), `created_at`

**Relationships**: `belongsTo(Order)`

---

### Position

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Position` |
| Purpose | Open trading position |

**Schema**: `id`, `uuid`, `account_id`, `exchange_symbol_id`, `side`, `entry_price`, `current_price`, `quantity`, `leverage`, `unrealized_pnl`, `realized_pnl`, `liquidation_price`, `margin`, `margin_type`, `opened_at`, `closed_at`, `status`, `stop_loss_price`, `take_profit_price`, `created_at`, `updated_at`

**Status Values**: OPEN, CLOSED, LIQUIDATED

**Relationships**:
- `belongsTo(Account)`, `belongsTo(ExchangeSymbol)`
- `hasMany(Order)`, `hasMany(Funding)`

**Concerns**: `HasAccessors`, `HasGetters`, `HasScopes`, `HasStatuses`, `HasTradingActions`, `InteractsWithApis`

**Observer**: `PositionObserver`

---

### Step

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Step` |
| Purpose | Job execution tracking in step-based workflows |

**Schema**: `id`, `block_uuid`, `type`, `state`, `class`, `index`, `response` (JSON), `error_message`, `error_stack_trace`, `relatable_type`, `relatable_id`, `child_block_uuid`, `execution_mode`, `double_check`, `queue`, `arguments` (JSON), `retries`, `dispatch_after`, `started_at`, `completed_at`, `duration`, `hostname`, `was_notified`, `created_at`, `updated_at`

**State Machine**: Uses Spatie ModelStates

**States**: Pending, Running, Completed, Failed, Skipped, Cancelled, Stopped

**Relationships**:
- `morphTo(relatable)` - Parent model
- `belongsTo(StepsDispatcherTicks, 'tick_id')`
- `hasMany(Step, 'block_uuid', 'child_block_uuid')` - Child steps

**Concerns**: `HasActions` - Step actions (dispatch, complete, fail)

**Observer**: `StepObserver`

---

### Indicator

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Indicator` |
| Purpose | Technical indicator registry |

**Schema**: `id`, `canonical`, `name`, `description`, `category`, `parameters` (JSON), `is_active`, `priority`, `created_at`, `updated_at`

**Relationships**: `hasMany(IndicatorHistory)`

**Concerns**: `HasScopes`

**Observer**: `IndicatorObserver`

---

### IndicatorHistory

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\IndicatorHistory` |
| Purpose | Time-series indicator values |

**Schema**: `id`, `exchange_symbol_id`, `indicator_id`, `timeframe`, `timestamp`, `value` (JSON), `metadata` (JSON), `created_at`

**Unique Index**: `exchange_symbol_id` + `indicator_id` + `timeframe` + `timestamp`

**Relationships**: `belongsTo(ExchangeSymbol)`, `belongsTo(Indicator)`

---

### Candle

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Candle` |
| Purpose | OHLCV candlestick data |

**Schema**: `id`, `exchange_symbol_id`, `timeframe`, `open`, `high`, `low`, `close`, `volume`, `timestamp`, `created_at`

**Unique Index**: `exchange_symbol_id` + `timeframe` + `timestamp`

**Relationships**: `belongsTo(ExchangeSymbol)`

---

### Notification & NotificationLog

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Notification`, `NotificationLog` |
| Purpose | Notification definitions and delivery history |

**Notification Schema**: `id`, `canonical`, `title`, `message`, `severity`, `is_active`, `created_at`, `updated_at`

**NotificationLog Schema**: `id`, `notification_id`, `relatable_type`, `relatable_id`, `channel`, `sent_at`, `created_at`

---

### ApiRequestLog

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\ApiRequestLog` |
| Purpose | API request/response logging |

**Schema**: `id`, `api_system_id`, `relatable_type`, `relatable_id`, `method`, `endpoint`, `request_body` (JSON), `response_body` (JSON), `http_status_code`, `vendor_code`, `duration`, `created_at`

**Concerns**: `SendsNotifications`

**Observer**: `ApiRequestLogObserver`

---

### Heartbeat

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Models\Heartbeat` |
| Purpose | WebSocket connection health monitoring for price streams |

**Schema**: `id`, `canonical`, `api_system_id`, `account_id`, `group`, `last_beat_at`, `beat_count`, `metadata` (JSON), `last_payload`, `connection_status`, `last_price_data_at`, `connected_at`, `last_close_code`, `last_close_reason`, `internal_reconnect_attempts`, `created_at`, `updated_at`

**Unique Constraint**: `(canonical, api_system_id, account_id, group)`

**Connection Status Values**:

| Status | Description |
|--------|-------------|
| `unknown` | Initial state |
| `connected` | Receiving messages |
| `reconnecting` | Internal reconnect in progress |
| `disconnected` | Max reconnect attempts exhausted |
| `stale` | Zombie connection (open but no messages) |
| `inactive` | Worker not running |

**Observer**: `HeartbeatObserver` - Sends notifications on status transitions

---

### Other Models

| Model | Purpose |
|-------|---------|
| `Funding` | Futures funding rate payments |
| `PriceHistory` | Real-time price snapshots |
| `LeverageBracket` | Leverage tier limits per symbol |
| `AccountBalanceHistory` | Account balance snapshots |
| `StepsDispatcher` | Step dispatcher configuration |
| `StepsDispatcherTicks` | Dispatcher tick tracking |
| `Server` | Worker server registry |
| `ThrottleRule` | Notification throttling rules |
| `ThrottleLog` | Throttle event tracking |
| `ApiSnapshot` | Full API response snapshots |
| `BinanceListenKey` | Binance WebSocket listen key management |
| `ForbiddenHostname` | Blocked API endpoints per account |
| `SlowQuery` | Slow database query logging |
| `Martingalian` | System-wide configuration singleton |

---

## Common Patterns

### Encrypted Columns

Models use Laravel's `encrypted` cast for sensitive data. Encrypted columns include API keys, secrets, and credentials. Access is automatic (decryption on read, encryption on write).

---

### Morphable Relationships

Many models use polymorphic relationships:

| Model | Via | Can Belong To |
|-------|-----|---------------|
| Step | `relatable` | Account, ExchangeSymbol, Order, Position, User |
| ApiRequestLog | `relatable` | Account, ExchangeSymbol |
| NotificationLog | `relatable` | Account, User |
| ThrottleLog | `contextable` | Account, User |
| ApiSnapshot | `responsable` | Account |

---

### Concerns/Traits Pattern

Models use concerns to organize functionality:

| Prefix | Purpose | Examples |
|--------|---------|----------|
| `Has*` | Properties, accessors, computed values | HasScopes, HasAccessors, HasStatuses |
| `Interacts*` | External interactions | InteractsWithApis |

---

### Observer Pattern

Models use observers for lifecycle events:

| Hook | Timing |
|------|--------|
| `creating` | Before insert |
| `created` | After insert |
| `updating` | Before update |
| `updated` | After update |
| `deleting` | Before delete |
| `deleted` | After delete |

---

### Soft Deletes

The `Account` model uses soft deletes to preserve historical data. Soft deleted records have `deleted_at` set and are excluded from queries by default.

---

### Factory Pattern

All models have factories for testing. Factories support:
- Single and batch creation
- Custom attributes
- Named states (e.g., `canTrade()`)

---

## Testing

### Model Tests

**Location**: `tests/Unit/Models/`

**Coverage**: Relationship integrity, accessor/mutator logic, scope correctness, observer behavior

### Integration Tests

**Location**: `tests/Integration/Models/`

**Coverage**: Multi-model workflows, complex queries, observer side effects

---

## Future Enhancements

- Model caching layer (Redis)
- Audit trail for all model changes
- Model-level encryption (entire records)
- Time-series database for IndicatorHistory and PriceHistory
- Read replicas for reporting queries
