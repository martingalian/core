# Data Models Catalog

## Overview
Comprehensive catalog of all Eloquent models in the Martingalian Core package. Documents relationships, concerns/traits, scopes, observers, encryption patterns, and common usage patterns.

## Model Organization

### Base Model
**Location**: `Martingalian\Core\Abstracts\BaseModel`
**Purpose**: Abstract base class for all Core models
**Features**:
- Common functionality shared across models
- Boot logic for observers
- Global scopes

## Core Models

### User
**Location**: `Martingalian\Core\Models\User`
**Purpose**: Application users (traders, admins)

**Schema**:
- `id`, `uuid` - Identifiers
- `name` - User full name
- `email` - Login email
- `password` - Encrypted password
- `remember_token` - Session token
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(Account)` - User's trading accounts

**Morphable**:
- `morphMany(Step)` via `relatable`
- `morphMany(ThrottleLog)` via `contextable`
- `morphMany(NotificationLog)` via `relatable`

**Observer**: `UserObserver`

---

### Account
**Location**: `Martingalian\Core\Models\Account`
**Purpose**: Trading account connected to exchange

**Schema**:
- `id`, `uuid` - Identifiers
- `user_id` - FK to users
- `api_system_id` - FK to api_systems (Binance, Bybit)
- `trade_configuration_id` - FK to trade_configuration
- `portfolio_quote_id` - FK to quotes (portfolio valuation currency)
- `trading_quote_id` - FK to quotes (trading pair quote currency)
- `margin` - Available margin
- `can_trade` - Trading enabled flag
- `last_notified_account_balance_history_id` - Last balance notification
- `credentials` (JSON, encrypted) - API credentials
- `credentials_testing` (JSON, encrypted) - Testnet credentials
- `created_at`, `updated_at`, `deleted_at` (soft delete)

**Encrypted Columns**:
- `binance_api_key`, `binance_api_secret`
- `bybit_api_key`, `bybit_api_secret`
- `coinmarketcap_api_key` (admin-only, in-memory)
- `taapi_secret` (admin-only, in-memory)

**Relationships**:
- `belongsTo(User)` - Account owner
- `belongsTo(ApiSystem)` - Exchange/API provider
- `belongsTo(TradeConfiguration)` - Trading rules
- `belongsTo(Quote, 'portfolio_quote_id')` - Portfolio currency
- `belongsTo(Quote, 'trading_quote_id')` - Trading currency
- `hasMany(Position)` - Open/closed positions
- `hasMany(ForbiddenHostname)` - Blocked API endpoints
- `hasMany(AccountBalanceHistory)` - Balance snapshots

**Morphable**:
- `morphMany(Step)` via `relatable`
- `morphMany(ApiRequestLog)` via `relatable`
- `morphMany(ThrottleLog)` via `contextable`
- `morphMany(NotificationLog)` via `relatable`
- `morphMany(ApiSnapshot)` via `responsable`

**Concerns**:
- `HasAccessors` - Computed properties
- `HasCollections` - Collection helpers
- `HasScopes` - Query scopes (`canTrade()`, `byApiSystem()`)
- `HasStatuses` - Status checks
- `HasTokenDiscovery` - API token discovery logic
- `InteractsWithApis` - API client helpers

**Special Method**:
- `Account::admin(string $apiSystemCanonical)` - Creates in-memory account with admin credentials

**Observer**: `AccountObserver`

---

### ApiSystem
**Location**: `Martingalian\Core\Models\ApiSystem`
**Purpose**: External API providers (Binance, Bybit, TAAPI, etc.)

**Schema**:
- `id` - Identifier
- `canonical` - Unique name (binance, bybit, taapi)
- `name` - Display name
- `is_active` - Enabled flag
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(Account)` - Accounts using this API
- `hasMany(ExchangeSymbol)` - Symbols on this exchange

**Concerns**:
- `HasScopes` - Query scopes (`active()`, `byCanonical()`)
- `InteractsWithApis` - API client instantiation

