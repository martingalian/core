# Database Schema

## Overview
MySQL 8.0+ database schema for cryptocurrency trading automation. Schema focuses on multi-user, multi-exchange account management with position tracking, order history, and balance snapshots.

## Important Notes

- **ALL migrations live in**: `packages/martingalian/core/database/migrations/`
- Use `php artisan make:migration` which respects package configuration and will create migrations in the correct location
- **Seeders should be called from migrations**: Migration files should directly call their corresponding seeder in the `up()` method after creating tables. We no longer use `--seeder` flag when running migrations.
- Always use `DB::transaction()` for related operations
- Always use pessimistic locking for concurrent updates: `->lockForUpdate()`

## Core Tables

### martingalian

Global application configuration (singleton table - only 1 row).

```sql
CREATE TABLE martingalian (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allow_opening_positions BOOLEAN DEFAULT TRUE,
    can_dispatch_steps BOOLEAN DEFAULT TRUE COMMENT 'Global circuit breaker: stops step dispatcher from dispatching new steps (allows graceful Horizon restarts)',
    binance_api_key TEXT NULL COMMENT 'Encrypted',
    binance_api_secret TEXT NULL COMMENT 'Encrypted',
    bybit_api_key TEXT NULL COMMENT 'Encrypted',
    bybit_api_secret TEXT NULL COMMENT 'Encrypted',
    kraken_api_key TEXT NULL COMMENT 'Encrypted',
    kraken_private_key TEXT NULL COMMENT 'Encrypted',
    coinmarketcap_api_key TEXT NULL COMMENT 'Encrypted',
    taapi_secret TEXT NULL COMMENT 'Encrypted',
    admin_pushover_user_key TEXT NULL COMMENT 'Encrypted',
    admin_pushover_application_key TEXT NULL COMMENT 'Encrypted',
    email VARCHAR(255) NULL,
    notification_channels JSON NULL COMMENT '["mail", "pushover"]',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

**Key Fields**:
- `allow_opening_positions`: Global toggle for opening new trading positions
- `can_dispatch_steps`: **Circuit breaker** - When `false`, StepDispatcher stops dispatching new jobs (enables graceful Horizon restarts)
- `binance_api_key`/`binance_api_secret`: Default Binance credentials (encrypted)
- `bybit_api_key`/`bybit_api_secret`: Default Bybit credentials (encrypted)
- `kraken_api_key`/`kraken_private_key`: Default Kraken Futures credentials (encrypted)
- `coinmarketcap_api_key`: CoinMarketCap API key (encrypted)
- `taapi_secret`: TaaPI indicator service secret (encrypted)
- `admin_pushover_user_key`/`admin_pushover_application_key`: Admin notification credentials (encrypted)
- `email`: Admin email address
- `notification_channels`: JSON array of enabled notification channels

**Usage**:
```php
// Get global config (singleton)
$config = Martingalian::first();

// Disable circuit breaker (stop new job dispatches)
$config->update(['can_dispatch_steps' => false]);

// Enable circuit breaker (resume normal operation)
$config->update(['can_dispatch_steps' => true]);

// Check if safe to restart Horizon
if (StepDispatcher::canSafelyRestart()) {
    // Safe to restart
}
```

**Circuit Breaker Pattern**:
See `StepDispatcher.md` for detailed circuit breaker documentation and deployment workflow.

### users

User accounts with notification preferences.

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_admin BOOLEAN DEFAULT FALSE,
    notification_channels JSON NULL COMMENT '["mail", "pushover"]',
    pushover_user_key VARCHAR(255) NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_is_active (is_active),
    INDEX idx_is_admin (is_admin)
);
```

**Key Fields**:
- `is_active`: Controls notification delivery (false = no notifications)
- `is_admin`: Receives admin notifications (exceptions, alerts)
- `notification_channels`: JSON array of enabled channels (mail, pushover)
- `pushover_user_key`: For individual Pushover notifications (not delivery groups)

**Relationships**:
- Has many `exchange_accounts`
- Has many `positions` (through exchange_accounts)
- Has many `orders` (through exchange_accounts)

