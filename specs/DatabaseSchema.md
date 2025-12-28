# Database Schema

## Overview

MySQL 8.0+ database schema for cryptocurrency trading automation. Schema focuses on multi-user, multi-exchange account management with position tracking, order history, and balance snapshots.

---

## Important Notes

- **ALL migrations live in**: `packages/martingalian/core/database/migrations/`
- **Seeders should be called from migrations**: Migration files should directly call their corresponding seeder in the `up()` method after creating tables
- Always use `DB::transaction()` for related operations
- Always use pessimistic locking for concurrent updates: `->lockForUpdate()`

---

## Core Tables

### martingalian

Global application configuration (singleton table - only 1 row).

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `allow_opening_positions` | boolean | Global toggle for opening new trading positions |
| `can_dispatch_steps` | boolean | Circuit breaker - stops step dispatcher (enables graceful Horizon restarts) |
| `binance_api_key` | text (encrypted) | Default Binance credentials |
| `binance_api_secret` | text (encrypted) | Default Binance credentials |
| `bybit_api_key` | text (encrypted) | Default Bybit credentials |
| `bybit_api_secret` | text (encrypted) | Default Bybit credentials |
| `kraken_api_key` | text (encrypted) | Default Kraken Futures credentials |
| `kraken_private_key` | text (encrypted) | Default Kraken Futures credentials |
| `coinmarketcap_api_key` | text (encrypted) | CoinMarketCap API key |
| `taapi_secret` | text (encrypted) | TaaPI indicator service secret |
| `admin_pushover_user_key` | text (encrypted) | Admin notification credentials |
| `admin_pushover_application_key` | text (encrypted) | Admin notification credentials |
| `email` | varchar(255) | Admin email address |
| `notification_channels` | JSON | Enabled notification channels (mail, pushover) |

**Usage Notes**:
- See `StepDispatcher.md` for circuit breaker documentation and deployment workflow

---

### users

User accounts with notification preferences.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `name` | varchar(255) | User name |
| `email` | varchar(255) | Unique email |
| `email_verified_at` | timestamp | Email verification time |
| `password` | varchar(255) | Hashed password |
| `is_active` | boolean | Controls notification delivery |
| `is_admin` | boolean | Receives admin notifications |
| `notification_channels` | JSON | Enabled channels (mail, pushover) |
| `pushover_user_key` | varchar(255) | Individual Pushover key |
| `remember_token` | varchar(100) | Session token |
| `created_at` | timestamp | Creation time |
| `updated_at` | timestamp | Last update |

**Relationships**: Has many accounts, positions (through accounts), orders (through accounts)

---

### accounts

Exchange API credentials and account configuration.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | bigint (FK) | Owner |
| `api_system_id` | bigint (FK) | Exchange system (binance, bybit, kraken) |
| `portfolio_quote` | varchar(20) | Quote currency for portfolio display |
| `trading_quote` | varchar(20) | Quote currency for trading pairs |
| `canonical` | varchar(255) | User-friendly name |
| `binance_api_key` | text (encrypted) | Binance credentials |
| `binance_api_secret` | text (encrypted) | Binance credentials |
| `bybit_api_key` | text (encrypted) | Bybit credentials |
| `bybit_api_secret` | text (encrypted) | Bybit credentials |
| `kraken_api_key` | text (encrypted) | Kraken Futures credentials |
| `kraken_private_key` | text (encrypted) | Kraken Futures credentials |
| `is_active` | boolean | Account enabled for operations |
| `can_trade` | boolean | Account enabled for trading |
| `created_at` | timestamp | Creation time |
| `updated_at` | timestamp | Last update |

**Relationships**: Belongs to user, has many positions, orders, account_balances

---

### positions

Open and closed trading positions.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `exchange_account_id` | bigint (FK) | Account reference |
| `symbol` | varchar(50) | Trading pair (BTCUSDT, ETHUSDT) |
| `side` | varchar(10) | Direction (long, short) |
| `entry_price` | decimal(20,8) | Average entry price |
| `current_price` | decimal(20,8) | Last known price |
| `quantity` | decimal(20,8) | Position size |
| `leverage` | int | Leverage multiplier (1 = no leverage) |
| `unrealized_pnl` | decimal(20,8) | Current profit/loss (open positions) |
| `realized_pnl` | decimal(20,8) | Final profit/loss (closed positions) |
| `status` | varchar(20) | Status (open, closed) |
| `opened_at` | timestamp | Opening time |
| `closed_at` | timestamp | Closing time |

**Relationships**: Belongs to exchange_account, has many orders

---

### orders