**Observer**: `ApiSystemObserver`

---

### TradeConfiguration
**Location**: `Martingalian\Core\Models\TradeConfiguration`
**Purpose**: Trading strategy configuration

**Schema**:
- `id` - Identifier
- `is_default` - Default configuration flag
- `disable_exchange_symbol_from_negative_pnl_position` - Disable symbol after loss
- `indicator_timeframes` (JSON) - Timeframes for analysis
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(Account)` - Accounts using this config

**Concerns**:
- `HasGetters` - Retrieval helpers (`getDefault()`)
- `HasScopes` - Query scopes (`default()`)

**No Observer**

---

### Symbol
**Location**: `Martingalian\Core\Models\Symbol`
**Purpose**: Trading pair base asset (BTC, ETH, etc.)

**Schema**:
- `id` - Identifier
- `canonical` - Unique name (btc, eth)
- `name` - Display name (Bitcoin, Ethereum)
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(ExchangeSymbol)` - Symbol on different exchanges

**Concerns**:
- `HasBaseAssetParsing` - Parse base asset from trading pair
- `HasScopes` - Query scopes
- `InteractsWithApis` - API interactions

**Observer**: `SymbolObserver`

---

### Quote
**Location**: `Martingalian\Core\Models\Quote`
**Purpose**: Quote currency (USDT, USD, BTC)

**Schema**:
- `id` - Identifier
- `canonical` - Unique name (usdt, usd)
- `name` - Display name
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(ExchangeSymbol)` - Symbols quoted in this currency
- `hasMany(Account, 'portfolio_quote_id')` - Accounts valued in this currency
- `hasMany(Account, 'trading_quote_id')` - Accounts trading in this currency

**No Concerns**

**Observer**: `QuoteObserver`

---

### ExchangeSymbol
**Location**: `Martingalian\Core\Models\ExchangeSymbol`
**Purpose**: Trading pair on specific exchange (BTCUSDT on Binance)

**Schema**:
- `id` - Identifier
- `symbol_id` - FK to symbols (base asset)
- `quote_id` - FK to quotes (quote asset)
- `api_system_id` - FK to api_systems (exchange)
- `is_active` - Trading enabled
- `direction` - Trading direction (LONG, SHORT, NULL)
- `percentage_gap_long` - Entry threshold for LONG
- `percentage_gap_short` - Entry threshold for SHORT
- `price_precision` - Decimal places for price
- `quantity_precision` - Decimal places for quantity
- `min_notional` - Minimum order value
- `tick_size` - Minimum price increment
- `symbol_information` (JSON) - Exchange metadata
- `leverage_brackets` (JSON) - Leverage tiers
- `mark_price` - Current market price
- `indicators_values` (JSON) - Latest indicator values
- `indicators_timeframe` - Timeframe for direction conclusion
- `indicators_synced_at` - Last indicator refresh
- `mark_price_synced_at` - Last price update
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Symbol)` - Base asset
- `belongsTo(Quote)` - Quote asset
- `belongsTo(ApiSystem)` - Exchange
- `hasMany(PriceHistory)` - Price snapshots
- `hasMany(Candle)` - OHLCV candles
- `hasMany(LeverageBracket)` - Leverage tiers

**Morphable**:
- `morphMany(Step)` via `relatable`
- `morphMany(ApiRequestLog)` via `relatable`

**Concerns**:
- `HasAccessors` - Computed properties (`parsed_trading_pair`)
- `HasScopes` - Query scopes (`active()`, `byDirection()`, `bySymbol()`)
- `HasStatuses` - Status checks
- `HasTradingComputations` - Position sizing, risk calculations
- `InteractsWithApis` - Exchange API interactions

**Observer**: `ExchangeSymbolObserver`

---

### Order
**Location**: `Martingalian\Core\Models\Order`
**Purpose**: Trading order on exchange

