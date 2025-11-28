# API Throttling System

## Overview
Comprehensive rate limiting and IP coordination system using Redis cache to prevent API throttling and IP bans across multiple workers. Supports both IP-based (REQUEST_WEIGHT limits) and per-account (ORDER limits) rate limiting with real-time response header tracking.

## Critical Performance Insights

### Throughput Results (Stress Testing - November 2025)

**Bybit Throttler:**
- 166 concurrent jobs completed in 3 seconds = **55 req/sec**
- 1,660 concurrent jobs (10×) completed in 18 seconds = **92 req/sec**
- 0 retries achieved
- Limit: 550 req/5s (92% of 600 hard limit)
- Simple count-based throttling

**Binance Throttler:**
- 355 concurrent jobs completed in 60 seconds = **5.9 req/sec sustained**
- Peak weight: 1,775 out of 2,040 limit (87% utilization)
- 87% efficiency vs theoretical maximum (6.8 req/sec)
- Weight-based throttling with response header tracking
- Limit: 2,040 weight/min (85% of 2,400 hard limit)

### Key Optimization: Removed min_delay Bottleneck

**Previous Issue:**
- Both Binance and Bybit had `min_delay_ms: 200` configuration
- Used second-precision timestamps (`now()->timestamp`)
- Caused false throttling: jobs in same second calculated 0ms elapsed
- Serialized execution at max 5 req/sec (200ms intervals)

**Solution Applied:**
- Removed `min_delay_ms` entirely from both throttlers
- Changed Bybit to use `microtime(true)` for millisecond precision
- Increased Binance fallback limit to 10,000 (allows weight-based throttling to take precedence)

**Impact:**
- Bybit: 1 req/sec → **55-92 req/sec** (55× - 92× improvement)
- Binance: 2 req/sec → **5.9 req/sec** (3× improvement, correct weight-based limit)

## Architecture

### Design Pattern: Delegation Chain

```
BaseApiableJob
    ↓ shouldStartOrThrottle()
BaseExceptionHandler
    ↓ isSafeToMakeRequest()
Throttler (BinanceThrottler, BybitThrottler, etc.)
    ↓ canDispatch() / isSafeToDispatch()
    ↓ Checks cache
Redis Cache (coordinated across all workers on same IP)
```

### Core Components

1. **Base Throttler** (`packages/martingalian/core/src/Abstracts/BaseApiThrottler.php`)
   - `canDispatch()` - Fixed window rate limiting (fallback)
   - `recordDispatch()` - Track request in current window
   - `checkMinimumDelay()` - DEPRECATED (removed in optimization)
   - `getCurrentWindowKey()` - Generate time-based window keys

2. **Throttlers** (`packages/martingalian/core/src/Support/Throttlers/`)
   - `BinanceThrottler` - Complex IP + per-account throttling with weight tracking
   - `BybitThrottler` - IP-based throttling with header tracking
   - `TaapiThrottler`, `CoinMarketCapThrottler`, `AlternativeMeThrottler` - Simple window-based

3. **Exception Handlers** (delegate to throttlers)
   - `recordResponseHeaders()` - Delegates to Throttler
   - `isCurrentlyBanned()` - Delegates to Throttler
   - `recordIpBan()` - Delegates to Throttler
   - `isSafeToMakeRequest()` - Delegates to Throttler

4. **BaseApiClient** (calls exception handlers)
   - Automatically calls `recordResponseHeaders()` after successful requests

## BinanceThrottler

### Unique Architecture: Dual Rate Limit System

Binance has TWO independent rate limit systems:

1. **REQUEST_WEIGHT (IP-based)** - Shared across all workers on same server IP
2. **ORDERS (UID-based)** - Per trading account, tracked separately

### Responsibilities

1. **IP Ban Tracking** - Coordinate IP bans (418 responses) across all workers
2. **IP-based REQUEST_WEIGHT Limits** - Track request weight consumption per IP across multiple intervals (1m, 10s)
3. **Per-account ORDER Limits** - Track order creation per account (10s, 1m)
4. **Rate Limit Proximity** - Detect when approaching limits (>85% safety threshold)
5. **Response Header Parsing** - Extract and store real-time rate limit data