### accounts

Exchange API credentials and account configuration. Each account is linked to a user and an api_system.

```sql
CREATE TABLE accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    api_system_id BIGINT UNSIGNED NOT NULL COMMENT 'References api_systems (binance, bybit, kraken)',
    quote_id BIGINT UNSIGNED NOT NULL COMMENT 'Trading quote currency (USDT, USD)',
    canonical VARCHAR(255) NOT NULL COMMENT 'User-friendly name',
    binance_api_key TEXT NULL COMMENT 'Encrypted - for Binance accounts',
    binance_api_secret TEXT NULL COMMENT 'Encrypted - for Binance accounts',
    bybit_api_key TEXT NULL COMMENT 'Encrypted - for Bybit accounts',
    bybit_api_secret TEXT NULL COMMENT 'Encrypted - for Bybit accounts',
    kraken_api_key TEXT NULL COMMENT 'Encrypted - for Kraken accounts',
    kraken_private_key TEXT NULL COMMENT 'Encrypted - for Kraken accounts',
    is_active BOOLEAN DEFAULT TRUE,
    can_trade BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (api_system_id) REFERENCES api_systems(id),
    FOREIGN KEY (quote_id) REFERENCES quotes(id),
    INDEX idx_user_id (user_id),
    INDEX idx_api_system_id (api_system_id),
    INDEX idx_is_active (is_active)
);
```

**Key Fields**:
- `api_system_id`: References api_systems table (binance, bybit, kraken)
- `quote_id`: Trading quote currency (USDT for Binance/Bybit, USD for Kraken)
- `binance_api_key`/`binance_api_secret`: Binance credentials (encrypted)
- `bybit_api_key`/`bybit_api_secret`: Bybit credentials (encrypted)
- `kraken_api_key`/`kraken_private_key`: Kraken Futures credentials (encrypted)
- `is_active`: Account enabled for operations
- `can_trade`: Account enabled for trading (can be disabled on errors)

**Relationships**:
- Belongs to `user`
- Has many `positions`
- Has many `orders`
- Has many `account_balances`

**Encryption**:
```php
// Stored encrypted
$account->api_key = encrypt($apiKey);

// Retrieved decrypted
$apiKey = decrypt($account->api_key);
```

### positions

Open and closed trading positions.

```sql
CREATE TABLE positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exchange_account_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(50) NOT NULL COMMENT 'BTCUSDT, ETHUSDT',
    side VARCHAR(10) NOT NULL COMMENT 'long, short',
    entry_price DECIMAL(20, 8) NOT NULL,
    current_price DECIMAL(20, 8) NOT NULL,
    quantity DECIMAL(20, 8) NOT NULL,
    leverage INT DEFAULT 1,
    unrealized_pnl DECIMAL(20, 8) NULL,
    realized_pnl DECIMAL(20, 8) NULL,
    status VARCHAR(20) NOT NULL COMMENT 'open, closed',
    opened_at TIMESTAMP NOT NULL,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (exchange_account_id) REFERENCES exchange_accounts(id),
    INDEX idx_exchange_account_id (exchange_account_id),
    INDEX idx_symbol (symbol),
    INDEX idx_status (status),
    INDEX idx_opened_at (opened_at)
);
```

**Key Fields**:
- `symbol`: Trading pair (e.g., BTCUSDT)
- `side`: 'long' (buy) or 'short' (sell)
- `entry_price`: Average entry price
- `current_price`: Last known price
- `quantity`: Position size
- `leverage`: Leverage multiplier (1 = no leverage)
- `unrealized_pnl`: Current profit/loss (open positions)
- `realized_pnl`: Final profit/loss (closed positions)
- `status`: 'open' or 'closed'

**Relationships**:
- Belongs to `exchange_account`
- Has many `orders`

**PnL Calculation**:
```php
// Long position
$unrealizedPnl = ($currentPrice - $entryPrice) * $quantity * $leverage;

// Short position
$unrealizedPnl = ($entryPrice - $currentPrice) * $quantity * $leverage;
```

### orders

