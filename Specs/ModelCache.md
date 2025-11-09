# Model Cache System

## Overview
Generic model-scoped caching mechanism that provides idempotent operation handling across job retries. Prevents duplicate API calls, duplicate order creation, and data inconsistencies when jobs are retried due to rate limits or failures.

## Architecture

### Design Pattern
Cache operations are scoped to individual model instances, allowing any model (Step, Position, Order, Account, etc.) to cache expensive operations.

### Cache Key Format
```
{table_name}:{model_id}:cache:{canonical}

Examples:
- steps:123:cache:place_order
- positions:456:cache:mark_price
- accounts:789:cache:balance
- orders:999:cache:sync_status
```

### Expiration
- **Default TTL**: 300 seconds (5 minutes)
- **Custom TTL**: Configurable per operation
- **Auto-cleanup**: Redis expires keys automatically
- **Purpose**: Long enough for job retry window, short enough to prevent cache bloat

## Components

### 1. ModelCache Class
**Location**: `packages/martingalian/core/src/Support/ModelCache.php`

**Purpose**: Provides fluent caching interface for model instances

**Methods**:
- `getOr(string $canonical, Closure $callback): mixed` - Get cached value or execute callback
- `ttl(int $seconds): self` - Set custom TTL for subsequent operations
- `forget(string $canonical): void` - Remove cached value

**Example**:
```php
$cache = new ModelCache($step, 300);
$result = $cache->getOr('my_operation', fn() => expensiveOperation());
```

### 2. HasModelCache Trait
**Location**: `packages/martingalian/core/src/Concerns/HasModelCache.php`

**Purpose**: Adds caching capability to Eloquent models

**Method**:
- `cache(int $ttl = 300): ModelCache` - Returns ModelCache instance

**Added to**: `BaseModel` (automatically available on ALL models)

## Usage

### Basic Usage

```php
// In BaseApiableJob
public function computeApiable()
{
    return $this->step->cache()->getOr('place_market_order', function() {
        // This entire block only executes once
        $calc = Martingalian::calculateMarketOrderData(...);

        $order = $this->position->orders()->create([
            'type' => 'MARKET',
            'quantity' => $calc['marketQty'],
        ]);

        $order->apiPlace();

        return ['order' => format_model_attributes($order)];
    });
}
```

**On first execution:**
- Callback executes
- Order created in DB
- API call made to exchange
- Result cached for 5 minutes
- Returns result

**On retry (after rate limit):**
- Cache hit
- Callback NOT executed
- Order NOT duplicated in DB
- API call NOT made again
- Returns cached result

### Custom TTL

```php
// Cache for 10 minutes instead of 5
$result = $this->step->cache()->ttl(600)->getOr('expensive_operation', function() {
    return $this->performExpensiveOperation();
});

// Or via constructor
$result = $this->step->cache(600)->getOr('operation', fn() => ...);
```

### Multiple Cached Operations

```php
public function computeApiable()
{
    // Cache position query
    $positions = $this->step->cache()->getOr('query_positions', function() {
        return $this->account->apiQueryPositions();
    });

    // Cache each close order
    foreach ($positions as $index => $positionData) {
        $order = $this->step->cache()->getOr("close_order_{$index}", function() use ($positionData) {
            $order = Order::create([...]);
            return $order->apiPlace();
        });
    }
}
```

### Manual Cache Management

```php
// Check and clear cache for debugging
if ($this->step->cache()->has('operation')) {
    $this->step->cache()->forget('operation');
}

// Force re-execution
$this->step->cache()->forget('place_order');
$result = $this->step->cache()->getOr('place_order', fn() => ...);
```

### Using on Different Models

```php
// Step-scoped (most common in jobs)
$this->step->cache()->getOr('operation', fn() => ...);
// Key: steps:{step_id}:cache:operation

// Position-scoped
$this->position->cache()->getOr('mark_price', fn() => ...);
// Key: positions:{position_id}:cache:mark_price

// Account-scoped
$this->account->cache()->getOr('balance', fn() => ...);
// Key: accounts:{account_id}:cache:balance

// Order-scoped
$this->order->cache()->getOr('sync_status', fn() => ...);
// Key: orders:{order_id}:cache:sync_status
```

## Use Cases

### 1. Prevent Duplicate Orders (Critical)