**Schema**:
- `id`, `uuid` - Identifiers
- `account_id` - FK to accounts
- `exchange_symbol_id` - FK to exchange_symbols
- `exchange_order_id` - Exchange's order ID
- `type` - MARKET, LIMIT, STOP_MARKET, TAKE_PROFIT_MARKET
- `side` - BUY, SELL
- `position_side` - LONG, SHORT
- `quantity` - Amount to trade
- `price` - Limit price (null for MARKET)
- `stop_price` - Stop trigger price
- `reduce_only` - Close position only flag
- `status` - NEW, PARTIALLY_FILLED, FILLED, CANCELED, REJECTED, EXPIRED
- `time_in_force` - GTC, IOC, FOK
- `filled_quantity` - Amount executed
- `average_fill_price` - Weighted average price
- `commission` - Trading fees
- `commission_asset` - Fee currency
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Account)` - Order account
- `belongsTo(ExchangeSymbol)` - Trading symbol
- `hasMany(OrderHistory)` - Fill history

**Morphable**:
- `morphMany(Step)` via `relatable`

**Concerns**:
- `HandlesChanges` - Change tracking
- `HasGetters` - Retrieval helpers
- `HasScopes` - Query scopes (`filled()`, `open()`)
- `HasStatuses` - Status checks
- `HasTradingActions` - Trading operations
- `InteractsWithApis` - Exchange API interactions

**Observer**: `OrderObserver`

---

### OrderHistory
**Location**: `Martingalian\Core\Models\OrderHistory`
**Purpose**: Audit trail of order updates

**Schema**:
- `id` - Identifier
- `order_id` - FK to orders
- `status` - Order status at this point
- `filled_quantity` - Cumulative filled
- `remaining_quantity` - Unfilled amount
- `average_price` - Fill price
- `commission` - Fees this update
- `timestamp` - Exchange timestamp
- `raw_data` (JSON) - Full exchange response
- `created_at`

**Relationships**:
- `belongsTo(Order)` - Parent order

**No Concerns**

**No Observer**

---

### Position
**Location**: `Martingalian\Core\Models\Position`
**Purpose**: Open trading position

**Schema**:
- `id`, `uuid` - Identifiers
- `account_id` - FK to accounts
- `exchange_symbol_id` - FK to exchange_symbols
- `side` - LONG, SHORT
- `entry_price` - Average entry price
- `current_price` - Latest market price
- `quantity` - Position size
- `leverage` - Leverage multiplier
- `unrealized_pnl` - Current profit/loss
- `realized_pnl` - Closed profit/loss
- `liquidation_price` - Forced closure price
- `margin` - Collateral used
- `margin_type` - ISOLATED, CROSS
- `opened_at` - Position open time
- `closed_at` - Position close time
- `status` - OPEN, CLOSED, LIQUIDATED
- `stop_loss_price` - SL trigger
- `take_profit_price` - TP trigger
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Account)` - Position account
- `belongsTo(ExchangeSymbol)` - Trading symbol
- `hasMany(Order)` - Orders for this position
- `hasMany(Funding)` - Funding payments

**Concerns**:
- `HasAccessors` - Computed properties
- `HasGetters` - Retrieval helpers
- `HasScopes` - Query scopes (`open()`, `closed()`, `bySymbol()`)
- `HasStatuses` - Status checks
- `HasTradingActions` - Trading operations
- `InteractsWithApis` - Exchange API interactions

**Observer**: `PositionObserver`

---

### Funding
**Location**: `Martingalian\Core\Models\Funding`
**Purpose**: Futures funding rate payments

**Schema**:
- `id` - Identifier
- `position_id` - FK to positions
- `rate` - Funding rate percentage
- `amount` - Payment amount (negative = paid, positive = received)
- `timestamp` - Payment time
- `created_at`

**Relationships**:
- `belongsTo(Position)` - Parent position

**No Concerns**

**No Observer**

---

### Indicator
**Location**: `Martingalian\Core\Models\Indicator`
**Purpose**: Technical indicator registry