### Configuration

```php
// config/martingalian.php
'throttlers' => [
    'binance' => [
        // Safety threshold: stop at this percentage of limit (0.0-1.0)
        // 0.85 = stop at 85% to leave 15% buffer
        'safety_threshold' => (float) env('BINANCE_THROTTLER_SAFETY_THRESHOLD', 0.85),

        // Fallback config (allows weight-based limits to take precedence)
        // Set very high so base throttler doesn't interfere
        'requests_per_window' => 10000,
        'window_seconds' => 60,

        // Optional minimum delay between requests (in milliseconds)
        // Default: 0 (disabled). Set to enforce minimum spacing between requests
        'min_delay_ms' => (int) env('BINANCE_THROTTLER_MIN_DELAY_MS', 0),

        // Rate limit definitions from response headers
        'rate_limits' => [
            ['type' => 'REQUEST_WEIGHT', 'interval' => '1m', 'limit' => 2040],  // 85% of 2400
            ['type' => 'REQUEST_WEIGHT', 'interval' => '10s', 'limit' => 255],  // 85% of 300
            ['type' => 'ORDERS', 'interval' => '1m', 'limit' => 1020],          // 85% of 1200
            ['type' => 'ORDERS', 'interval' => '10s', 'limit' => 255],          // 85% of 300
        ],

        'advanced' => [
            'track_weight' => true,                    // Track endpoint weights
            'track_orders_per_account' => false,       // Track per-account ORDER limits (future)
            'auto_fetch_limits' => false,              // Auto-update limits from exchangeInfo (future)
        ],
    ],
],
```

### Cache Keys

```php
// IP ban state
'binance:{ip}:banned_until' => timestamp

// IP-based weight tracking (rolling window from response headers)
'binance:{ip}:weight:1m' => 1775      // Current weight used in last 1 minute
'binance:{ip}:weight:10s' => 85       // Current weight used in last 10 seconds

// Per-account order tracking (rolling window from response headers)
'binance:{ip}:uid:{account_id}:orders:1m' => 15
'binance:{ip}:uid:{account_id}:orders:10s' => 3
```

### Binance API Response Headers

Every Binance response includes rate limit headers:

```
X-MBX-USED-WEIGHT-1M: 1775
X-MBX-USED-WEIGHT-10S: 85
X-MBX-ORDER-COUNT-1M: 15
X-MBX-ORDER-COUNT-10S: 3
```

**Critical:** These values are rolling windows maintained by Binance, not fixed windows. We store them directly in cache and check against configured limits.

### Response Header Parsing

```php
public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
{
    $ip = self::getCurrentIp();
    $headers = self::normalizeHeaders($response);

    // Parse weight headers (X-MBX-USED-WEIGHT-1M, X-MBX-USED-WEIGHT-10S)
    $weights = self::parseIntervalHeaders($headers, 'x-mbx-used-weight-');
    foreach ($weights as $data) {
        $interval = $data['interval'];  // e.g., "1m"
        $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

        Cache::put("binance:{$ip}:weight:{$interval}", $data['value'], $ttl);
    }

    // Parse order count headers (X-MBX-ORDER-COUNT-1M, X-MBX-ORDER-COUNT-10S)
    $orders = self::parseIntervalHeaders($headers, 'x-mbx-order-count-');
    foreach ($orders as $data) {
        $interval = $data['interval'];
        $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

        $key = $accountId !== null
            ? "binance:{$ip}:uid:{$accountId}:orders:{$interval}"
            : "binance:{$ip}:orders:{$interval}";

        Cache::put($key, $data['value'], $ttl);
    }
}
```

### Methods

#### isSafeToDispatch(?int $accountId = null): int

Pre-flight safety check called before making any request.

