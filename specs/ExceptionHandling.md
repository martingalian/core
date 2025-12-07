# Exception Handling System

## Overview
Comprehensive exception handling system for API interactions, job execution, and error recovery. Provides unified error handling across multiple exchanges (Binance, Bybit, Kraken Futures) and data providers (TAAPI, CoinMarketCap, AlternativeMe) with intelligent retry logic, throttling, and notification routing.

## Architecture

### Exception Flow
```
API Request/Job Execution
    ↓
Exception Thrown
    ↓
ExceptionParser analyzes exception
    ↓
BaseExceptionHandler (API-specific) classifies error
    ↓
Retry Logic Applied OR Fail Permanently
    ↓
Notification Sent (if warranted)
    ↓
Job Rescheduled OR Marked Failed
```

## Core Components

### ExceptionParser
**Location**: `Martingalian\Core\Exceptions\ExceptionParser`
**Purpose**: Extracts structured error information from any Throwable

**Features**:
- Parses exception class, message, file, line number
- Extracts HTTP status codes from RequestException
- Decodes vendor-specific error codes (e.g., Binance -1003)
- Generates friendly error messages for notifications
- Traces back to user code (ignores vendor/ directory)

**Usage**:
```php
use Martingalian\Core\Exceptions\ExceptionParser;

try {
    $apiClient->makeRequest();
} catch (Throwable $e) {
    $parser = ExceptionParser::with($e);

    // Get structured data
    $className = $parser->className(); // "RequestException"
    $httpCode = $parser->httpStatusCode(); // 429
    $vendorCode = $parser->errorCode(); // -1003
    $message = $parser->friendlyMessage(); // "RequestException: Too Many Requests..."
    $file = $parser->filename(); // "packages/martingalian/core/src/..."
    $line = $parser->lineNumber(); // 142
}
```

**HTTP Response Parsing**:
```php
// For RequestException with JSON body:
{
  "code": -1003,
  "msg": "Too many requests"
}

// Parser extracts:
$parser->httpStatusCode(); // 429
$parser->errorCode(); // -1003
$parser->errorMsg(); // "Too many requests"
```

### BaseExceptionHandler
**Location**: `Martingalian\Core\Abstracts\BaseExceptionHandler`
**Purpose**: Abstract base class for API-specific exception handlers

**Properties**:
- `public int $backoffSeconds = 10` - Default backoff duration
- `public ?Account $account = null` - Optional account for per-account rate limiting (set via `withAccount()`)

**Key Methods**:
- `isRateLimited(Throwable $e): bool` - Detect rate limit errors
- `isForbidden(Throwable $e): bool` - Detect auth/permission errors
- `isRecvWindowMismatch(Throwable $e): bool` - Detect timestamp errors
- `rateLimitUntil(RequestException $e): Carbon` - Calculate retry time
- `recordResponseHeaders(ResponseInterface $r): void` - Track rate limit state (delegates to Throttlers)
- `isCurrentlyBanned(): bool` - Check if IP is banned (delegates to Throttlers)
- `isSafeToMakeRequest(): bool` - Pre-flight safety check (delegates to Throttlers)
- `recordIpBan(int $retryAfterSeconds): void` - Record IP ban (delegates to Throttlers)
- `withAccount(Account $account)` - Eager load account for per-account rate limiting

**Factory Pattern**:
```php
$handler = BaseExceptionHandler::make('binance');
// Returns BinanceExceptionHandler instance

$handler = BaseExceptionHandler::make('bybit');
// Returns BybitExceptionHandler instance

$handler = BaseExceptionHandler::make('kraken');
// Returns KrakenExceptionHandler instance

// With account for per-account ORDER limits (Binance)
$handler = BaseExceptionHandler::make('binance')->withAccount($account);
```

