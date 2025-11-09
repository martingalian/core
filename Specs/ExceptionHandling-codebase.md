# Exception Handling - Codebase Examples

Real-world exception handling patterns from production code.

## Table of Contents

1. [Exception Flow in BaseApiableJob](#exception-flow-in-baseapiablejob)
2. [BinanceExceptionHandler Configuration](#binanceexceptionhandler-configuration)
3. [Exception Classification Methods](#exception-classification-methods)
4. [Rate Limit Handling](#rate-limit-handling)
5. [Forbidden Hostname Tracking](#forbidden-hostname-tracking)
6. [Retry Logic](#retry-logic)

---

## Exception Flow in BaseApiableJob

### Main Exception Catch

**From: BaseApiableJob (line 85)**

```php
try {
    // Execute the API-able job logic
    $this->executeJobLogic();
} catch (Throwable $e) {
    // Let the API-specific exception handler deal with the error
    $this->handleApiException($e);
}
```

**Pattern:**
- All API exceptions caught at top level
- Delegated to `handleApiException()` for classification
- Exception handler determines: retry, fail, or ignore

---

## BinanceExceptionHandler Configuration

**Location**: `packages/martingalian/core/src/Support/ApiExceptionHandlers/BinanceExceptionHandler.php`

### Error Code Classifications

```php
final class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    /**
     * Ignorable — no-ops / idempotent operations
     */
    public $ignorableHttpCodes = [
        400 => [-4046, -5027, -2011],
    ];
    // -4046: No need to change margin type
    // -5027: No need to modify the order
    // -2011: Unknown order sent

    /**
     * Retryable — transient conditions
     */
    public $retryableHttpCodes = [
        503,  // Service unavailable
        504,  // Gateway timeout
        409,  // Conflict
        400 => [-1021, -5028, -2013],
        408 => [-1007],
        -2013,
    ];
    // -1021 / -5028: recvWindow/timestamp mismatch
    // -2013: Order doesn't exist (eventual consistency)

    /**
     * Rate-limited codes
     */
    public array $rateLimitedHttpCodes = [
        429,  // Too Many Requests
        418,  // IP ban escalation (temporary)
    ];

    /**
     * recvWindow mismatches
     */
    public $recvWindowMismatchedHttpCodes = [
        400 => [-1021, -5028],
    ];

    /**
     * Ambiguous credential/IP/permission errors
     * -2015 REJECTED_MBX_KEY
     */
    protected array $credentialsOrIpCodes = [
        -2015,
    ];

    /**
     * Account status errors - critical
     */
    protected array $accountStatusCodes = [
        -2017,   // API keys locked
        -2023,   // User in liquidation
        -4087,   // Reduce-only order permission
        -4088,   // No place order permission
        -4400,   // Trading quantitative rule
        -1002,   // Unauthorized
    ];

    /**
     * Balance/margin insufficiency
     */
    protected array $insufficientBalanceCodes = [
        -2018,   // Balance not sufficient
        -2019,   // Margin not sufficient
        -4164,   // Insufficient margin
    ];
}
```

**Usage:**
- These arrays drive exception classification
- Used by trait methods: `isRateLimited()`, `isForbidden()`, `retryException()`
- Exchange-specific codes in child classes

---

## Exception Classification Methods

**From: ApiExceptionHelpers trait**

### Basic Classifiers

```php
public function isRecvWindowMismatch(Throwable $exception): bool
{
    return $this->containsHttpExceptionIn($exception, $this->recvWindowMismatchedHttpCodes);
}

public function isRateLimited(Throwable $exception): bool
{
    return $this->containsHttpExceptionIn($exception, $this->rateLimitedHttpCodes);
}

public function isForbidden(Throwable $exception): bool
{
    return $this->containsHttpExceptionIn($exception, $this->forbiddenHttpCodes);
}

public function retryException(Throwable $exception): bool
{
    return $this->containsHttpExceptionIn($exception, $this->retryableHttpCodes);
}

public function ignoreException(Throwable $exception): bool
{
    return $this->containsHttpExceptionIn($exception, $this->ignorableHttpCodes);
}
```

### Usage Example

```php
try {
    $apiClient->placeOrder($order);
} catch (Throwable $e) {
    $handler = BaseExceptionHandler::make('binance');

    if ($handler->isRateLimited($e)) {
        $retryAt = $handler->rateLimitUntil($e);
        $this->release($retryAt->diffInSeconds());
        return;
    }

    if ($handler->isForbidden($e)) {
        $handler->forbid();
        $this->fail($e);
        return;
    }

    if ($handler->retryException($e)) {
        $this->release(10);
        return;
    }

    if ($handler->ignoreException($e)) {
        // No-op, job succeeds
        return;
    }

    // Unknown error - fail permanently
    $this->fail($e);
}
```

---

## Rate Limit Handling

### rateLimitUntil() Method

**From: ApiExceptionHelpers (line 122)**

```php
public function rateLimitUntil(RequestException $exception): Carbon
{
    $now = Carbon::now();

    if (!$exception->hasResponse()) {
        return $now->copy()->addSeconds($this->backoffSeconds);
    }

    $meta = $this->extractHttpMeta($exception);
    $retryAfter = mb_trim((string) ($meta['retry_after'] ?? ''));

    if ($retryAfter !== '') {
        // Numeric seconds
        if (is_numeric($retryAfter)) {
            return $now->copy()->addSeconds((int) $retryAfter + random_int(2, 6));
        }

        // RFC date format
        try {
            $parsed = Carbon::parse($retryAfter);
            return $parsed->isPast()
                ? $now->copy()->addSeconds($this->backoffSeconds)
                : $parsed;
        } catch (Throwable) {
            // fall through
        }
    }

    // Base fallback + light jitter
    return $now->copy()->addSeconds($this->backoffSeconds + random_int(1, 5));
}
```

**Key Features:**
- **Respects Retry-After header** (seconds or RFC date)
- **Adds jitter** (2-6 seconds) to prevent thundering herd
- **Fallback** to default backoff (10 seconds default)
- **Past timestamps** fallback to base backoff

### Usage in Job

```php
if ($handler->isRateLimited($e)) {
    $retryAt = $handler->rateLimitUntil($e);
    $this->release($retryAt->diffInSeconds());
    return;
}
```

---

## Forbidden Hostname Tracking

### forbid() Method

**From: ApiExceptionHelpers (line 49)**

```php
public function forbid(): void
{
    $apiSystem = \Martingalian\Core\Models\ApiSystem::where('canonical', $this->getApiSystem())
        ->firstOrFail();

    // Determine account_id:
    // - Admin accounts (transient, id is NULL) → save as NULL (system-wide ban)
    // - User accounts (real, id has value) → save real ID (account-specific ban)
    $accountId = $this->account->id;

    $record = ForbiddenHostname::updateOrCreate(
        [
            'api_system_id' => $apiSystem->id,
            'account_id' => $accountId,
            'ip_address' => Martingalian::ip(),
        ],
        [
            'updated_at' => now(),
        ]
    );

    info("----- HOSTNAME WAS FORBIDDEN: {$record->ip_address}");

    // Only send notification if this is a NEW forbidden hostname
    if ($record->wasRecentlyCreated) {
        $hostname = gethostname();
        $exchange = $this->getApiSystem();
        $exchangeName = ucfirst($exchange);
        $accountInfo = $this->account->user
            ? "User: {$this->account->user->name}"
            : 'Account: Admin';

        $throttleCanonical = $exchange.'_forbidden_hostname_added';

        Throttler::using(NotificationService::class)
            ->withCanonical($throttleCanonical)
            ->execute(function () use ($exchangeName, $hostname, $record, $accountInfo) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "A hostname has been forbidden from accessing {$exchangeName} API.\n\n".
                        "Hostname: {$hostname}\n".
                        "IP Address: {$record->ip_address}\n".
                        "Exchange: {$exchangeName}\n".
                        "{$accountInfo}\n".
                        'Time: '.now()->toDateTimeString(),
                    title: 'Forbidden Hostname Detected',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
```

**Key Features:**
- **Upsert pattern**: `updateOrCreate()` prevents duplicates
- **Account scoping**: NULL for system-wide, ID for user-specific
- **Notification**: Only on first detection (`wasRecentlyCreated`)
- **Throttled**: Prevents spam if multiple jobs fail simultaneously

### Usage

```php
if ($handler->isForbidden($e)) {
    $handler->forbid();  // Record in DB, notify admin
    $this->fail($e);     // Fail job permanently
    return;
}
```

---

## Retry Logic

### Pre-flight Safety Check

**From: BaseApiableJob (line 103)**

```php
protected function shouldStartOrThrottle(): bool
{
    // Ensure exception handler is assigned
    if (!isset($this->exceptionHandler)) {
        $this->assignExceptionHandler();
    }

    // First check exception handler's pre-flight safety check
    if (isset($this->exceptionHandler) && !$this->exceptionHandler->isSafeToMakeRequest()) {
        // Throttler says not safe - release job for later
        $this->release(60);
        return false;
    }

    // ... rest of throttling logic
}
```

**Pattern:**
- Called BEFORE API request
- Prevents API calls during IP bans
- Delegates to throttler for safety check
- Releases job if not safe

### Exception Handling Pattern

```php
try {
    $result = $apiClient->makeRequest();
} catch (Throwable $e) {
    $handler = BaseExceptionHandler::make('binance');

    // 1. Check if ignorable (no-op)
    if ($handler->ignoreException($e)) {
        return;  // Success, no retry
    }

    // 2. Check if rate limited
    if ($handler->isRateLimited($e)) {
        $retryAt = $handler->rateLimitUntil($e);
        $this->release($retryAt->diffInSeconds());
        return;
    }

    // 3. Check if retryable (transient error)
    if ($handler->retryException($e)) {
        $this->release(10);  // Fixed backoff
        return;
    }

    // 4. Check if forbidden (permanent ban)
    if ($handler->isForbidden($e)) {
        $handler->forbid();
        $this->fail($e);
        return;
    }

    // 5. Check if recvWindow mismatch (clock sync)
    if ($handler->isRecvWindowMismatch($e)) {
        // Sync clock and retry
        $this->release(5);
        return;
    }

    // 6. Unknown error - fail permanently
    $this->fail($e);
}
```

---

## Factory Pattern

### Creating Exception Handlers

```php
// Basic usage
$handler = BaseExceptionHandler::make('binance');
// Returns BinanceExceptionHandler instance

$handler = BaseExceptionHandler::make('bybit');
// Returns BybitExceptionHandler instance

// With account for per-account rate limiting
$handler = BaseExceptionHandler::make('binance')->withAccount($account);
```

**Why withAccount()?**
- Binance has per-account ORDER limits (not just IP limits)
- Account ID needed for ORDER throttler tracking
- IP limits don't need account context

---

## Error Code Extraction

### From HTTP Response

```php
// Binance error response:
{
  "code": -1003,
  "msg": "Too many requests"
}

// Extract with ExceptionParser
$parser = ExceptionParser::with($exception);
$httpCode = $parser->httpStatusCode();  // 429
$vendorCode = $parser->errorCode();     // -1003
$message = $parser->errorMsg();         // "Too many requests"
```

### containsHttpExceptionIn() Helper

**Pattern used internally:**

```php
protected function containsHttpExceptionIn(Throwable $e, array $codes): bool
{
    if (!$e instanceof RequestException || !$e->hasResponse()) {
        return false;
    }

    $meta = $this->extractHttpMeta($e);
    $httpCode = $meta['http_code'] ?? 0;
    $vendorCode = $meta['error_code'] ?? null;

    // Check HTTP code
    if (in_array($httpCode, $codes, true)) {
        return true;
    }

    // Check vendor code under HTTP code
    if (isset($codes[$httpCode]) && is_array($codes[$httpCode])) {
        if (in_array($vendorCode, $codes[$httpCode], true)) {
            return true;
        }
    }

    return false;
}
```

**Usage:**
- Checks both HTTP code (429) AND vendor code (-1003)
- Flexible: `[429]` or `[400 => [-1021, -5028]]`

---

## Summary

**Standard Exception Flow:**
1. Try API request
2. Catch exception
3. Classify: ignorable / rate-limited / retryable / forbidden
4. Act: succeed / retry / fail / forbid

**Key Files:**
- `BaseApiableJob.php` - Top-level exception catching
- `BinanceExceptionHandler.php` - Binance-specific codes
- `BybitExceptionHandler.php` - Bybit-specific codes
- `ApiExceptionHelpers.php` - Generic classification trait

**Classification Arrays:**
- `$ignorableHttpCodes` - No-op, job succeeds
- `$retryableHttpCodes` - Transient, retry with backoff
- `$rateLimitedHttpCodes` - Rate limited, respect Retry-After
- `$forbiddenHttpCodes` - Permanent ban, record in DB
- `$recvWindowMismatchedHttpCodes` - Clock sync issue, retry

**Never:**
- Hardcode exception handling in jobs
- Ignore rate limit headers
- Retry forbidden errors
- Skip jitter on retries