```php
public static function isSafeToDispatch(?int $accountId = null): int
{
    // 1. Check IP ban (418 response)
    if (self::isCurrentlyBanned()) {
        return self::getSecondsUntilBanLifts();
    }

    // 2. Check rate limit proximity (>85% threshold)
    return self::checkRateLimitProximity($accountId);
}
```

#### checkRateLimitProximity(?int $accountId = null): int

Checks all configured rate limits against cached header values:

```php
protected static function checkRateLimitProximity(?int $accountId = null): int
{
    $ip = self::getCurrentIp();
    $safetyThreshold = config('martingalian.throttlers.binance.safety_threshold', 0.85);

    $rateLimits = config('martingalian.throttlers.binance.rate_limits', []);

    foreach ($rateLimits as $rateLimit) {
        $interval = $rateLimit['interval'];
        $limit = $rateLimit['limit'];
        $type = $rateLimit['type'];

        // Build cache key based on limit type
        if ($type === 'ORDERS' && $accountId !== null) {
            $key = "binance:{$ip}:uid:{$accountId}:orders:{$interval}";
        } elseif ($type === 'ORDERS') {
            $key = "binance:{$ip}:orders:{$interval}";
        } else {
            $key = "binance:{$ip}:weight:{$interval}";
        }

        $current = Cache::get($key) ?? 0;

        // Check if current usage exceeds safety threshold
        if ($current / $limit > $safetyThreshold) {
            // Calculate time until window resets
            return self::calculateWindowResetTime($interval);
        }
    }

    return 0; // Safe to proceed
}
```

### Binance API Endpoint Weights

| Endpoint | Type | Weight | Path |
|----------|------|--------|------|
| Place Order | ORDERS | 1 (10s & 1m) | POST `/fapi/v1/order` |
| Modify Order | ORDERS | 1 (10s & 1m) | PUT `/fapi/v1/order` |
| Cancel Order | REQUEST_WEIGHT | 1 | DELETE `/fapi/v1/order` |
| Query Order | REQUEST_WEIGHT | 1 | GET `/fapi/v1/order` |
| Get Open Orders (symbol) | REQUEST_WEIGHT | 1 | GET `/fapi/v1/openOrders?symbol=X` |
| Get Open Orders (all) | REQUEST_WEIGHT | 40 | GET `/fapi/v1/openOrders` |
| Get Positions | REQUEST_WEIGHT | 5 | GET `/fapi/v3/positionRisk` |
| Account Info | REQUEST_WEIGHT | 5 | GET `/fapi/v3/account` |

### Throughput Calculations

**With your Martingalian bot pattern:**
- 7 orders per position opening (1 market + 1 profit + 4 limit + 1 stop)
- Each order placement: 1 ORDER count + 0 weight

**Rate Limits:**
- ORDERS: 1020/minute = 17 orders/sec sustained, 255/10s = 25.5 orders/sec burst
- REQUEST_WEIGHT: 2040/minute = 34 weight/sec

**Maximum throughput:**
- **Position openings: ~145 positions/minute** (1020 ORDERS ÷ 7 orders per position)
- **Queries: ~400 queries/minute** (2040 weight at 5 weight per query)

**Per IP with 100 workers:**
- Can handle **~120-145 accounts** if evenly distributed
- Bottleneck: ORDERS limit (for position opening), not worker count

**Scaling to 5 IPs (5 servers):**
- Total capacity: 5 × 2040 = **10,200 weight/minute**
- Total capacity: 5 × 1020 = **5,100 orders/minute**
- Can handle **~400-500 accounts** with REST polling
- Can handle **~700-800 accounts** with WebSocket (no polling weight)

## BybitThrottler

### Responsibilities

1. **IP Ban Tracking** - Coordinate IP bans (403 responses) across workers
2. **Rate Limit Tracking** - Monitor remaining requests via response headers
3. **Fixed Window Throttling** - 550 requests per 5-second window

### Configuration