Individual buy/sell orders.

```sql
CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id BIGINT UNSIGNED NULL COMMENT 'NULL if not part of position',
    exchange_account_id BIGINT UNSIGNED NOT NULL,
    exchange_order_id VARCHAR(255) NOT NULL COMMENT 'Exchange-provided ID',
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(10) NOT NULL COMMENT 'buy, sell',
    type VARCHAR(20) NOT NULL COMMENT 'market, limit, stop_loss',
    price DECIMAL(20, 8) NULL COMMENT 'NULL for market orders',
    quantity DECIMAL(20, 8) NOT NULL,
    filled_quantity DECIMAL(20, 8) DEFAULT 0,
    status VARCHAR(20) NOT NULL COMMENT 'pending, filled, cancelled, rejected',
    time_in_force VARCHAR(10) DEFAULT 'GTC' COMMENT 'GTC, IOC, FOK',
    placed_at TIMESTAMP NOT NULL,
    filled_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (position_id) REFERENCES positions(id),
    FOREIGN KEY (exchange_account_id) REFERENCES exchange_accounts(id),
    INDEX idx_position_id (position_id),
    INDEX idx_exchange_account_id (exchange_account_id),
    INDEX idx_exchange_order_id (exchange_order_id),
    INDEX idx_symbol (symbol),
    INDEX idx_status (status),
    INDEX idx_placed_at (placed_at)
);
```

**Key Fields**:
- `position_id`: Links to position (NULL if standalone order)
- `exchange_order_id`: Exchange's internal order ID
- `side`: 'buy' or 'sell'
- `type`: 'market', 'limit', 'stop_loss', 'take_profit'
- `price`: Limit price (NULL for market orders)
- `filled_quantity`: Partial fill support
- `status`: 'pending', 'filled', 'cancelled', 'rejected'
- `time_in_force`: 'GTC' (good till cancelled), 'IOC' (immediate or cancel), 'FOK' (fill or kill)

**Relationships**:
- Belongs to `position` (optional)
- Belongs to `exchange_account`

### account_balances

Periodic snapshots of account balances.

```sql
CREATE TABLE account_balances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exchange_account_id BIGINT UNSIGNED NOT NULL,
    asset VARCHAR(20) NOT NULL COMMENT 'USDT, BTC, ETH',
    free DECIMAL(20, 8) NOT NULL COMMENT 'Available balance',
    locked DECIMAL(20, 8) NOT NULL COMMENT 'Locked in orders',
    total DECIMAL(20, 8) NOT NULL COMMENT 'free + locked',
    snapshot_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,

    FOREIGN KEY (exchange_account_id) REFERENCES exchange_accounts(id),
    INDEX idx_exchange_account_id (exchange_account_id),
    INDEX idx_asset (asset),
    INDEX idx_snapshot_at (snapshot_at)
);
```

**Purpose**: Historical balance tracking for analysis and reporting

**Key Fields**:
- `asset`: Currency/token symbol (USDT, BTC, ETH, etc.)
- `free`: Available for trading
- `locked`: Reserved in open orders
- `total`: free + locked
- `snapshot_at`: When snapshot was taken

**Relationships**:
- Belongs to `exchange_account`

**Usage**:
```php
// Store snapshot every 15 minutes
AccountBalance::create([
    'exchange_account_id' => $account->id,
    'asset' => 'USDT',
    'free' => $balance['free'],
    'locked' => $balance['locked'],
    'total' => $balance['total'],
    'snapshot_at' => now(),
]);
```

### throttle_logs (DEPRECATED - REMOVED)

**Status**: ❌ Deprecated and removed from codebase

**Reason**: Throttling now uses `notification_logs` table for dual purpose (audit + throttle). The `throttle_logs` table was redundant and has been completely removed.

**Migration**: All throttling logic now uses `NotificationService` which logs to `notification_logs` and uses those records for throttle checking based on `throttle_rules.throttle_seconds`.

## Migration Patterns

### Creating Migrations

```bash
# NEVER use --path flag
php artisan make:migration create_positions_table

# This respects package configuration and creates in:
# packages/martingalian/core/database/migrations/
```