Individual buy/sell orders.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `position_id` | bigint (FK, nullable) | Position reference |
| `exchange_account_id` | bigint (FK) | Account reference |
| `exchange_order_id` | varchar(255) | Exchange-provided ID |
| `symbol` | varchar(50) | Trading pair |
| `side` | varchar(10) | Direction (buy, sell) |
| `type` | varchar(20) | Order type (market, limit, stop_loss) |
| `price` | decimal(20,8) | Limit price (NULL for market) |
| `quantity` | decimal(20,8) | Order size |
| `filled_quantity` | decimal(20,8) | Amount executed |
| `status` | varchar(20) | Status (pending, filled, cancelled, rejected) |
| `time_in_force` | varchar(10) | Duration (GTC, IOC, FOK) |
| `placed_at` | timestamp | Placement time |
| `filled_at` | timestamp | Fill time |
| `cancelled_at` | timestamp | Cancellation time |

**Relationships**: Belongs to position (optional), belongs to exchange_account

---

### account_balances

Periodic snapshots of account balances.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `exchange_account_id` | bigint (FK) | Account reference |
| `asset` | varchar(20) | Currency/token (USDT, BTC, ETH) |
| `free` | decimal(20,8) | Available balance |
| `locked` | decimal(20,8) | Locked in orders |
| `total` | decimal(20,8) | free + locked |
| `snapshot_at` | timestamp | Snapshot time |

**Purpose**: Historical balance tracking for analysis and reporting

---

### throttle_logs (DEPRECATED - REMOVED)

**Status**: Deprecated and removed from codebase

**Reason**: Throttling now uses `notification_logs` table for dual purpose (audit + throttle). The `throttle_logs` table was redundant and has been completely removed.

---

## Migration Patterns

### Creating Migrations

Use `php artisan make:migration create_positions_table` without `--path` flag. The package configuration will create migrations in `packages/martingalian/core/database/migrations/`.

### Modifying Columns

**IMPORTANT**: When modifying a column, you MUST include ALL attributes. Missing attributes will be lost.

### Foreign Keys

**IMPORTANT**: Never use `->onDelete('cascade')` - handle deletions explicitly in application code.

---

## Database Transactions

### Always Use Transactions

For related database operations, wrap them in `DB::transaction()`.

### Pessimistic Locking

For concurrent updates, use `->lockForUpdate()` to prevent race conditions.

---

## Eloquent Models

### Model Location

- Core models: `packages/martingalian/core/src/Models/`
- App-specific models: `app/Models/`

### Model Conventions

| Convention | Description |
|------------|-------------|
| HasFactory trait | Required for testing |
| fillable array | List all mass-assignable attributes |
| casts() method | Laravel 12 style, define type casts |
| Relationships | Define all relationships with return types |
| Scopes | Use query scopes for common filters |

---

## Query Best Practices

### Prevent N+1 Queries

Use eager loading with `with()` for relationships.

### Use Query Scopes

Define scopes like `scopeOpen()`, `scopeForSymbol()` for reusable query logic.

### Chunk Large Datasets

Use `chunk()` for processing large datasets to avoid memory issues.

### Use DB::transaction()

Wrap related operations in transactions.

### Always Use Model::create()

Never use `Model::insert()` as it skips observers.

---

## Performance Considerations

### Indexes

| Rule | Description |
|------|-------------|
| Foreign keys | Always index |
| WHERE columns | Index frequently filtered columns |
| ORDER BY columns | Index for sort performance |
| Composite indexes | Use for multi-column queries |

### Decimals

| Rule | Description |
|------|-------------|
| Precision | Use DECIMAL(20, 8) for financial data |
| Never use FLOAT | Float causes precision errors |
| Storage format | 20 digits total, 8 after decimal |

**Testing Implications**: Database stores ALL financial values with 8 decimal places. When testing, match the precision:
- Use `'51000.00000000'` not `'51000'`
- Applies to all DECIMAL(20, 8) columns

### Timestamps

- Use TIMESTAMP for specific points in time
- TIMESTAMP supports timezones
- Index for range queries

### JSON Columns

- Use for flexible, non-relational data
- Index with virtual columns if needed
- Query with `whereJsonContains()`

---

## Backup & Maintenance

### Backup Strategy

| Setting | Value |
|---------|-------|
| Frequency | Daily full backups |
| Recovery | Point-in-time recovery enabled |
| Retention | 30 days |
| Testing | Monthly restore verification |

### Maintenance

| Task | Frequency |
|------|-----------|
| Optimize tables | Monthly |
| Analyze tables | Monthly |
| Archive old data | As needed |
| Monitor slow queries | Continuous |

### Cleanup Tasks

| Data | Archive After |
|------|---------------|
| notification_logs | 30 days |
| closed positions | 90 days |
| balance snapshots | 365 days |