```php
'bybit' => [
    // Safety threshold: stop when remaining falls below this percentage
    // 0.15 = stop when less than 15% of requests remaining
    // Note: Bybit uses "remaining" not "used", so LOWER = MORE conservative
    'safety_threshold' => (float) env('BYBIT_THROTTLER_SAFETY_THRESHOLD', 0.15),

    // Fallback rate limit (when headers unavailable)
    'requests_per_window' => (int) env('BYBIT_THROTTLER_REQUESTS_PER_WINDOW', 550), // 92% of 600
    'window_seconds' => (int) env('BYBIT_THROTTLER_WINDOW_SECONDS', 5),

    // Optional minimum delay between requests (in milliseconds)
    // Default: 0 (disabled). Set to enforce minimum spacing between requests
    'min_delay_ms' => (int) env('BYBIT_THROTTLER_MIN_DELAY_MS', 0),
],
```

### Cache Keys

```php
// IP ban state
'bybit:{ip}:banned_until' => timestamp

// Rate limit from response headers
'bybit:{ip}:limit:status' => 50   // Remaining requests
'bybit:{ip}:limit:max' => 600     // Total limit
```

### Bybit API Response Headers

```
X-Bapi-Limit-Status: 50      // Requests remaining
X-Bapi-Limit: 600            // Total limit per window
```

**Critical difference from Binance:** Bybit provides "remaining" count, not "used" count.

### Methods

#### isSafeToDispatch(?int $accountId = null): int

```php
public static function isSafeToDispatch(?int $accountId = null): int
{
    // 1. Check IP ban (403 response)
    if (self::isCurrentlyBanned()) {
        return self::getSecondsUntilBanLifts();
    }

    // 2. Check remaining requests threshold
    $ip = self::getCurrentIp();
    $remaining = Cache::get("bybit:{$ip}:limit:status") ?? null;
    $limit = Cache::get("bybit:{$ip}:limit:max") ?? 600;

    if ($remaining !== null) {
        $safetyThreshold = config('martingalian.throttlers.bybit.safety_threshold', 0.15);

        // If remaining < 15% of limit, throttle
        if ($remaining / $limit < $safetyThreshold) {
            return 5; // Wait for next 5-second window
        }
    }

    return 0; // Safe to proceed
}
```

### Throughput

**Bybit Rate Limits:**
- HTTP Level: 600 requests per 5 seconds per IP (hard limit, triggers 403 ban)
- Safety threshold: 550 requests per 5 seconds (92% of 600)

**Achieved Performance:**
- 166 requests in 3 seconds = **55 req/sec**
- 1,660 requests in 18 seconds = **92 req/sec**
- Well below 120 req/sec limit

## Simple Throttlers (TAAPI, CoinMarketCap, Alternative.me)

### Pattern: Window-Based Throttling

These APIs use simple fixed-window rate limiting via `BaseApiThrottler`:

```php
// TAAPI Configuration
'taapi' => [
    'requests_per_window' => (int) env('TAAPI_THROTTLER_REQUESTS_PER_WINDOW', 75),  // Expert Plan
    'window_seconds' => (int) env('TAAPI_THROTTLER_WINDOW_SECONDS', 15),
    'safety_threshold' => (float) env('TAAPI_THROTTLER_SAFETY_THRESHOLD', 0.80),
],

// CoinMarketCap Configuration
'coinmarketcap' => [
    'requests_per_window' => (int) env('COINMARKETCAP_THROTTLER_REQUESTS_PER_WINDOW', 30),
    'window_seconds' => (int) env('COINMARKETCAP_THROTTLER_WINDOW_SECONDS', 60),
],
```

**Implementation:** Rely entirely on `BaseApiThrottler::canDispatch()` with fixed window algorithm.

## Integration Flow

### 1. Pre-flight Check (Before API Request)