**Problem**: Rate limit after `apiPlace()` causes job retry, which creates duplicate order

**Solution**:
```php
public function computeApiable()
{
    return $this->step->cache()->getOr('place_limit_order', function() {
        $this->order->apiPlace();
        return ['order' => format_model_attributes($this->order)];
    });
}
```

**Jobs Using This**:
- PlaceMarketOrderJob
- PlaceLimitOrderJob
- PlaceProfitOrderJob
- PlaceStopLossOrderJob
- PlaceOrderJob

### 2. Prevent Duplicate API Syncs

**Problem**: Syncing multiple orders, rate limited in middle, retry syncs already-synced orders

**Solution**:
```php
public function computeApiable()
{
    $orders = $this->position->orders;

    foreach ($orders as $index => $order) {
        $this->step->cache()->getOr("sync_order_{$index}", function() use ($order) {
            return $order->apiSync();
        });
    }
}
```

### 3. Cache Expensive Calculations

**Problem**: Complex calculation before API call, don't want to recalculate on retry

**Solution**:
```php
public function computeApiable()
{
    $wap = $this->step->cache()->getOr('calculate_wap', function() {
        // Expensive WAP calculation
        return Martingalian::calculateWAP($this->position);
    });

    $this->step->cache()->getOr('modify_profit_order', function() use ($wap) {
        $profitOrder->apiModify($quantity, $wap['newPrice']);
        return $profitOrder->apiSync();
    });
}
```

### 4. Multi-Step API Operations

**Problem**: Operation requires multiple API calls, rate limited between them

**Solution**:
```php
public function computeApiable()
{
    // Step 1: Query positions (cached)
    $apiResponse = $this->step->cache()->getOr('query_positions', function() {
        return $this->account->apiQueryPositions();
    });

    // Step 2: Process each position (cached individually)
    $matching = collect($apiResponse->result)->filter(...);

    foreach ($matching as $index => $positionData) {
        $this->step->cache()->getOr("close_position_{$index}", function() use ($positionData) {
            $order = Order::create([...]);
            return $order->apiPlace();
        });
    }
}
```

## Implementation Examples

### PlaceMarketOrderJob

**Before (without cache):**
```php
public function computeApiable()
{
    $calc = Martingalian::calculateMarketOrderData(...);

    $this->marketOrder = $this->position->orders()->create([...]);
    $this->marketOrder->apiPlace();

    return ['order' => format_model_attributes($this->marketOrder)];
}

// Problem: Rate limit causes duplicate order creation on retry
```

**After (with cache):**
```php
public function computeApiable()
{
    return $this->step->cache()->getOr('place_market_order', function() {
        $calc = Martingalian::calculateMarketOrderData(...);

        $this->marketOrder = $this->position->orders()->create([...]);
        $this->marketOrder->apiPlace();

        return ['order' => format_model_attributes($this->marketOrder)];
    });
}

// Solution: Cache ensures operation only executes once
```

### CalculateWAPAndModifyProfitOrderJob

```php
public function computeApiable()
{
    return $this->step->cache()->getOr('modify_profit_order', function() {
        // Calculate WAP
        $wap = Martingalian::calculateWAP($this->position);

        // Modify order
        $profitOrder = $this->position->profitOrder();
        $profitOrder->apiModify($wap['quantity'], $wap['newPrice']);

        // Sync to verify
        $profitOrder->apiSync();

        return ['order' => format_model_attributes($profitOrder)];
    });
}
```

## Cache Scoping

### Why Step-Scoped?

**Step = Unit of Work**
- Each step represents one logical operation
- Steps can be retried by any worker
- Step ID is unique per execution
- Cache shared across worker retries (no worker isolation needed)

**Example Flow**:
```
Time 0: Worker 1 processes Step #1234
Time 1: Worker 1 caches: steps:1234:cache:place_order = Order #123
Time 2: Worker 1 crashes
Time 3: Worker 2 picks up Step #1234 (retry)
Time 4: Worker 2 reads cache: steps:1234:cache:place_order - CACHE HIT!
Time 5: Worker 2 gets Order #123, no duplicate created
Time 6: Step #1234 completes successfully
```

**No Worker Isolation**: Two workers never process same step simultaneously (enforced by queue/step dispatcher)

### Why Not Worker-Scoped?