**Schema**:
- `id` - Identifier
- `canonical` - Unique name (rsi, macd, ema_50)
- `name` - Display name
- `description` - Indicator explanation
- `category` - RefreshData, History, Reports
- `parameters` (JSON) - Configuration
- `is_active` - Enabled flag
- `priority` - Calculation order
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(IndicatorHistory)` - Historical values

**Concerns**:
- `HasScopes` - Query scopes (`active()`, `byCategory()`)

**Observer**: `IndicatorObserver`

---

### IndicatorHistory
**Location**: `Martingalian\Core\Models\IndicatorHistory`
**Purpose**: Time-series indicator values

**Schema**:
- `id` - Identifier
- `exchange_symbol_id` - FK to exchange_symbols
- `indicator_id` - FK to indicators
- `timeframe` - Candle interval (1m, 5m, 1h, 1d)
- `timestamp` - Data point time
- `value` (JSON) - Indicator values
- `metadata` (JSON) - Additional context
- `created_at`

**Relationships**:
- `belongsTo(ExchangeSymbol)` - Symbol
- `belongsTo(Indicator)` - Indicator type

**Indexes**:
- Composite unique: `exchange_symbol_id` + `indicator_id` + `timeframe` + `timestamp`

**No Concerns**

**No Observer**

---

### Candle
**Location**: `Martingalian\Core\Models\Candle`
**Purpose**: OHLCV candlestick data

**Schema**:
- `id` - Identifier
- `exchange_symbol_id` - FK to exchange_symbols
- `timeframe` - Interval (1m, 5m, 1h, 1d)
- `open` - Opening price
- `high` - High price
- `low` - Low price
- `close` - Closing price
- `volume` - Trading volume
- `timestamp` - Candle open time
- `created_at`

**Relationships**:
- `belongsTo(ExchangeSymbol)` - Symbol

**Indexes**:
- Composite unique: `exchange_symbol_id` + `timeframe` + `timestamp`

**No Concerns**

**No Observer**

---

### PriceHistory
**Location**: `Martingalian\Core\Models\PriceHistory`
**Purpose**: Real-time price snapshots

**Schema**:
- `id` - Identifier
- `exchange_symbol_id` - FK to exchange_symbols
- `price` - Market price
- `timestamp` - Price time
- `created_at`

**Relationships**:
- `belongsTo(ExchangeSymbol)` - Symbol

**No Concerns**

**No Observer**

---

### LeverageBracket
**Location**: `Martingalian\Core\Models\LeverageBracket`
**Purpose**: Leverage tier limits per symbol

**Schema**:
- `id` - Identifier
- `exchange_symbol_id` - FK to exchange_symbols
- `bracket` - Tier number
- `initial_leverage` - Max leverage for bracket
- `notional_cap` - Max position size
- `notional_floor` - Min position size
- `maintenance_margin_rate` - Maintenance margin %
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(ExchangeSymbol)` - Symbol

**No Concerns**

**No Observer**

---

### AccountBalanceHistory
**Location**: `Martingalian\Core\Models\AccountBalanceHistory`
**Purpose**: Account balance snapshots

**Schema**:
- `id` - Identifier
- `account_id` - FK to accounts
- `balance` - Account balance
- `margin_balance` - Available margin
- `timestamp` - Snapshot time
- `created_at`

**Relationships**:
- `belongsTo(Account)` - Account

**Observer**: `AccountBalanceHistoryObserver`

---

### Step
**Location**: `Martingalian\Core\Models\Step`
**Purpose**: Job execution tracking in step-based workflows