**Error Code Classification**:
Each handler defines arrays of HTTP/vendor codes:
- `$ignorableHttpCodes` - Safe to ignore (e.g., "no change needed")
- `$retryableHttpCodes` - Temporary errors, safe to retry
- `$forbiddenHttpCodes` - Auth/permission errors (don't retry)
- `$rateLimitedHttpCodes` - Rate limit errors (backoff and retry)
- `$recvWindowMismatchedHttpCodes` - Timestamp sync errors (retry)
- `$accountStatusCodes` - Critical account errors (disable account)
- `$insufficientBalanceCodes` - Insufficient balance/margin
- `$kycRequiredCodes` - KYC verification required
- `$systemErrorCodes` - Exchange system errors (timeout, unknown)

## API-Specific Handlers

### BinanceExceptionHandler
**Location**: `Martingalian\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler`
**Specialty**: Binance Futures API error handling

**Key Features**:
- Parses Retry-After header from 429/418 responses
- Calculates retry time from interval headers (X-MBX-USED-WEIGHT-1M)
- Distinguishes between IP bans (418) and standard rate limits (429)
- Handles recvWindow mismatches (-1021, -5028)
- Treats 403 with WAF messages as rate limits (not auth errors)

**Error Code Examples**:
- `-1003`: Too many requests (rate limit)
- `-1015`: Too many new orders (rate limit)
- `-1021`: Timestamp outside recvWindow (retry with time sync)
- `-2015`: Invalid API key, IP, or permissions (ambiguous, needs investigation)
- `-2018`: Insufficient balance
- `-2019`: Insufficient margin
- `-4046`: No need to change margin type (ignorable)
- `-4087`: Reduce-only mode (account status issue)

**Retry-After Logic**:
```php
// 1. Check Retry-After header
$retryAfter = $response->getHeader('Retry-After'); // "60"
$retryTime = now()->addSeconds($retryAfter);

// 2. If no Retry-After, parse interval headers
$headers = [
    'x-mbx-used-weight-1m' => '1200',
    'x-mbx-order-count-10s' => '50',
];
// Calculate next window reset (e.g., next minute boundary)
$retryTime = now()->startOfMinute()->addMinute();
```

**IP Ban Handling**:
```php
// 418 response with Retry-After: 60
if ($handler->isIpBanned($exception)) {
    $retryAfter = 60; // seconds
    $handler->recordIpBan($retryAfter);
    // Delegates to BinanceThrottler which stores in cache: binance:ip_ban:{hostname}
    // All jobs on this IP will pause for 60 seconds
}

// Check if currently banned
if ($handler->isCurrentlyBanned()) {
    // Delegates to BinanceThrottler->isCurrentlyBanned()
    // Returns true if cache key exists and hasn't expired
}
```

### BybitExceptionHandler
**Location**: `Martingalian\Core\Support\ApiExceptionHandlers\BybitExceptionHandler`
**Specialty**: Bybit V5 API error handling

**Key Features**:
- Clearer error code separation (API key vs signature vs IP)
- Handles Bybit-specific rate limit headers
- Distinguishes credential errors more precisely

**Error Code Examples**:
- `10001`: Rate limit exceeded
- `10003`: Invalid API key
- `10004`: Invalid signature
- `10005`: Insufficient permissions
- `10010`: IP not whitelisted
- `110007`: Insufficient balance
- `110043`: Reduce-only mode

### KrakenExceptionHandler
**Location**: `Martingalian\Core\Support\ApiExceptionHandlers\KrakenExceptionHandler`
**Specialty**: Kraken Futures API error handling

**Key Features**:
- HTTP status code based error classification
- Handles Retry-After header for rate limits
- Distinguishes between account blocked (401) and forbidden (403)
- Retryable server errors with exponential backoff

**Error Code Arrays**:
```php
$serverForbiddenHttpCodes = [403];        // IP blocked / permission denied
$serverRateLimitedHttpCodes = [429];      // Rate limit exceeded
$accountBlockedHttpCodes = [401];         // Auth failed / API key invalid
$retryableHttpCodes = [408, 500, 502, 503, 504]; // Temporary errors
```

**Retry-After Logic**:
```php
// 429 response with Retry-After header
if ($handler->isRateLimited($exception)) {
    $retryAfter = $response->getHeader('Retry-After')[0] ?? 60;
    $retryTime = now()->addSeconds($retryAfter);
}
```

**Account Blocked Handling**:
- 401 errors create `ForbiddenHostname` record with type `account_blocked`
- Prevents further API calls until credentials are verified
- Sends critical notification to admin

### TaapiExceptionHandler
**Location**: `Martingalian\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler`
**Specialty**: TAAPI.io indicator API

**Key Features**:
- Standard HTTP status code handling
- 15-second window rate limiting
- **Conditional ignore for HTTP 400**: Some 400s are ignorable (invalid symbol) but plan limit errors are NOT

**Plan Limit Error Handling**:
The `ignoreException()` method overrides the base behavior to NOT ignore plan limit errors:

```php
// Error patterns that should NOT be ignored (even on HTTP 400):
'constructs than your plan allows'   // Bulk API construct limit exceeded
'calculations than your plan allows' // Calculation limit exceeded
```

**Why This Matters**:
- Bulk API requests (`FetchAndStoreCandlesBulkJob`, `QuerySymbolIndicatorsBulkJob`) may exceed plan limits
- These errors indicate a configuration issue (too many symbols per chunk)
- Job should FAIL to alert about the issue, not silently continue

**Rate Limit Logic**:
```php
public function rateLimitUntil(RequestException $e): Carbon
{
    // TAAPI uses 15-second windows
    // Calculate next window boundary + 3 second buffer
    $currentSecond = now()->second;
    $windowStart = floor($currentSecond / 15) * 15;
    $nextWindowStart = $windowStart + 15;
    $secondsUntilNextWindow = $nextWindowStart - $currentSecond;

    return now()->addSeconds($secondsUntilNextWindow + 3);
}
```

### AlternativeMeExceptionHandler
**Location**: `Martingalian\Core\Support\ApiExceptionHandlers\AlternativeMeExceptionHandler`
**Purpose**: Fear & Greed Index API

### CoinmarketCapExceptionHandler
**Location**: `Martingalian\Core\Support\ApiExceptionHandlers\CoinmarketCapExceptionHandler`
**Purpose**: CoinMarketCap cryptocurrency data API

## Custom Exceptions

### JustEndException
**Location**: `Martingalian\Core\Exceptions\JustEndException`
**Purpose**: Silently end job execution without retry or notification

**Use Case**: When an error occurs but no action is needed:
- Too many orders already created (no need to create more)
- Resource already exists (idempotent operation)
- Validation fails but it's expected

**Example**:
```php
if ($this->orderCount >= $maxOrders) {
    throw new JustEndException("Max orders reached, stopping gracefully");
}
// Job ends, no retry, no notification
```

### JustResolveException
**Location**: `Martingalian\Core\Exceptions\JustResolveException`
**Purpose**: Mark job as resolved without failure

**Use Case**: Soft failures that should be marked as completed:
- Optional operation that couldn't complete
- Feature flag disabled mid-execution
- Resource no longer needed

### NonNotifiableException
**Location**: `Martingalian\Core\Exceptions\NonNotifiableException`
**Purpose**: Exception that should not trigger notifications

**Use Case**: Known/expected errors that don't need admin attention:
- Stale data detected (will refresh next cycle)
- Symbol no longer active (expected state change)
- Test mode operation

### MaxRetriesReachedException
**Location**: `Martingalian\Core\Exceptions\MaxRetriesReachedException`
**Purpose**: Thrown when retry limit exceeded

**Usage**: Jobs that have exhausted retry attempts throw this for final handling

## Retry Patterns

### Pattern 1: Immediate Retry (Transient Errors)
**When**: Network glitches, temporary API unavailability
**Examples**: 503, 504, connection timeout
**Strategy**: Retry immediately, up to 3 times with exponential backoff

```php
$maxRetries = 3;
$attempt = 0;
$backoff = 1; // seconds

while ($attempt < $maxRetries) {
    try {
        return $apiClient->makeRequest();
    } catch (RequestException $e) {
        if ($handler->isRetryable($e) && $attempt < $maxRetries - 1) {
            sleep($backoff);
            $backoff *= 2; // Exponential: 1s, 2s, 4s
            $attempt++;
            continue;
        }
        throw $e;
    }
}
```

### Pattern 2: Delayed Retry (Rate Limits)
**When**: Rate limit errors (429, 418, -1003)
**Strategy**: Wait until rate limit resets before retrying

```php
try {
    return $apiClient->makeRequest();
} catch (RequestException $e) {
    if ($handler->isRateLimited($e)) {
        $retryAt = $handler->rateLimitUntil($e); // Carbon instance
        $delaySeconds = now()->diffInSeconds($retryAt);

        // Re-dispatch job with delay
        dispatch($this)->delay($retryAt);

        throw new JustEndException("Rate limited, retrying at {$retryAt}");
    }
    throw $e;
}
```

### Pattern 3: No Retry (Permanent Errors)
**When**: Auth errors, invalid API key, IP not whitelisted
**Strategy**: Fail immediately, notify admin, disable account if needed

```php
try {
    return $apiClient->makeRequest();
} catch (RequestException $e) {
    if ($handler->isForbidden($e)) {
        // Permanent error, don't retry
        $this->account->update(['can_trade' => false]);

        // Notify admin
        NotificationService::critical("Account {$this->account->id} disabled: Invalid API credentials");

        throw $e; // Mark job as failed
    }
}
```

### Pattern 4: Clock Sync Retry (Timestamp Errors)
**When**: recvWindow mismatch (-1021, -5028)
**Strategy**: Sync clock with exchange server, then retry

```php
try {
    return $apiClient->makeRequest();
} catch (RequestException $e) {
    if ($handler->isRecvWindowMismatch($e)) {
        // Fetch server time from exchange
        $serverTime = $apiClient->getServerTime();

        // Calculate offset
        $localTime = now()->timestamp * 1000;
        $offset = $serverTime - $localTime;

        // Store offset for future requests
        cache()->put("time_offset_{$apiSystem}", $offset, 3600);

        // Retry with adjusted timestamp
        return $apiClient->makeRequest(['timestamp' => $serverTime]);
    }
}
```

## Integration with Jobs

### BaseQueueableJob Exception Handling
Jobs extend BaseQueueableJob which provides centralized exception handling:

```php
public function handle()
{
    try {
        $this->process(); // Your business logic
    } catch (JustEndException $e) {
        // Silently end, no retry
        return;
    } catch (JustResolveException $e) {
        // Mark as resolved
        $this->markAsResolved();
        return;
    } catch (NonNotifiableException $e) {
        // Fail without notification
        $this->fail($e);
    } catch (MaxRetriesReachedException $e) {
        // Max retries reached, notify and fail
        NotificationService::warning("Job failed after max retries: " . get_class($this));
        $this->fail($e);
    } catch (Throwable $e) {
        // General exception handling
        $this->handleException($e);
    }
}

protected function handleException(Throwable $e)
{
    // Parse exception
    $parser = ExceptionParser::with($e);

    // Get API handler if applicable
    if ($this->apiSystem) {
        $handler = BaseExceptionHandler::make($this->apiSystem);

        // Check if should retry
        if ($handler->isRateLimited($e)) {
            $this->retryAfterRateLimit($handler, $e);
            return;
        }

        if ($handler->isRetryable($e)) {
            $this->retryWithBackoff();
            return;
        }

        if ($handler->isForbidden($e)) {
            $this->handleForbiddenError($parser);
            $this->fail($e);
            return;
        }
    }

    // Default: retry with exponential backoff
    if ($this->attempts() < $this->maxRetries) {
        $this->release($this->calculateBackoff());
    } else {
        $this->fail($e);
    }
}
```

### BaseApiableJob Exception Handling
API-specific jobs extend BaseApiableJob which includes:
- Pre-flight safety checks (`shouldStartOrThrottle()` - delegates to exception handler)
- Response header recording (`recordResponseHeaders()` - called by BaseApiClient)
- IP ban coordination (via Throttlers)
- Throttle rule integration
- **Model caching** - Prevents duplicate operations on retry

```php
public function handle()
{
    $handler = BaseExceptionHandler::make($this->apiSystem)
        ->withAccount($this->account); // For per-account rate limiting

    // Pre-flight check (delegates to Throttler via isSafeToMakeRequest())
    $delaySeconds = $this->shouldStartOrThrottle($handler);
    if ($delaySeconds > 0) {
        // IP banned or rate limit approaching
        $this->release($delaySeconds);
        return;
    }

    try {
        // BaseApiClient calls recordResponseHeaders() automatically after successful requests
        $response = $this->makeApiRequest();

        $this->process($response);
    } catch (RequestException $e) {
        // Check for IP ban
        if ($handler->isIpBanned($e)) {
            $retryAfter = $handler->backoffSeconds($e);
            $handler->recordIpBan($retryAfter); // Delegates to Throttler

            // Notify about IP ban
            NotificationService::critical("IP banned on {$this->apiSystem} for {$retryAfter}s");

            $this->release($retryAfter);
            return;
        }

        // Handle other exception types...
        $this->handleException($e);
    }
}

// Example with caching to prevent duplicate operations on retry
public function computeApiable()
{
    return $this->step->cache()->getOr('place_market_order', function() {
        $order = $this->position->orders()->create([...]);
        $order->apiPlace(); // If this rate limits, job retries
        return ['order' => format_model_attributes($order)];
    });
    // On retry: Cache hit, no duplicate order created!
}

// shouldStartOrThrottle() implementation in BaseApiableJob
protected function shouldStartOrThrottle(BaseExceptionHandler $handler): int
{
    // Returns delay in seconds (0 = safe to proceed, >0 = wait)
    return $handler->isSafeToMakeRequest() ? 0 : 60;
}
```

## Notification Integration

### Throttled Notifications
Exception handlers integrate with ThrottleRules to prevent notification spam:

**Throttle Rules** (see ThrottleRulesSeeder.php):
- `binance_server_rate_limit_exceeded` - 1800s (30 min)
- `bybit_api_connection_failed` - 900s (15 min)
- `binance_account_in_liquidation` - 900s (15 min)
- `taapi_invalid_api_credentials` - 1800s (30 min)

**Usage in Exception Handler**:
```php
if ($handler->isRateLimited($e)) {
    $canonical = "{$this->apiSystem}_server_rate_limit_exceeded";

    if (Throttler::canNotify($canonical)) {
        NotificationService::warning("Rate limit exceeded on {$this->apiSystem}");
    }
    // Else: Skip notification (throttled)
}
```

### Critical vs Warning Notifications

**Critical** (immediate Pushover notification):
- Account disabled (trading banned, liquidation)
- IP banned
- Invalid API credentials (after verification)
- System errors on production

**Warning** (email notification):
- Rate limits exceeded
- Connection failures (after multiple retries)
- KYC verification required
- Insufficient balance

**Info** (logged only):
- Ignorable errors (no action needed)
- Transient errors (will retry)
- Non-notifiable exceptions

## Error Code Mapping

### Binance Error Codes
| Code | Meaning | Category | Action |
|------|---------|----------|--------|
| -1000 | Unknown error | System Error | Retry |
| -1003 | Too many requests | Rate Limit | Backoff |
| -1007 | Timeout | System Error | Retry |
| -1015 | Too many new orders | Rate Limit | Backoff |
| -1021 | Timestamp outside recvWindow | recvWindow | Sync & Retry |
| -2011 | Unknown order | Ignorable | Continue |
| -2013 | Order doesn't exist | Retryable | Retry |
| -2015 | Invalid API key/IP/permissions | Ambiguous | Investigate |
| -2017 | API keys locked | Account Status | Disable |
| -2018 | Insufficient balance | Balance | Notify |
| -2019 | Insufficient margin | Balance | Notify |
| -2023 | In liquidation | Account Status | Disable |
| -4046 | No need to change margin | Ignorable | Continue |
| -4087 | Reduce-only mode | Account Status | Disable |
| -4088 | No place order permission | Account Status | Disable |
| -5027 | No need to modify order | Ignorable | Continue |
| -5028 | Timestamp outside recvWindow | recvWindow | Sync & Retry |

### Bybit Error Codes
| Code | Meaning | Category | Action |
|------|---------|----------|--------|
| 10001 | Rate limit exceeded | Rate Limit | Backoff |
| 10003 | Invalid API key | Credentials | Disable |
| 10004 | Invalid signature | Credentials | Disable |
| 10005 | Insufficient permissions | Permissions | Disable |
| 10010 | IP not whitelisted | IP Whitelist | Notify |
| 110007 | Insufficient balance | Balance | Notify |
| 110043 | Reduce-only mode | Account Status | Disable |

## Testing

### Unit Tests
**Location**: `tests/Unit/Exceptions/`
- ExceptionParser parsing accuracy
- Error code classification
- Retry time calculation
- Backoff strategies

### Integration Tests
**Location**: `tests/Integration/Exceptions/`
- Full job retry lifecycle
- Notification throttling
- IP ban coordination
- Multi-worker scenarios

### Mocking Exchange Errors
```php
// Mock rate limit response
Http::fake([
    'api.binance.com/*' => Http::response([
        'code' => -1003,
        'msg' => 'Too many requests'
    ], 429, [
        'Retry-After' => '60',
        'X-MBX-USED-WEIGHT-1M' => '1200'
    ]),
]);

// Test job handles rate limit correctly
$job = new SomeApiJob();
$job->handle();

// Assert job was released with correct delay
expect($job->releaseDelay)->toBe(60);
```

## Configuration

### Exception Settings
**Location**: `config/martingalian.php` → `exceptions`

```php
'exceptions' => [
    'max_retries' => 3,
    'backoff_strategy' => 'exponential', // linear, exponential
    'base_backoff_seconds' => 10,
    'max_backoff_seconds' => 300,

    // Notification settings
    'notify_on_retry' => false,
    'notify_on_max_retries' => true,
    'critical_error_codes' => [-2023, -4087, 10005], // Account status issues
],
```

## Future Enhancements
- Circuit breaker pattern for failing APIs
- Automatic API credential rotation
- Error pattern detection (ML-based)
- Distributed rate limit tracking (Redis-based)
- Exception aggregation and analytics
- Automatic retry strategy optimization