```php
// In BaseApiableJob->handle()
public function handle()
{
    // Assign exception handler
    $this->assignExceptionHandler();

    // Pre-flight safety check
    $delaySeconds = $this->exceptionHandler->isSafeToMakeRequest();
    if ($delaySeconds > 0) {
        // Not safe, retry job later
        $this->retryJob(now()->addSeconds($delaySeconds), true);
        return;
    }

    // Record dispatch (for base throttler window tracking)
    $this->exceptionHandler->recordDispatch();

    // Make API request
    $response = $this->computeApiable();

    // Mark as completed
    $this->transitionToCompleted();
}
```

### 2. Recording Response Headers (After API Request)

```php
// In BaseApiClient->executeHttpRequest()
protected function executeHttpRequest(string $method, string $path, array $options)
{
    try {
        $response = $this->httpClient->request($method, $path, $options);

        // Automatically record response headers for rate limit tracking
        if ($this->exceptionHandler) {
            $this->exceptionHandler->recordResponseHeaders($response);
        }

        return $response;
    } catch (RequestException $e) {
        throw $e;
    }
}
```

### 3. Handling IP Bans

```php
// In BinanceExceptionHandler->handle()
public function handle(Throwable $e): void
{
    if ($this->isIpBanned($e)) {
        $retryAfter = $this->backoffSeconds($e);

        // Record ban in cache so all workers coordinate
        BinanceThrottler::recordIpBan($retryAfter);

        // Send critical notification
        $this->notify(
            level: NotificationLevel::Critical,
            title: "Binance IP Ban",
            message: "IP banned for {$retryAfter}s. All workers throttled."
        );

        throw $e; // Let job retry with exponential backoff
    }
}
```

## Worker Coordination

### Multi-Worker Scenario

**Configuration:**
- 5 servers (5 different IPs)
- 25 workers per server
- 100 accounts per server

**How workers coordinate:**

1. **All workers on same IP share REQUEST_WEIGHT limit:**
   ```
   Server 1 (IP: x.x.x.1)
   ├── Worker 1: Makes request → weight 1775/2040
   ├── Worker 2: Checks cache → sees 1775, safe to proceed
   ├── Worker 3: Checks cache → sees 1780, safe to proceed
   └── Worker 25: Checks cache → sees 2030, THROTTLED (>85%)
   ```

2. **Each account has independent ORDER limit:**
   ```
   Account 1 (UID: 12345)
   ├── Worker 5: Place order → 15/1020 orders
   ├── Worker 12: Place order → 16/1020 orders
   └── Worker 18: Place order → 17/1020 orders

   Account 2 (UID: 67890)
   ├── Worker 3: Place order → 8/1020 orders
   └── Worker 20: Place order → 9/1020 orders
   ```

3. **IP bans affect all workers on same IP:**
   ```
   Server 1: Worker 15 gets 418 response → records ban
   Server 1: All 25 workers see ban in cache → throttle for 60s
   Server 2: Different IP → unaffected, continues normally
   ```

## Cache Expiration & TTL Strategy

All cache entries use TTL matching rate limit intervals:

```php
// Binance WEIGHT-1M: 60-second TTL
Cache::put("binance:{$ip}:weight:1m", 1775, 60);

// Binance ORDER-COUNT-10S: 10-second TTL
Cache::put("binance:{$ip}:orders:10s", 15, 10);

// Bybit status: 5-second TTL
Cache::put("bybit:{$ip}:limit:status", 50, 5);

// IP ban: Variable TTL based on retry-after
Cache::put("binance:{$ip}:banned_until", $timestamp, $retryAfterSeconds);
```

**Auto-cleanup:** Redis automatically removes expired keys, no manual cleanup needed.

## Testing

### Stress Test Commands

```bash
# Test Bybit throttler with all symbols
php artisan test:sync-leverage-brackets --clean --multiplier=10
# Result: 1660 jobs in 18s, 92 req/sec, 0 retries

# Test Binance throttler with parallel account info calls
php artisan test:binance-account-info --clean --count=1000
# Result: 355 jobs in 60s, 5.9 req/sec, peak weight 1775/2040
```

### Unit Tests