```php
// BAD: Worker isolation
$cacheKey = "step:{$stepId}:worker:{$hostname}:cache:{$canonical}";

// Problem:
Worker 1: steps:1234:worker:server-01:cache:place_order = Order #123
Worker 2: steps:1234:worker:server-02:cache:place_order = CACHE MISS → Duplicate Order #124!
```

## Best Practices

### 1. Cache the Entire Operation
```php
// GOOD: Cache entire block
$result = $this->step->cache()->getOr('operation', function() {
    $order = Order::create([...]);
    $order->apiPlace();
    return $order;
});

// BAD: Separate caching leads to complexity
$order = Order::create([...]);
$result = $this->step->cache()->getOr('place', fn() => $order->apiPlace());
```

### 2. Use Descriptive Canonicals
```php
// GOOD: Clear, specific
$this->step->cache()->getOr('place_market_order', fn() => ...);
$this->step->cache()->getOr('query_positions_for_close', fn() => ...);

// BAD: Vague, collision-prone
$this->step->cache()->getOr('api_call', fn() => ...);
$this->step->cache()->getOr('data', fn() => ...);
```

### 3. Cache at Job Level, Not Model Methods
```php
// GOOD: Cache in job (has $this->step context)
public function computeApiable()
{
    return $this->step->cache()->getOr('sync_orders', function() {
        return $this->position->syncOrders();
    });
}

// AVOID: Caching inside model methods (no step context)
// Model methods should be pure/stateless
```

### 4. Keep TTL Reasonable
```php
// GOOD: 5-10 minutes (covers retry window)
$this->step->cache()->ttl(300)->getOr(...);

// AVOID: Too short (cache expires before retry)
$this->step->cache()->ttl(10)->getOr(...);

// AVOID: Too long (cache bloat, stale data)
$this->step->cache()->ttl(86400)->getOr(...);
```

## Testing

### Unit Tests
**Location**: `tests/Unit/Support/ModelCacheTest.php`

**Coverage**:
- Basic caching functionality
- Cache hit/miss scenarios
- TTL expiration
- Cache scoping per model
- Forgetting cached values
- Complex data types
- Method chaining

**Example**:
```php
it('caches result on first call and returns cached on second', function () {
    $step = Step::factory()->create();
    $callCount = 0;

    $result1 = $step->cache()->getOr('test', function() use (&$callCount) {
        $callCount++;
        return 'result';
    });

    $result2 = $step->cache()->getOr('test', function() use (&$callCount) {
        $callCount++;
        return 'different';
    });

    expect($result1)->toBe('result');
    expect($result2)->toBe('result'); // Cached
    expect($callCount)->toBe(1); // Callback only executed once
});
```

### Integration Tests
Test with actual jobs that make API calls:

```php
it('prevents duplicate orders on retry', function () {
    $job = new PlaceMarketOrderJob($positionId);

    // Mock API to fail on first call
    Http::fake(['*' => Http::sequence()
        ->push([], 429) // Rate limit
        ->push(['orderId' => '123'], 200) // Success
    ]);

    // First attempt - rate limited
    expect(fn() => $job->handle())->toThrow(RateLimitException::class);

    // Second attempt - should use cached order, not create duplicate
    $job->handle();

    // Verify only ONE order created
    expect(Order::count())->toBe(1);
});
```

## Performance Considerations

### Cache Driver
- **Requires**: Redis for distributed cache across workers
- **Config**: `config/cache.php` → default driver must be Redis

### Memory Usage
- Average cache entry: ~1-5KB (serialized Order/array)
- 1000 concurrent steps: ~1-5MB total
- Auto-expiring (5 min default) prevents buildup

### Network Overhead
- Redis reads/writes: ~1ms per operation
- Much faster than API calls (100-500ms)
- Prevents duplicate expensive operations

## Future Enhancements

- **Pattern matching forget**: Clear all cache for a pattern
- **Cache warming**: Pre-populate cache for predictable operations
- **Cache statistics**: Track hit rate, size, effectiveness
- **Conditional caching**: Cache only if condition met
- **Cache middleware**: Automatic caching for specific job types

## Related Specs
- See `ExceptionHandling.md` for retry patterns
- See `ApiClients.md` for API call patterns
- See `StepDispatcher.md` for step execution flow
- See `Throttling.md` for rate limiting