### Migration Template

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_account_id')->constrained();
            $table->string('symbol', 50);
            $table->enum('side', ['long', 'short']);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('current_price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->integer('leverage')->default(1);
            $table->decimal('unrealized_pnl', 20, 8)->nullable();
            $table->decimal('realized_pnl', 20, 8)->nullable();
            $table->enum('status', ['open', 'closed']);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('exchange_account_id');
            $table->index('symbol');
            $table->index('status');
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
```

### Modifying Columns

**IMPORTANT**: When modifying a column, you MUST include ALL attributes:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // BAD: Will lose NOT NULL constraint
        $table->string('email')->unique()->change();

        // GOOD: Preserves all attributes
        $table->string('email')->nullable(false)->unique()->change();
    });
}
```

### Foreign Keys

```php
// Shorthand (recommended)
$table->foreignId('user_id')->constrained();

// Explicit
$table->foreignId('user_id')->constrained('users', 'id');

// Custom action
$table->foreignId('user_id')
    ->constrained()
    ->onUpdate('cascade')
    ->onDelete('restrict'); // NEVER use 'cascade' for deletes
```

**IMPORTANT**: Never use `->onDelete('cascade')` - handle deletions explicitly in application code.

## Database Transactions

### Always Use Transactions

For related database operations:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $position = Position::create([...]);

    Order::create([
        'position_id' => $position->id,
        ...
    ]);

    AccountBalance::create([...]);
});
```

### Pessimistic Locking

For concurrent updates:

```php
DB::transaction(function () {
    $position = Position::where('id', $id)
        ->lockForUpdate() // Prevents race conditions
        ->first();

    $position->current_price = $newPrice;
    $position->unrealized_pnl = $calculatedPnl;
    $position->save();
});
```

## Eloquent Models

### Model Location
- Core models: `packages/martingalian/core/src/Models/`
- App-specific models: `app/Models/`

### Model Conventions

```php
namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'exchange_account_id',
        'symbol',
        'side',
        'entry_price',
        'current_price',
        'quantity',
        'leverage',
        'unrealized_pnl',
        'realized_pnl',
        'status',
        'opened_at',
        'closed_at',
    ];

    // Use casts() method (Laravel 12)
    protected function casts(): array
    {
        return [
            'entry_price' => 'decimal:8',
            'current_price' => 'decimal:8',
            'quantity' => 'decimal:8',
            'unrealized_pnl' => 'decimal:8',
            'realized_pnl' => 'decimal:8',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    // Relationships
    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Scopes
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    public function scopeForSymbol(Builder $query, string $symbol): void
    {
        $query->where('symbol', $symbol);
    }
}
```

### Factory Definitions

Located: `packages/martingalian/core/database/factories/`

```php
namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'exchange_account_id' => ExchangeAccount::factory(),
            'symbol' => 'BTCUSDT',
            'side' => 'long',
            'entry_price' => $this->faker->randomFloat(8, 30000, 70000),
            'current_price' => $this->faker->randomFloat(8, 30000, 70000),
            'quantity' => $this->faker->randomFloat(8, 0.001, 1),
            'leverage' => 1,
            'status' => 'open',
            'opened_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'closed_at' => now(),
            'realized_pnl' => $this->faker->randomFloat(8, -1000, 1000),
        ]);
    }

    public function withLeverage(int $leverage): static
    {
        return $this->state(fn (array $attributes) => [
            'leverage' => $leverage,
        ]);
    }
}
```

## Query Best Practices

### Prevent N+1 Queries

```php
// BAD: N+1 problem
$accounts = ExchangeAccount::all();
foreach ($accounts as $account) {
    echo $account->user->name; // Query per account
}

// GOOD: Eager loading
$accounts = ExchangeAccount::with('user')->get();
foreach ($accounts as $account) {
    echo $account->user->name; // No additional queries
}
```

### Use Query Scopes

```php
// BAD: Raw WHERE clauses
Position::where('status', 'open')
    ->where('symbol', 'BTCUSDT')
    ->get();

