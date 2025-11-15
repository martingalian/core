# Model Cache - Codebase Examples

Real-world model-scoped caching implementation patterns from production code.

## Table of Contents

1. [Basic Usage in Jobs](#basic-usage-in-jobs)
2. [Preventing Duplicate Orders](#preventing-duplicate-orders)
3. [Cache Key Structure](#cache-key-structure)
4. [ModelCache Implementation](#modelcache-implementation)
5. [HasModelCache Trait](#hasmodelcache-trait)
6. [Testing Examples](#testing-examples)

---

## Basic Usage in Jobs

### Caching Expensive Operations

**From: CheckPriceSpikeAndCooldownJob (modified example)**

```php
try {
    // Process symbol for price spike detection
    $symbol = ExchangeSymbol::find($symbolId);

    // ... price calculation logic ...

} catch (Throwable $e) {
    $summary['errors']++;

    // Build message data from template
    $messageData = NotificationMessageBuilder::build(
        canonical: 'price_spike_check_symbol_error',
        context: [
            'message' => "[{$ex->id}] {$ex->parsed_trading_pair} - " .
                         ExceptionParser::with($e)->friendlyMessage(),
        ]
    );

    // 🔥 CACHE THE NOTIFICATION SEND
    // Prevents duplicate notifications on job retry
    $this->step->cache()->getOr('notify_error', function () use ($messageData) {
        Throttler::using(NotificationService::class)
            ->withCanonical('price_spike_check_symbol_error')
            ->execute(function () use ($messageData) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: $messageData['emailMessage'],
                    title: $messageData['title'],
                    canonical: 'price_spike_check_symbol_error',
                    deliveryGroup: 'exceptions',
                    severity: $messageData['severity'],
                    pushoverMessage: $messageData['pushoverMessage'],
                    actionUrl: $messageData['actionUrl'],
                    actionLabel: $messageData['actionLabel']
                );
            });

        return true;  // Cache the fact that we sent it
    });
}
```

**Why Cache?**
- Job retries (rate limits, etc.) won't resend notification
- Cache expires in 5 minutes (default TTL)
- Safe idempotent behavior

---

## Preventing Duplicate Orders

### Problem: Rate Limit After Order Creation

**Without Cache (WRONG):**

```php
public function computeApiable()
{
    // Create order in database
    $this->marketOrder = $this->position->orders()->create([
        'type' => 'MARKET',
        'quantity' => $calc['marketQty'],
        'price' => null,
    ]);

    // Place order on exchange
    $this->marketOrder->apiPlace();

    // ❌ PROBLEM: If rate limited HERE, job retries and creates DUPLICATE ORDER!

    return ['order' => format_model_attributes($this->marketOrder)];
}
```

**With Cache (CORRECT):**

```php
public function computeApiable()
{
    // 🔥 CACHE THE ENTIRE OPERATION
    return $this->step->cache()->getOr('place_market_order', function () {
        // This entire block only executes ONCE (even on retry)

        // Calculate order data
        $calc = Martingalian::calculateMarketOrderData(
            $this->position,
            $this->step->action_data
        );

        // Create order in database
        $this->marketOrder = $this->position->orders()->create([
            'type' => 'MARKET',
            'quantity' => $calc['marketQty'],
            'price' => null,
        ]);

        // Place order on exchange
        $this->marketOrder->apiPlace();

        // Return result (gets cached)
        return ['order' => format_model_attributes($this->marketOrder)];
    });
}
```

**What Happens on Retry:**
1. First execution: Callback runs, order created, result cached
2. Rate limited during `apiPlace()` → job released
3. Retry: Cache hit! Callback NOT executed, cached result returned
4. No duplicate order created ✅

---

## Cache Key Structure

### Key Format

```
{table_name}:{model_id}:cache:{canonical}
```

### Examples

```php
// Step-scoped cache
$this->step->cache()->getOr('place_order', fn() => ...);
// Key: steps:123:cache:place_order

// Position-scoped cache
$this->position->cache()->getOr('mark_price', fn() => ...);
// Key: positions:456:cache:mark_price

// Account-scoped cache
$this->account->cache()->getOr('balance', fn() => ...);
// Key: accounts:789:cache:balance

// Order-scoped cache
$this->order->cache()->getOr('sync_status', fn() => ...);
// Key: orders:999:cache:sync_status
```

### Why This Format?

**Model-scoped:**
- Each model instance has its own cache namespace
- `Step #123` cache is separate from `Step #124`
- No cross-contamination

**Canonical as suffix:**
- Multiple cached operations per model
- Descriptive names: `place_order`, `sync_positions`, `calculate_wap`

**Table name prefix:**
- Prevents collisions across different model types
- `steps:1:cache:test` ≠ `orders:1:cache:test`

---

## ModelCache Implementation

**Location**: `packages/martingalian/core/src/Support/ModelCache.php`

### Complete Implementation

```php
final class ModelCache
{
    private Model $model;
    private int $ttl;

    public function __construct(Model $model, int $ttl = 300)
    {
        $this->model = $model;
        $this->ttl = $ttl;
    }

    /**
     * Get cached value or execute callback and cache result.
     *
     * @param  string  $canonical  Cache key suffix (e.g., 'place_order')
     * @param  Closure  $callback  Function to execute if cache miss
     * @return mixed Cached value or callback result
     */
    public function getOr(string $canonical, Closure $callback): mixed
    {
        $cacheKey = $this->buildKey($canonical);

        return cache()->remember($cacheKey, $this->ttl, $callback);
    }

    /**
     * Set custom TTL for next operation (method chaining).
     *
     * @param  int  $seconds  TTL in seconds
     * @return self For method chaining
     */
    public function ttl(int $seconds): self
    {
        $this->ttl = $seconds;
        return $this;
    }

    /**
     * Remove cached value.
     *
     * @param  string  $canonical  Cache key suffix
     */
    public function forget(string $canonical): void
    {
        $cacheKey = $this->buildKey($canonical);
        cache()->forget($cacheKey);
    }

    /**
     * Build full cache key.
     *
     * @param  string  $canonical  Cache key suffix
     * @return string Full cache key
     */
    private function buildKey(string $canonical): string
    {
        return "{$this->model->getTable()}:{$this->model->id}:cache:{$canonical}";
    }
}
```

**Methods:**
- `getOr()` - Get or execute and cache
- `ttl()` - Set custom TTL (chainable)
- `forget()` - Clear cache entry
- `buildKey()` - Generate cache key

---

## HasModelCache Trait

**Location**: `packages/martingalian/core/src/Concerns/HasModelCache.php`

### Implementation

```php
trait HasModelCache
{
    /**
     * Get model cache instance for fluent operations.
     *
     * @param  int  $ttl  Default TTL in seconds (default: 5 minutes)
     */
    public function cache(int $ttl = 300): ModelCache
    {
        return new ModelCache($this, $ttl);
    }
}
```

### Usage in BaseModel

```php
abstract class BaseModel extends Model
{
    use HasModelCache;  // ✅ ALL models get caching

    // ... other code ...
}
```

**Result:**
- Every Eloquent model has `->cache()` method
- Step, Position, Order, Account, etc.
- Zero configuration needed

---

## Testing Examples

**Location**: `tests/Unit/Support/ModelCacheTest.php`

### Basic Caching Test

```php
it('caches result on first call and returns cached on second', function () {
    $step = Step::factory()->create();
    $callCount = 0;

    // First call - executes callback
    $result1 = $step->cache()->getOr('test_operation', function () use (&$callCount) {
        $callCount++;
        return 'expensive_result';
    });

    // Second call - returns cached result
    $result2 = $step->cache()->getOr('test_operation', function () use (&$callCount) {
        $callCount++;
        return 'different_result';
    });

    expect($result1)->toBe('expensive_result');
    expect($result2)->toBe('expensive_result');  // ✅ Cached
    expect($callCount)->toBe(1);  // ✅ Callback only executed once
});
```

### Cache Key Format Test

```php
it('uses correct cache key format', function () {
    $step = Step::factory()->create();

    $step->cache()->getOr('my_operation', fn() => 'test_value');

    $expectedKey = "steps:{$step->id}:cache:my_operation";

    expect(Cache::has($expectedKey))->toBeTrue();
    expect(Cache::get($expectedKey))->toBe('test_value');
});
```

### Model Scoping Test

```php
it('scopes cache to specific model instance', function () {
    $step1 = Step::factory()->create();
    $step2 = Step::factory()->create();

    $step1->cache()->getOr('operation', fn() => 'step1_result');
    $step2->cache()->getOr('operation', fn() => 'step2_result');

    // Each step has its own cached value
    expect($step1->cache()->getOr('operation', fn() => 'unused'))->toBe('step1_result');
    expect($step2->cache()->getOr('operation', fn() => 'unused'))->toBe('step2_result');
});
```

### Custom TTL Test

```php
it('allows custom TTL via fluent method', function () {
    $step = Step::factory()->create();

    $step->cache()->ttl(120)->getOr('custom_ttl', fn() => 'test_value');

    $expectedKey = "steps:{$step->id}:cache:custom_ttl";

    // Cache should exist
    expect(Cache::has($expectedKey))->toBeTrue();

    // Advance time by 119 seconds (within TTL)
    Carbon::setTestNow(now()->addSeconds(119));
    expect(Cache::has($expectedKey))->toBeTrue();

    // Advance to 121 seconds (past TTL)
    Carbon::setTestNow(now()->addSeconds(2));
    expect(Cache::has($expectedKey))->toBeFalse();

    Carbon::setTestNow();
});
```

### Forget Test

```php
it('executes callback again after forget', function () {
    $step = Step::factory()->create();
    $callCount = 0;

    $step->cache()->getOr('operation', function () use (&$callCount) {
        $callCount++;
        return "call_{$callCount}";
    });

    expect($callCount)->toBe(1);

    // Forget cache
    $step->cache()->forget('operation');

    // Should execute callback again
    $result = $step->cache()->getOr('operation', function () use (&$callCount) {
        $callCount++;
        return "call_{$callCount}";
    });

    expect($result)->toBe('call_2');
    expect($callCount)->toBe(2);
});
```

---

## Advanced Usage

### Multiple Cached Operations

```php
public function computeApiable()
{
    // Cache position query
    $positions = $this->step->cache()->getOr('query_positions', function () {
        return $this->account->apiQueryPositions();
    });

    // Cache each close order individually
    foreach ($positions as $index => $positionData) {
        $this->step->cache()->getOr("close_order_{$index}", function () use ($positionData) {
            $order = Order::create([...]);
            $order->apiPlace();
            return $order;
        });
    }

    return ['closed' => count($positions)];
}
```

**Why Per-Index Keys?**
- Each order cached separately
- Partial completion on retry (don't re-close already closed positions)
- Idempotent across retries

### Custom TTL for Long Operations

```php
public function computeApiable()
{
    // Cache for 10 minutes (longer than default 5)
    $result = $this->step->cache()->ttl(600)->getOr('expensive_calculation', function () {
        return $this->performVeryExpensiveCalculation();
    });

    return $result;
}
```

### Caching Complex Data

```php
public function computeApiable()
{
    $data = $this->step->cache()->getOr('complex_data', function () {
        return [
            'orders' => $this->position->orders->map(fn($o) => format_model_attributes($o)),
            'balances' => $this->account->apiQueryBalance(),
            'calculated_at' => now(),
        ];
    });

    return $data;
}
```

**Cache Supports:**
- Arrays, objects, primitives
- Serialization handled by Laravel Cache
- Complex nested structures

---

## Best Practices

### ✅ DO: Cache Entire Operation

```php
// GOOD
$result = $this->step->cache()->getOr('place_order', function () {
    $order = Order::create([...]);
    $order->apiPlace();
    return $order;
});
```

### ❌ DON'T: Partial Caching

```php
// BAD - Creates complexity
$order = Order::create([...]);
$result = $this->step->cache()->getOr('api_call', fn() => $order->apiPlace());
```

### ✅ DO: Use Descriptive Canonicals

```php
// GOOD
$this->step->cache()->getOr('place_market_order', fn() => ...);
$this->step->cache()->getOr('calculate_wap_and_modify', fn() => ...);
```

### ❌ DON'T: Vague Names

```php
// BAD
$this->step->cache()->getOr('api', fn() => ...);
$this->step->cache()->getOr('data', fn() => ...);
```

### ✅ DO: Cache at Job Level

```php
// GOOD - Cache in computeApiable()
public function computeApiable()
{
    return $this->step->cache()->getOr('sync_orders', function () {
        return $this->position->syncOrders();
    });
}
```

### ❌ DON'T: Cache in Model Methods

```php
// AVOID - Model methods should be stateless
class Position extends BaseModel
{
    public function syncOrders()
    {
        // ❌ No $step context here
        return $this->cache()->getOr('sync', fn() => ...);
    }
}
```

---

## Debugging Cache

### Check Cache Manually

```bash
# Redis CLI
redis-cli GET "steps:123:cache:place_order"

# Artisan tinker
>>> Cache::get('steps:123:cache:place_order')
```

### Clear Cache for Debugging

```php
// In job/tinker
$step = Step::find(123);
$step->cache()->forget('place_order');

// Force re-execution
$result = $step->cache()->getOr('place_order', fn() => ...);
```

### Check Cache Existence

```php
if (Cache::has("steps:{$step->id}:cache:operation")) {
    // Cache exists
    $value = Cache::get("steps:{$step->id}:cache:operation");
}
```

---

## Summary

**Standard Pattern:**
1. Wrap operation in `$this->step->cache()->getOr('canonical', callable)`
2. First execution: callback runs, result cached
3. Retry: cache hit, callback skipped, cached result returned
4. Cache expires in 5 minutes (or custom TTL)

**Use Cases:**
- Prevent duplicate orders on retry
- Cache expensive calculations
- Idempotent API operations
- Multi-step operations with partial completion

**Key Files:**
- `ModelCache.php` - Cache implementation
- `HasModelCache.php` - Trait for models
- `BaseModel.php` - Adds trait to all models
- `ModelCacheTest.php` - Comprehensive tests

**Cache Keys:**
- Format: `{table}:{id}:cache:{canonical}`
- Scoped to model instance
- Descriptive canonical names
- Redis-backed for distributed workers

**Never:**
- Cache across different model instances
- Use short TTL (<60 seconds)
- Cache in model methods (use jobs)
- Forget to use descriptive canonicals