**Schema**:
- `id` - Identifier
- `block_uuid` - Block group identifier
- `type` - default, resolve-exception
- `state` - Pending, Running, Completed, Failed, Skipped, Cancelled, Stopped
- `class` - Job class name
- `index` - Execution order
- `response` (JSON) - Job result
- `error_message` - Exception message
- `error_stack_trace` - Stack trace
- `relatable_type`, `relatable_id` - Polymorphic parent
- `child_block_uuid` - Nested block reference
- `execution_mode` - sync, async
- `double_check` - Re-verify flag
- `queue` - Queue name
- `arguments` (JSON) - Job parameters
- `retries` - Retry count
- `dispatch_after` - Scheduled dispatch time
- `started_at` - Job start time
- `completed_at` - Job end time
- `duration` - Execution duration
- `hostname` - Worker hostname
- `was_notified` - Notification sent flag
- `created_at`, `updated_at`

**Relationships**:
- `morphTo(relatable)` - Parent model
- `belongsTo(StepsDispatcherTicks, 'tick_id')` - Dispatch tick
- `hasMany(Step, 'block_uuid', 'child_block_uuid')` - Child steps

**State Machine**: Uses Spatie ModelStates
- States: `Pending`, `Running`, `Completed`, `Failed`, `Skipped`, `Cancelled`, `Stopped`

**Concerns**:
- `HasActions` - Step actions (dispatch, complete, fail)

**Observer**: `StepObserver`

---

### StepsDispatcher
**Location**: `Martingalian\Core\Models\StepsDispatcher`
**Purpose**: Step dispatcher configuration

**Schema**:
- `id` - Identifier
- `worker_server_id` - FK to servers
- `dispatches_per_second` - Dispatch rate
- `last_dispatched_step_id` - Last dispatched step
- `is_active` - Dispatcher enabled
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Server, 'worker_server_id')` - Worker server

**Static Method**:
- `StepsDispatcher::getDispatchGroup()` - Get random dispatch group

**No Concerns**

**No Observer**

---

### StepsDispatcherTicks
**Location**: `Martingalian\Core\Models\StepsDispatcherTicks`
**Purpose**: Dispatcher tick tracking

**Schema**:
- `id` - Identifier
- `steps_dispatcher_id` - FK to steps_dispatchers
- `started_at` - Tick start
- `ended_at` - Tick end
- `created_at`

**Relationships**:
- `belongsTo(StepsDispatcher)` - Dispatcher
- `hasMany(Step, 'tick_id')` - Steps dispatched this tick

**No Concerns**

**No Observer**

---

### Server
**Location**: `Martingalian\Core\Models\Server`
**Purpose**: Worker server registry

**Schema**:
- `id` - Identifier
- `hostname` - Server hostname
- `ip_address` - Server IP
- `is_active` - Server enabled
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(StepsDispatcher)` - Dispatchers on this server

**No Concerns**

**No Observer**

---

### Repeater
**Location**: `Martingalian\Core\Models\Repeater`
**Purpose**: Scheduled task configuration

**Schema**:
- `id` - Identifier
- `canonical` - Unique name
- `class` - Job class
- `arguments` (JSON) - Job parameters
- `frequency` - Cron expression
- `is_active` - Enabled flag
- `last_run_at` - Last execution
- `created_at`, `updated_at`

**No Relationships**

**Observer**: `RepeaterObserver`

---

### Notification
**Location**: `Martingalian\Core\Models\Notification`
**Purpose**: Notification definitions

