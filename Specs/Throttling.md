# API Throttling System

## Overview
Comprehensive rate limiting and IP ban coordination system using Redis cache to prevent API throttling and IP bans across multiple workers. Supports both IP-based (Binance WEIGHT limits) and per-account (Binance ORDER limits) rate limiting.

## Architecture

### Design Pattern: Delegation Chain

```
BaseApiableJob
    ↓ shouldStartOrThrottle()
BaseExceptionHandler
    ↓ isSafeToMakeRequest()
Throttler (BinanceThrottler, BybitThrottler, etc.)
    ↓ Checks cache
Redis Cache
```

### Core Components

1. **Throttlers** (`packages/martingalian/core/src/Support/Throttlers/`)
   - `BinanceThrottler` - Complex IP + per-account throttling
   - `BybitThrottler` - IP-based throttling
   - `TaapiThrottler`, `CoinMarketCapThrottler`, `AlternativeMeThrottler` - No-op implementations

2. **Exception Handlers** (delegate to throttlers)
   - `recordResponseHeaders()` - Delegates to Throttler
   - `isCurrentlyBanned()` - Delegates to Throttler
   - `recordIpBan()` - Delegates to Throttler
   - `isSafeToMakeRequest()` - Delegates to Throttler

3. **BaseApiClient** (calls exception handlers)
   - Automatically calls `recordResponseHeaders()` after successful requests

## BinanceThrottler

### Responsibilities

1. **IP Ban Tracking** - Coordinate IP bans across all workers
2. **IP-based WEIGHT Limits** - Track request weight consumption per IP
3. **Per-account ORDER Limits** - Track order creation per account
4. **Rate Limit Proximity** - Detect when approaching limits (>80%)

### Cache Keys

```php
// IP ban state
'binance:ip_ban:{hostname}' => [
    'banned_until' => '2025-01-08 12:34:56',
    'retry_after_seconds' => 60,
]

// IP-based weight tracking (per minute window)
'binance:weight:{hostname}:1m' => [
    'used' => 850,
    'limit' => 1200,
    'interval_ms' => 60000,
    'reset_at' => '2025-01-08 12:35:00',
]

// Per-account order tracking (per 10-second window)
'binance:orders:{hostname}:{account_id}:10s' => [
    'used' => 8,
    'limit' => 50,
    'interval_ms' => 10000,
    'reset_at' => '2025-01-08 12:34:10',
]
```

### Response Header Parsing

Binance returns rate limit headers on every response:

```
X-MBX-USED-WEIGHT-1M: 850
X-MBX-ORDER-COUNT-10S: 8
```

**Parsing Logic** (`parseIntervalHeaders()`):
1. Extract header name components: `WEIGHT`, `1M`, or `ORDER-COUNT`, `10S`
2. Determine interval in milliseconds (1M = 60000ms, 10S = 10000ms)
3. Calculate reset time (now + interval)
4. Store in cache with appropriate key

### Methods

#### recordResponseHeaders(ResponseInterface $response, ?int $accountId = null)

```php
public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
{
    $headers = $response->getHeaders();
    $hostname = Martingalian::hostname();

    foreach ($headers as $name => $values) {
        if (!str_starts_with(strtoupper($name), 'X-MBX-')) {
            continue;
        }

        // Parse interval headers (WEIGHT-1M, ORDER-COUNT-10S)
        $parsed = self::parseIntervalHeaders($name, $values[0]);
        if (!$parsed) {
            continue;
        }

        // Store in cache
        $cacheKey = self::buildCacheKey($parsed, $hostname, $accountId);
        cache()->put($cacheKey, $parsed, $parsed['interval_ms'] / 1000);
    }
}
```

#### isCurrentlyBanned(): bool

```php
public static function isCurrentlyBanned(): bool
{
    $hostname = Martingalian::hostname();
    $cacheKey = "binance:ip_ban:{$hostname}";

    return cache()->has($cacheKey);
}
```

#### recordIpBan(int $retryAfterSeconds): void