```php
it('records and retrieves Binance weight headers', function () {
    $response = new Response(200, [
        'X-MBX-USED-WEIGHT-1M' => '1775',
        'X-MBX-USED-WEIGHT-10S' => '85',
    ]);

    BinanceThrottler::recordResponseHeaders($response, accountId: 1);

    $ip = Martingalian::ip();
    expect(Cache::get("binance:{$ip}:weight:1m"))->toBe(1775);
    expect(Cache::get("binance:{$ip}:weight:10s"))->toBe(85);
});

it('throttles when approaching weight limit', function () {
    $ip = Martingalian::ip();

    // Mock 87% usage (1775/2040 = 0.87)
    Cache::put("binance:{$ip}:weight:1m", 1775, 60);

    $delay = BinanceThrottler::isSafeToDispatch();

    expect($delay)->toBeGreaterThan(0); // Should throttle
});

it('coordinates IP ban across workers', function () {
    BinanceThrottler::recordIpBan(60);

    expect(BinanceThrottler::isCurrentlyBanned())->toBeTrue();
    expect(BinanceThrottler::getSecondsUntilBanLifts())->toBeLessThanOrEqual(60);
});
```

## Scaling Considerations

### Single Server (1 IP) Limitations

**Binance:**
- REQUEST_WEIGHT: 2,040/minute per IP
- With REST polling (30s intervals): ~90 accounts max
- With WebSocket: ~120-150 accounts

**Bybit:**
- 550 requests per 5 seconds per IP
- With steady load: ~200-300 accounts per IP

### Multi-Server Scaling (Multiple IPs)

**5 Servers (5 IPs):**
- Binance capacity: 5 × 2,040 = 10,200 weight/minute
- Can handle: 400-500 accounts (REST) or 700-800 accounts (WebSocket)

**Key insight:** Adding more workers on same IP does NOT help. Adding more servers (more IPs) DOES help.

### WebSocket vs REST Polling

**REST Polling (30s intervals):**
```
Per account per minute:
- getPositions(): 5 weight × 2 = 10 weight
- getCurrentOpenOrders(): 1 weight × 2 = 2 weight
- account(): 5 weight × 2 = 10 weight
Total: 22 weight/minute per account

10,200 weight ÷ 22 = ~463 accounts (theoretical)
With 20% buffer: ~370 accounts (realistic)
```

**WebSocket (User Data Stream):**
```
Per account per minute:
- Real-time order updates: 0 weight
- Real-time position updates: 0 weight
- Real-time balance updates: 0 weight
- Occasional emergency query: ~5 weight/minute

10,200 weight ÷ 5 = 2,040 accounts (theoretical)
With other factors (DB, memory): ~700-800 accounts (realistic)
```

## Configuration Best Practices

### Horizon Workers (25 per server)

```php
'cronjobs-supervisor' => [
    'processes' => 10,  // Main workhorse
],
'orders-supervisor' => [
    'processes' => 3,   // Critical time-sensitive
],
'positions-supervisor' => [
    'processes' => 3,   // Critical time-sensitive
],
'indicators-supervisor' => [
    'processes' => 4,   // Medium priority
],
// ... background tasks: 1-2 workers each
// Total: 25 workers per CX22 server
```

### Memory Planning

```
Per worker: ~80 MB
25 workers: 2,000 MB
OS + overhead: 500 MB
Available on CX22 (4 GB): 3,500 MB
Headroom: 1,500 MB (43%)
```

### Database Connections

```
25 workers × 3 connections = 75 connections
MySQL default: 151 connections
Safe: ✅
```

## Future Enhancements

- **Dynamic safety thresholds** - Adjust based on traffic patterns
- **Predictive throttling** - ML to predict rate limit exhaustion
- **Dashboard** - Real-time rate limit monitoring UI
- **WebSocket integration** - Eliminate REST polling weight
- **Per-account ORDER tracking** - Full implementation for Binance
- **Auto-scaling** - Automatically adjust worker count based on load
- **Metrics export** - Prometheus/Grafana integration