// GOOD: Scopes
Position::open()
    ->forSymbol('BTCUSDT')
    ->get();
```

### Chunk Large Datasets

```php
// BAD: Load all into memory
$positions = Position::all(); // Could be millions

// GOOD: Process in chunks
Position::chunk(100, function ($positions) {
    foreach ($positions as $position) {
        // Process position
    }
});
```

### Use DB::transaction()

```php
// BAD: No transaction
$position = Position::create([...]);
Order::create(['position_id' => $position->id, ...]);

// GOOD: Wrapped in transaction
DB::transaction(function () {
    $position = Position::create([...]);
    Order::create(['position_id' => $position->id, ...]);
});
```

### Always Use Model::create()

```php
// BAD: Skips observers
DB::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
User::insert(['name' => 'John', 'email' => 'john@example.com']);

// GOOD: Triggers observers
User::create(['name' => 'John', 'email' => 'john@example.com']);
```

## Database Testing

### RefreshDatabase Trait

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates position', function () {
    $position = Position::factory()->create();

    expect(Position::count())->toBe(1);
    // Database automatically reset after test
});
```

### Database Transactions in Tests

```php
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('updates position', function () {
    $position = Position::factory()->create();
    $position->update(['status' => 'closed']);

    expect($position->status)->toBe('closed');
    // Changes rolled back after test
});
```

### Test Database Configuration

```php
// config/database.php
'connections' => [
    'testing' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => 'martingalian_test',
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', 'password'),
    ],
],
```

## Common Queries

### Get open positions with account info
```php
Position::with('exchangeAccount.user')
    ->open()
    ->get();
```

### Get recent orders for account
```php
Order::where('exchange_account_id', $accountId)
    ->orderBy('placed_at', 'desc')
    ->limit(10)
    ->get();
```

### Get balance history for account
```php
AccountBalance::where('exchange_account_id', $accountId)
    ->where('asset', 'USDT')
    ->orderBy('snapshot_at', 'desc')
    ->get();
```

### Get admin users
```php
User::where('is_admin', true)
    ->where('is_active', true)
    ->get();
```

### Get users with email notifications enabled
```php
User::whereJsonContains('notification_channels', 'mail')
    ->where('is_active', true)
    ->get();
```

## Performance Considerations

### Indexes
- Always index foreign keys
- Index columns used in WHERE clauses
- Index columns used in ORDER BY
- Composite indexes for multi-column queries

### Decimals
- Use DECIMAL(20, 8) for financial data
- Never use FLOAT for money/prices
- Precision: 20 digits total, 8 after decimal
- **CRITICAL**: Database stores ALL financial values with 8 decimal places

**Testing Implications**:
```php
// ❌ WRONG: Will fail assertion - precision mismatch
$position->update(['closing_price' => '51000']);
expect($position->closing_price)->toBe('51000');  // Fails!

// ✓ CORRECT: Match database precision
$position->update(['closing_price' => '51000.00000000']);
expect($position->closing_price)->toBe('51000.00000000');  // Passes!

// Applies to ALL financial fields:
// - Prices: opening_price, closing_price, entry_price, current_price, mark_price
// - Quantities: quantity, filled_quantity, executed_qty
// - Order fields: price, stop_price, average_fill_price
// - Position fields: liquidation_price
// - All DECIMAL(20, 8) columns
```

### Timestamps
- Use TIMESTAMP for specific points in time
- TIMESTAMP supports timezones
- Indexed for range queries

### JSON Columns
- Use for flexible, non-relational data
- Index with virtual columns if needed
- Query with `whereJsonContains()`

## Backup & Maintenance

### Backup Strategy
- Daily full backups
- Point-in-time recovery enabled
- Retain backups for 30 days
- Test restore procedures monthly

### Maintenance
- Optimize tables monthly
- Analyze tables for query optimization
- Archive old data (closed positions, old balances)
- Monitor slow queries

### Cleanup Tasks
- Archive old notification_logs (>30 days)
- Archive closed positions (>90 days)
- Archive balance snapshots (>365 days)