```php
public static function recordIpBan(int $retryAfterSeconds): void
{
    $hostname = Martingalian::hostname();
    $cacheKey = "binance:ip_ban:{$hostname}";

    cache()->put($cacheKey, [
        'banned_until' => now()->addSeconds($retryAfterSeconds),
        'retry_after_seconds' => $retryAfterSeconds,
    ], $retryAfterSeconds);
}
```

#### isSafeToMakeRequest(): int

Returns delay in seconds (0 = safe to proceed, >0 = wait time)

```php
public static function isSafeToMakeRequest(): int
{
    // 1. Check IP ban
    if (self::isCurrentlyBanned()) {
        $banData = cache()->get("binance:ip_ban:" . Martingalian::hostname());
        $remainingSeconds = now()->diffInSeconds($banData['banned_until'], false);
        return max(0, $remainingSeconds);
    }

    // 2. Check rate limit proximity (>80% threshold)
    $hostname = Martingalian::hostname();
    $weightKey = "binance:weight:{$hostname}:1m";
    $weightData = cache()->get($weightKey);

    if ($weightData && ($weightData['used'] / $weightData['limit']) > 0.80) {
        // Approaching limit, wait until window resets
        $remainingSeconds = now()->diffInSeconds($weightData['reset_at'], false);
        return max(0, $remainingSeconds);
    }

    return 0; // Safe to proceed
}
```

### Per-Account ORDER Limits

Binance enforces ORDER limits per account, not per IP:
- Limit: 50 orders per 10 seconds per account
- Header: `X-MBX-ORDER-COUNT-10S: 8`

**Flow**:
1. Exception handler receives `$accountId` via `withAccount()` method
2. Exception handler passes `$accountId` to `recordResponseHeaders()`
3. Throttler stores ORDER count in account-specific cache key
4. Future requests check account-specific ORDER limit

**Example**:
```php
// In BaseApiableJob
$handler = BaseExceptionHandler::make('binance')
    ->withAccount($this->account);

// After successful order creation
$handler->recordResponseHeaders($response);
// Stores: binance:orders:{hostname}:{account->id}:10s
```

## BybitThrottler

### Responsibilities

1. **IP Ban Tracking** - Coordinate IP bans across workers
2. **Rate Limit Proximity** - Detect when approaching limits

### Cache Keys

```php
'bybit:ip_ban:{hostname}' => [
    'banned_until' => '2025-01-08 12:34:56',
    'retry_after_seconds' => 60,
]
```

### Methods

Same interface as BinanceThrottler:
- `recordResponseHeaders(ResponseInterface $response, ?int $accountId = null)`
- `isCurrentlyBanned(): bool`
- `recordIpBan(int $retryAfterSeconds): void`
- `isSafeToMakeRequest(): int`

**Difference**: Simpler header parsing (Bybit has different rate limit structure)

## Simple Throttlers (TAAPI, CoinMarketCap, Alternative.me)

### Pattern: No-op Implementation

These APIs don't require IP ban coordination or complex rate limiting:

```php
class TaapiThrottler
{
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        // No-op: TAAPI doesn't provide rate limit headers we need to track
    }

    public static function isCurrentlyBanned(): bool
    {
        return false; // TAAPI doesn't ban IPs
    }

    public static function recordIpBan(int $retryAfterSeconds): void
    {
        // No-op: Not applicable for TAAPI
    }

    public static function isSafeToMakeRequest(): int
    {
        return 0; // Always safe (handled by plan-based quotas)
    }
}
```

## Integration Flow

### 1. Making an API Request

```php
// In BaseApiableJob->handle()
public function handle()
{
    // Create exception handler with account context
    $handler = BaseExceptionHandler::make($this->apiSystem)
        ->withAccount($this->account);

    // Pre-flight safety check
    $delaySeconds = $this->shouldStartOrThrottle($handler);
    if ($delaySeconds > 0) {
        // Not safe, release job
        $this->release($delaySeconds);
        return;
    }

    // Make API request
    $response = $this->apiClient->makeRequest();

    // Process response
    $this->process($response);
}

protected function shouldStartOrThrottle(BaseExceptionHandler $handler): int
{
    // Delegates to handler->isSafeToMakeRequest()
    // Which delegates to Throttler->isSafeToMakeRequest()
    return $handler->isSafeToMakeRequest() ? 0 : 60;
}
```

