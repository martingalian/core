# Throttling System - Codebase Examples

Real-world throttling implementation patterns from production code.

## Table of Contents

1. [BinanceThrottler Usage](#binancethrottler-usage)
2. [Pre-flight Safety Checks](#pre-flight-safety-checks)
3. [Response Header Recording](#response-header-recording)
4. [Rate Limit Proximity Detection](#rate-limit-proximity-detection)
5. [IP Ban Tracking](#ip-ban-tracking)
6. [Cache Key Patterns](#cache-key-patterns)

---

## BinanceThrottler Usage

### Standard Pattern in Jobs

**From: BaseApiableJob (conceptual usage)**

```php
// BEFORE making API request
$secondsToWait = BinanceThrottler::isSafeToDispatch($accountId);
if ($secondsToWait > 0) {
    // Not safe - release job for later
    $this->release($secondsToWait);
    return;
}

// Record dispatch (increments request counter)
BinanceThrottler::recordDispatch($accountId);

// Make API request
$response = $binanceClient->makeRequest();

// AFTER receiving response - record headers
BinanceThrottler::recordResponseHeaders($response, $accountId);
```

**Pattern Flow:**
1. **Check safety** - Is IP banned? Rate limit close?
2. **Record dispatch** - Increment local counter
3. **Make request** - Actual API call
4. **Record headers** - Update rate limit state from response

---

## Pre-flight Safety Checks

### isSafeToDispatch() Method

**From: BinanceThrottler (line 51)**

```php
public static function isSafeToDispatch(?int $accountId = null): int
{
    $prefix = self::getCacheKeyPrefix();

    // 1. Check if IP is currently banned (418 response)
    if (self::isCurrentlyBanned()) {
        $secondsRemaining = self::getSecondsUntilBanLifts();
        Log::channel('jobs')->info(
            "[THROTTLER] {$prefix} | IP currently banned | Wait: {$secondsRemaining}s"
        );
        return $secondsRemaining;
    }

    // 2. Check minimum delay since last request
    try {
        $ip = self::getCurrentIp();
        $minDelayMs = config('martingalian.throttlers.binance.min_delay_ms', 0);

        if ($minDelayMs > 0) {
            $lastRequest = Cache::get("binance:{$ip}:last_request");
            if ($lastRequest) {
                $elapsedMs = (now()->timestamp - $lastRequest) * 1000;
                if ($elapsedMs < $minDelayMs) {
                    $waitSeconds = (int) ceil(($minDelayMs - $elapsedMs) / 1000);
                    return $waitSeconds > 0 ? $waitSeconds : 1;
                }
            }
        }
    } catch (Throwable $e) {
        // Fail-safe: allow request on error
        Log::warning("Failed to check Binance min delay: {$e->getMessage()}");
    }

    // 3. Check if approaching any rate limit (>80% threshold)
    $secondsToWait = self::checkRateLimitProximity($accountId);
    if ($secondsToWait > 0) {
        Log::channel('jobs')->info(
            "[THROTTLER] {$prefix} | Throttled by rate limit proximity: {$secondsToWait}s"
        );
        return $secondsToWait;
    }

    return 0;  // Safe to proceed
}
```

**Checks Performed:**
1. **IP Ban** - Currently in 418 timeout?
2. **Minimum Delay** - Enough time since last request?
3. **Rate Limit Proximity** - Close to any limit (>80%)?

**Return Value:**
- `0` = Safe to proceed
- `> 0` = Seconds to wait

---

## Response Header Recording

### recordResponseHeaders() Method

**From: BinanceThrottler (line 102)**

```php
public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
{
    try {
        $ip = self::getCurrentIp();
        $headers = self::normalizeHeaders($response);

        // Parse and store weight headers
        $weights = self::parseIntervalHeaders($headers, 'x-mbx-used-weight-');
        foreach ($weights as $data) {
            $interval = $data['interval'];
            $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

            Cache::put(
                "binance:{$ip}:weight:{$interval}",
                $data['value'],
                $ttl
            );
        }

        // Parse and store order count headers (UID-based, per account)
        $orders = self::parseIntervalHeaders($headers, 'x-mbx-order-count-');
        foreach ($orders as $data) {
            $interval = $data['interval'];
            $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

            // ORDER limits are per UID (account)
            $key = $accountId !== null
                ? "binance:{$ip}:uid:{$accountId}:orders:{$interval}"
                : "binance:{$ip}:orders:{$interval}";  // Fallback

            Cache::put($key, $data['value'], $ttl);
        }

        // Record timestamp of last request
        Cache::put("binance:{$ip}:last_request", now()->timestamp, 60);
    } catch (Throwable $e) {
        // Fail silently - don't break application if Cache fails
        Log::warning("Failed to record Binance response headers: {$e->getMessage()}");
    }
}
```

**Headers Parsed:**
- `X-MBX-USED-WEIGHT-1M` → `binance:1.2.3.4:weight:1m`
- `X-MBX-USED-WEIGHT-10S` → `binance:1.2.3.4:weight:10s`
- `X-MBX-ORDER-COUNT-10S` → `binance:1.2.3.4:uid:123:orders:10s`

**Key Patterns:**
- **IP-scoped weights**: `binance:{ip}:weight:{interval}`
- **Account-scoped orders**: `binance:{ip}:uid:{accountId}:orders:{interval}`
- **Last request**: `binance:{ip}:last_request`

**TTL Calculation:**
```php
protected static function getIntervalTTL(int $num, string $letter): int
{
    return match (strtolower($letter)) {
        's' => $num,           // seconds
        'm' => $num * 60,      // minutes
        'h' => $num * 3600,    // hours
        'd' => $num * 86400,   // days
        default => 60,
    };
}
```

---

## Rate Limit Proximity Detection

### checkRateLimitProximity() Method

**Conceptual implementation:**

```php
protected static function checkRateLimitProximity(?int $accountId = null): int
{
    $ip = self::getCurrentIp();

    // Check all weight limits (IP-based)
    $weightLimits = [
        '10s' => 1000,   // 1000 weight per 10 seconds
        '1m' => 6000,    // 6000 weight per minute
    ];

    foreach ($weightLimits as $interval => $maxWeight) {
        $currentWeight = (int) Cache::get("binance:{$ip}:weight:{$interval}", 0);
        $threshold = $maxWeight * 0.8;  // 80% threshold

        if ($currentWeight >= $threshold) {
            // Too close to limit - calculate wait time
            $ttl = self::getIntervalTTL(...);  // Parse interval
            return $ttl;
        }
    }

    // Check order limits (UID-based, if account provided)
    if ($accountId) {
        $orderLimits = [
            '10s' => 50,    // 50 orders per 10 seconds
            '1m' => 200,    // 200 orders per minute
        ];

        foreach ($orderLimits as $interval => $maxOrders) {
            $key = "binance:{$ip}:uid:{$accountId}:orders:{$interval}";
            $currentOrders = (int) Cache::get($key, 0);
            $threshold = $maxOrders * 0.8;

            if ($currentOrders >= $threshold) {
                $ttl = self::getIntervalTTL(...);
                return $ttl;
            }
        }
    }

    return 0;  // No limits close
}
```

**Threshold Strategy:**
- **80% rule**: Throttle when reaching 80% of limit
- Prevents hitting hard limits
- Buffer for concurrent requests

**Example:**
- Limit: 1000 weight/10s
- Current: 850 weight
- 850 / 1000 = 85% → **THROTTLE** (wait 10 seconds)

---

## IP Ban Tracking

### isCurrentlyBanned() Method

**From: BinanceThrottler (line 146)**

```php
public static function isCurrentlyBanned(): bool
{
    try {
        $ip = self::getCurrentIp();
        $bannedUntil = Cache::get("binance:{$ip}:banned_until");

        if ($bannedUntil === null) {
            return false;
        }

        $now = now();
        $bannedUntilCarbon = is_int($bannedUntil)
            ? Carbon::createFromTimestamp($bannedUntil)
            : Carbon::parse($bannedUntil);

        return $now->lessThan($bannedUntilCarbon);
    } catch (Throwable $e) {
        // Fail-safe: assume not banned on error
        return false;
    }
}
```

### recordIpBan() Method

**Conceptual implementation:**

```php
public static function recordIpBan(int $retryAfterSeconds): void
{
    try {
        $ip = self::getCurrentIp();
        $bannedUntil = now()->addSeconds($retryAfterSeconds);

        Cache::put(
            "binance:{$ip}:banned_until",
            $bannedUntil->timestamp,
            $retryAfterSeconds
        );

        Log::warning("[THROTTLER] IP banned until {$bannedUntil}", [
            'ip' => $ip,
            'retry_after' => $retryAfterSeconds,
        ]);
    } catch (Throwable $e) {
        Log::error("Failed to record IP ban: {$e->getMessage()}");
    }
}
```

**Usage in Exception Handler:**

```php
if ($httpCode === 418) {
    // IP ban escalation
    $retryAfter = $this->extractRetryAfter($response);  // e.g., 300 seconds
    BinanceThrottler::recordIpBan($retryAfter);
    throw new RateLimitException("IP banned for {$retryAfter}s");
}
```

---

## Cache Key Patterns

### Binance Cache Keys

```php
// Weight limits (IP-scoped)
"binance:{ip}:weight:10s"    // Used weight in last 10 seconds
"binance:{ip}:weight:1m"     // Used weight in last minute

// Order limits (UID-scoped per account)
"binance:{ip}:uid:{accountId}:orders:10s"   // Orders in last 10 seconds
"binance:{ip}:uid:{accountId}:orders:1m"    // Orders in last minute

// IP ban tracking
"binance:{ip}:banned_until"   // Timestamp when ban lifts

// Last request timing
"binance:{ip}:last_request"   // Timestamp of last API call
```

### Bybit Cache Keys

```php
// Simple IP-based rate limiting
"bybit:{ip}:weight:10s"
"bybit:{ip}:last_request"
"bybit:{ip}:banned_until"
```

**IP Resolution:**
```php
protected static function getCurrentIp(): string
{
    // Use hostname to IP conversion for consistent keys
    $hostname = gethostname();
    return gethostbyname($hostname);  // e.g., "1.2.3.4"
}
```

---

## Header Parsing

### parseIntervalHeaders() Method

**From: BinanceThrottler**

```php
protected static function parseIntervalHeaders(array $headers, string $prefix): array
{
    $result = [];

    foreach ($headers as $key => $values) {
        $lowerKey = strtolower($key);

        if (!Str::startsWith($lowerKey, $prefix)) {
            continue;
        }

        // Extract interval from header name
        // e.g., "x-mbx-used-weight-1m" → "1m"
        $interval = substr($lowerKey, strlen($prefix));

        // Parse number and letter
        // "1m" → num=1, letter=m
        preg_match('/^(\d+)([a-z])$/i', $interval, $matches);
        if (count($matches) !== 3) {
            continue;
        }

        $intervalNum = (int) $matches[1];
        $intervalLetter = strtolower($matches[2]);

        // Get header value (first element if array)
        $value = is_array($values) ? (int) ($values[0] ?? 0) : (int) $values;

        $result[] = [
            'interval' => $interval,
            'intervalNum' => $intervalNum,
            'intervalLetter' => $intervalLetter,
            'value' => $value,
        ];
    }

    return $result;
}
```

**Example:**
```php
// Input headers
[
    'X-MBX-USED-WEIGHT-1M' => ['1234'],
    'X-MBX-USED-WEIGHT-10S' => ['123'],
]

// Output
[
    [
        'interval' => '1m',
        'intervalNum' => 1,
        'intervalLetter' => 'm',
        'value' => 1234,
    ],
    [
        'interval' => '10s',
        'intervalNum' => 10,
        'intervalLetter' => 's',
        'value' => 123,
    ],
]
```

---

## Integration with BaseApiableJob

### shouldStartOrThrottle() Method

**From: BaseApiableJob**

```php
protected function shouldStartOrThrottle(): bool
{
    // Assign exception handler
    if (!isset($this->exceptionHandler)) {
        $this->assignExceptionHandler();
    }

    // Check exception handler's pre-flight safety
    if (isset($this->exceptionHandler) && !$this->exceptionHandler->isSafeToMakeRequest()) {
        // Delegated to throttler via exception handler
        $this->release(60);
        return false;
    }

    // Additional throttling logic...

    return true;  // OK to proceed
}
```

**Delegation Chain:**
1. `BaseApiableJob::shouldStartOrThrottle()`
2. → `BinanceExceptionHandler::isSafeToMakeRequest()`
3. → `BinanceThrottler::isSafeToDispatch()`

---

## Configuration

### Config File Structure

**From: config/martingalian.php**

```php
'throttlers' => [
    'binance' => [
        'enabled' => true,
        'min_delay_ms' => 100,  // Minimum 100ms between requests
        'proximity_threshold' => 0.8,  // Throttle at 80%
        'weight_limits' => [
            '10s' => 1000,
            '1m' => 6000,
        ],
        'order_limits' => [
            '10s' => 50,
            '1m' => 200,
        ],
    ],
    'bybit' => [
        'enabled' => true,
        'min_delay_ms' => 50,
        'proximity_threshold' => 0.8,
        'weight_limits' => [
            '10s' => 100,
        ],
    ],
],
```

---

## Monitoring and Logging

### Log Examples

```php
// Safety check
Log::channel('jobs')->info("[THROTTLER] binance | IP currently banned | Wait: 300s");

// Proximity throttle
Log::channel('jobs')->info("[THROTTLER] binance | Throttled by rate limit proximity: 10s");

// Header recording failure
Log::warning("Failed to record Binance response headers: Redis connection timeout");

// IP ban recorded
Log::warning("[THROTTLER] IP banned until 2025-01-08 16:30:00", [
    'ip' => '1.2.3.4',
    'retry_after' => 300,
]);
```

### Debugging Cache State

```bash
# Check current weight usage
redis-cli GET "binance:1.2.3.4:weight:1m"
# Output: "1234"

# Check IP ban status
redis-cli GET "binance:1.2.3.4:banned_until"
# Output: "1736349000"

# Check order count (account 123)
redis-cli GET "binance:1.2.3.4:uid:123:orders:10s"
# Output: "12"
```

---

## Zero-Second Throttling

### Immediate Execution Pattern

**From: Throttler::execute() (Support/Throttler.php)**

```php
public function execute(Closure $callback): bool
{
    // Get active throttle rule
    $throttleRule = ThrottleRule::findByCanonical($this->canonical);

    // Use override or rule's throttle seconds
    $throttleSeconds = $this->throttleSecondsOverride ?? $throttleRule->throttle_seconds;

    // If throttle is 0 seconds, execute immediately without any throttle logic or log creation
    if ($throttleSeconds === 0) {
        $callback();
        return false; // Not throttled, executed
    }

    // Use a short-lived transaction ONLY for the throttle check
    // ... rest of throttle logic with pessimistic locking ...
}
```

**Zero-Second Behavior:**
- Executes callback immediately
- **No database operations** - no throttle logs created
- **No locking** - no transaction overhead
- Returns `false` (not throttled)

**Use Cases:**
- Notifications that must send immediately
- Critical real-time operations
- Events that cannot be delayed

**Example Usage:**

```php
// Auto-deactivation notification (never throttled)
Throttler::using(NotificationService::class)
    ->withCanonical('exchange_symbol_no_taapi_data')  // Has 0 seconds in DB
    ->for($exchangeSymbol)
    ->execute(function () use ($messageData, $exchangeSymbol) {
        NotificationService::send(
            user: Martingalian::admin(),
            message: $messageData['emailMessage'],
            title: $messageData['title'],
            canonical: 'exchange_symbol_no_taapi_data',
            deliveryGroup: 'default',
            severity: $messageData['severity'],
            pushoverMessage: $messageData['pushoverMessage'],
            actionUrl: $messageData['actionUrl'],
            actionLabel: $messageData['actionLabel'],
            relatable: $exchangeSymbol
        );
    });
```

**Configuration:**

```php
// In MartingalianSeeder.php
[
    'canonical' => 'exchange_symbol_no_taapi_data',
    'description' => 'No throttle - send notification immediately',
    'throttle_seconds' => 0,  // Zero = no throttling
    'is_active' => true,
],
```

**Benefits:**
- **Performance**: No DB queries or locks
- **Simplicity**: Clean separation of throttled vs instant operations
- **Flexibility**: Can enable throttling later by changing DB value
- **Audit**: Still uses canonical for tracking (even though no logs)

---

## Summary

**Key Patterns:**
1. **Pre-flight checks** - Before every API request
2. **Header recording** - After every API response
3. **IP-scoped weights** - Shared across all accounts
4. **UID-scoped orders** - Per-account tracking
5. **80% threshold** - Proactive throttling
6. **IP ban tracking** - Respects 418 responses
7. **Zero-second throttling** - Immediate execution without overhead

**Cache Strategy:**
- Keys include IP for multi-server coordination
- TTL matches interval (10s key expires in 10s)
- Fail-safe on Cache errors (allow request)

**Key Files:**
- `BinanceThrottler.php` - Binance-specific implementation
- `BybitThrottler.php` - Bybit-specific implementation
- `BaseApiThrottler.php` - Shared base class
- `BaseApiableJob.php` - Integration point
- `Throttler.php` - General-purpose throttling with 0-second support

**Never:**
- Skip pre-flight checks
- Ignore response headers
- Hardcode rate limits in jobs
- Use hostname in cache keys (use IP)
- Create throttle logs for 0-second rules
