# Model Cache System

## Overview

Generic model-scoped caching mechanism for idempotent operation handling across job retries. Prevents duplicate API calls, duplicate order creation, and data inconsistencies when jobs are retried due to rate limits or failures.

---

## Purpose

**Problem**: When a job is rate limited after performing an API operation, the retry would create duplicate operations.

**Solution**: Cache the result of operations scoped to the model (typically Step), so retries return cached results instead of re-executing.

---

## How It Works

1. Job calls `$this->step->cache()->getOr('operation_name', callback)`
2. First execution: callback runs, result cached for 5 minutes
3. On retry: cache hit, callback NOT executed, cached result returned

---

## Cache Key Format

```
{table_name}:{model_id}:cache:{canonical}
```

Examples:
- `steps:123:cache:place_order`
- `positions:456:cache:mark_price`
- `accounts:789:cache:balance`

---

## Default Configuration

| Setting | Value | Purpose |
|---------|-------|---------|
| Default TTL | 300 seconds | Covers job retry window |
| Cache Driver | Redis | Distributed across workers |
| Auto-cleanup | Yes | Redis expires keys |

---

## Use Cases

### 1. Prevent Duplicate Orders

Cache the entire order creation + API call:
- First execution: Order created in DB, API call made, result cached
- Retry: Returns cached result, no duplicate order

**Jobs Using This**:
- PlaceMarketOrderJob
- PlaceLimitOrderJob
- PlaceProfitOrderJob
- PlaceStopLossOrderJob

### 2. Prevent Duplicate API Syncs

When syncing multiple items, cache each individually:
- Partial completion is saved
- Retry continues from where it left off

### 3. Cache Expensive Calculations

Cache complex calculations before API calls:
- WAP calculations
- Position sizing
- Risk calculations

### 4. Multi-Step Operations

Cache each step of a multi-API operation:
- Step 1: Query positions (cached)
- Step 2: Close each position (cached individually)
- Retry restarts from last uncached step

---

## Scoping

### Why Step-Scoped?

- **Step = Unit of Work**: Each step represents one logical operation
- **Retries use same Step ID**: Cache shared across retries
- **Workers don't overlap**: Queue ensures one worker per step

### Flow Example

1. Worker 1 processes Step #1234
2. Worker 1 caches result, then crashes
3. Worker 2 picks up Step #1234 (retry)
4. Worker 2 reads cache → hit → no duplicate operation
5. Step #1234 completes successfully

---

## Best Practices

### DO

1. **Cache the entire operation** - Include DB create and API call together
2. **Use descriptive canonicals** - `place_market_order` not `api_call`
3. **Cache at job level** - Jobs have Step context
4. **Keep TTL reasonable** - 5-10 minutes covers retry window

### DON'T

1. **Don't cache inside model methods** - No Step context available
2. **Don't use too short TTL** - Cache may expire before retry
3. **Don't use too long TTL** - Causes cache bloat

---

## TTL Guidelines

| Duration | Use Case |
|----------|----------|
| 300s (default) | Standard operations |
| 600s | Multiple API calls |
| 60s | Frequently changing data |

---

## Memory Considerations

- Average entry: ~1-5KB (serialized data)
- 1000 concurrent steps: ~1-5MB
- Auto-expires after TTL
- Redis handles cleanup

---

## Related Systems

- **StepDispatcher**: Manages step execution and retries
- **ExceptionHandling**: Triggers retries that benefit from cache
- **Throttling**: Rate limits that cause retries