### 2. Recording Response Headers

```php
// In BaseApiClient->request()
public function request(string $method, string $endpoint, array $options = []): ResponseInterface
{
    try {
        $response = $this->httpClient->request($method, $endpoint, $options);

        // Automatically record response headers
        $this->recordResponseHeaders($response);

        return $response;
    } catch (RequestException $e) {
        throw $e;
    }
}

protected function recordResponseHeaders(ResponseInterface $response): void
{
    $accountId = $this->account?->id;
    BinanceThrottler::recordResponseHeaders($response, $accountId);
}
```

### 3. Handling IP Bans

```php
// In BaseApiableJob exception handling
catch (RequestException $e) {
    if ($handler->isIpBanned($e)) {
        $retryAfter = $handler->backoffSeconds($e);
        $handler->recordIpBan($retryAfter); // Delegates to Throttler

        NotificationService::critical("IP banned on {$this->apiSystem} for {$retryAfter}s");

        $this->release($retryAfter);
        return;
    }
}
```

## Rate Limit Proximity Detection

### 80% Threshold Rule

When rate limit usage exceeds 80%, jobs are delayed until the window resets:

```php
// Example: Binance WEIGHT limit
// Limit: 1200 per minute
// Current usage: 980 (81.6%)
// Action: Delay jobs until next minute

if (($used / $limit) > 0.80) {
    $delaySeconds = now()->diffInSeconds($resetAt);
    return $delaySeconds; // Return delay instead of proceeding
}
```

**Why 80%?**
- Safety margin to prevent hitting exact limit
- Accounts for in-flight requests
- Prevents cascading failures

## Cache Expiration

All cache entries use TTL based on rate limit intervals:

```php
// WEIGHT-1M: 60-second TTL
cache()->put($key, $data, 60);

// ORDER-COUNT-10S: 10-second TTL
cache()->put($key, $data, 10);

// IP ban: Variable TTL based on retry-after
cache()->put($key, $data, $retryAfterSeconds);
```

**Auto-cleanup**: Redis automatically removes expired keys

## Testing

### Unit Tests

```php
it('records IP ban in cache', function () {
    BinanceThrottler::recordIpBan(60);

    $hostname = Martingalian::hostname();
    $cacheKey = "binance:ip_ban:{$hostname}";

    expect(cache()->has($cacheKey))->toBeTrue();
    expect(BinanceThrottler::isCurrentlyBanned())->toBeTrue();
});

it('detects rate limit proximity', function () {
    // Mock cache to simulate 85% usage
    cache()->put("binance:weight:" . Martingalian::hostname() . ":1m", [
        'used' => 1020,
        'limit' => 1200,
        'reset_at' => now()->addSeconds(30),
    ], 60);

    $delay = BinanceThrottler::isSafeToMakeRequest();

    expect($delay)->toBeGreaterThan(0); // Should delay
    expect($delay)->toBeLessThanOrEqual(30); // Until window resets
});
```

### Integration Tests

```php
it('coordinates IP ban across multiple jobs', function () {
    // Job 1 gets IP banned
    $job1 = new SomeBinanceJob();
    $job1->handle(); // Triggers IP ban

    // Job 2 should detect ban and delay
    $job2 = new AnotherBinanceJob();
    $result = $job2->shouldStartOrThrottle($handler);

    expect($result)->toBeGreaterThan(0); // Should delay due to ban
});
```

## Configuration

### Cache Driver

Requires Redis for distributed cache across workers:

```php
// config/cache.php
'default' => env('CACHE_DRIVER', 'redis'),

'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
    ],
],
```

### Hostname

Each server must have unique hostname for cache key isolation:

```php
// Martingalian::hostname()
return gethostname(); // e.g., "worker-01", "worker-02"
```

## Future Enhancements

- **Dynamic thresholds** - Adjust 80% threshold based on traffic patterns
- **Predictive throttling** - Machine learning to predict rate limit exhaustion
- **Dashboard** - Real-time rate limit monitoring UI
- **Alerts** - Proactive notifications when approaching limits
- **Metrics** - Track throttle effectiveness, ban frequency, etc.