**Schema**:
- `id` - Identifier
- `canonical` - Unique name
- `title` - Notification title
- `message` - Notification body
- `severity` - info, warning, critical
- `is_active` - Enabled flag
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(NotificationLog)` - Notification history

**Concerns**:
- `HasGetters` - Retrieval helpers
- `HasScopes` - Query scopes (`active()`, `bySeverity()`)

**No Observer**

---

### NotificationLog
**Location**: `Martingalian\Core\Models\NotificationLog`
**Purpose**: Notification delivery history

**Schema**:
- `id` - Identifier
- `notification_id` - FK to notifications
- `relatable_type`, `relatable_id` - Polymorphic context
- `channel` - pushover, email, slack
- `sent_at` - Delivery time
- `created_at`

**Relationships**:
- `belongsTo(Notification)` - Notification type
- `morphTo(relatable)` - Context model

**No Concerns**

**No Observer**

---

### ThrottleRule
**Location**: `Martingalian\Core\Models\ThrottleRule`
**Purpose**: Notification throttling rules

**Schema**:
- `id` - Identifier
- `canonical` - Unique name
- `description` - Rule description
- `throttle_seconds` - Minimum time between notifications
- `is_active` - Enabled flag
- `created_at`, `updated_at`

**Relationships**:
- `hasMany(ThrottleLog)` - Throttle events

**No Concerns**

**No Observer**

---

### ThrottleLog
**Location**: `Martingalian\Core\Models\ThrottleLog`
**Purpose**: Throttle event tracking

**Schema**:
- `id` - Identifier
- `throttle_rule_id` - FK to throttle_rules
- `contextable_type`, `contextable_id` - Polymorphic context
- `triggered_at` - Event time
- `created_at`

**Relationships**:
- `belongsTo(ThrottleRule)` - Throttle rule
- `morphTo(contextable)` - Context model

**No Concerns**

**No Observer**

---

### ApiRequestLog
**Location**: `Martingalian\Core\Models\ApiRequestLog`
**Purpose**: API request/response logging

**Schema**:
- `id` - Identifier
- `api_system_id` - FK to api_systems
- `relatable_type`, `relatable_id` - Polymorphic context
- `method` - GET, POST, DELETE
- `endpoint` - API endpoint
- `request_body` (JSON) - Request payload
- `response_body` (JSON) - Response payload
- `http_status_code` - HTTP status
- `vendor_code` - Exchange error code
- `duration` - Request duration (ms)
- `created_at`

**Relationships**:
- `belongsTo(ApiSystem)` - API provider
- `morphTo(relatable)` - Context model

**Concerns**:
- `SendsNotifications` - Notification triggers

**Observer**: `ApiRequestLogObserver`

---

### ApiSnapshot
**Location**: `Martingalian\Core\Models\ApiSnapshot`
**Purpose**: Full API response snapshots

**Schema**:
- `id` - Identifier
- `responsable_type`, `responsable_id` - Polymorphic parent
- `payload` (JSON) - Full response
- `created_at`

**Relationships**:
- `morphTo(responsable)` - Parent model

**Observer**: `ApiSnapshotObserver`

---

### BinanceListenKey
**Location**: `Martingalian\Core\Models\BinanceListenKey`
**Purpose**: Binance WebSocket listen key management

**Schema**:
- `id` - Identifier
- `account_id` - FK to accounts
- `listen_key` - WebSocket key
- `expires_at` - Key expiration
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Account)` - Account

**No Concerns**

**No Observer**

---

### BaseAssetMapper
**Location**: `Martingalian\Core\Models\BaseAssetMapper`
**Purpose**: Maps exchange-specific symbol names to canonical symbols

**Schema**:
- `id` - Identifier
- `api_system_id` - FK to api_systems
- `symbol_id` - FK to symbols
- `exchange_base_asset` - Exchange's symbol name (e.g., "1000PEPEUSDT")
- `canonical_base_asset` - Canonical name (e.g., "PEPE")
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(ApiSystem)` - Exchange
- `belongsTo(Symbol)` - Canonical symbol

**Observer**: `BaseAssetMapperObserver`

---

### ForbiddenHostname
**Location**: `Martingalian\Core\Models\ForbiddenHostname`
**Purpose**: Blocked API endpoints per account

**Schema**:
- `id` - Identifier
- `account_id` - FK to accounts
- `hostname` - Blocked hostname/endpoint
- `created_at`, `updated_at`

**Relationships**:
- `belongsTo(Account)` - Account

**Observer**: `ForbiddenHostnameObserver`

---

### SlowQuery
**Location**: `Martingalian\Core\Models\SlowQuery`
**Purpose**: Slow database query logging

**Schema**:
- `id` - Identifier
- `query` - SQL query
- `duration` - Query duration (ms)
- `bindings` (JSON) - Query bindings
- `created_at`

**No Relationships**

**No Concerns**

**No Observer**

---

### Martingalian
**Location**: `Martingalian\Core\Models\Martingalian`
**Purpose**: System-wide configuration and admin credentials

**Schema**:
- `id` - Identifier (always 1)
- `all_credentials` (JSON, encrypted) - All admin API credentials
- `created_at`, `updated_at`

**No Relationships**

**Concerns**:
- `HasAccessors` - Credential access helpers

**No Observer**

---

## Common Patterns

### Encrypted Columns
Models use Laravel's `encrypted` cast for sensitive data:

```php
protected $casts = [
    'binance_api_key' => 'encrypted',
    'binance_api_secret' => 'encrypted',
];
```

**Access**:
```php
$account->binance_api_key; // Automatically decrypted
$account->binance_api_key = 'new_key'; // Automatically encrypted
```

### Morphable Relationships
Many models use polymorphic relationships:

**Step** can belong to any model:
```php
// Create step for ExchangeSymbol
$exchangeSymbol->steps()->create([...]);

// Create step for Account
$account->steps()->create([...]);
```

**Common Morphables**:
- `Step` → `relatable` (Account, ExchangeSymbol, Order, Position, User)
- `ApiRequestLog` → `relatable` (Account, ExchangeSymbol)
- `NotificationLog` → `relatable` (Account, User)
- `ThrottleLog` → `contextable` (Account, User)
- `ApiSnapshot` → `responsable` (Account)

### Concerns/Traits Pattern
Models use concerns to organize functionality:

**Naming Convention**:
- `Has*` - Properties, accessors, computed values
- `Interacts*` - External interactions (APIs, services)

**Common Concerns**:
- `HasScopes` - Query scopes
- `HasAccessors` - Computed properties
- `HasStatuses` - Status checks
- `HasGetters` - Retrieval helpers
- `InteractsWithApis` - API client interactions

**Example**:
```php
// Account\HasScopes
public function scopeCanTrade($query)
{
    return $query->where('can_trade', true);
}

// Usage:
$accounts = Account::canTrade()->get();
```

### Observer Pattern
Models use observers for lifecycle events:

**Common Observer Hooks**:
- `creating` - Before insert
- `created` - After insert
- `updating` - Before update
- `updated` - After update
- `deleting` - Before delete
- `deleted` - After delete

**Example** (ApiRequestLogObserver):
```php
public function created(ApiRequestLog $log)
{
    // Send notification if critical error
    if ($this->isCriticalError($log)) {
        NotificationService::critical("API error: {$log->vendor_code}");
    }
}
```

### Soft Deletes
Some models use soft deletes:
- `Account` - Preserve historical data

```php
// Soft delete
$account->delete(); // Sets deleted_at

// Query including soft deleted
$accounts = Account::withTrashed()->get();

// Only soft deleted
$accounts = Account::onlyTrashed()->get();

// Restore
$account->restore();

// Permanently delete
$account->forceDelete();
```

### Factory Pattern
All models have factories for testing:

```php
// Create single instance
$account = Account::factory()->create();

// Create multiple
$accounts = Account::factory()->count(10)->create();

// With custom attributes
$account = Account::factory()->create([
    'can_trade' => false,
]);

// With state
$account = Account::factory()->canTrade()->create();
```

## Testing

### Model Tests
**Location**: `tests/Unit/Models/`
- Relationship integrity
- Accessor/mutator logic
- Scope correctness
- Observer behavior

### Integration Tests
**Location**: `tests/Integration/Models/`
- Multi-model workflows
- Complex queries
- Observer side effects

## Future Enhancements
- Model caching layer (Redis)
- Audit trail for all model changes
- Model-level encryption (entire records)
- Time-series database for IndicatorHistory and PriceHistory
- Read replicas for reporting queries
