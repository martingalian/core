# Notification System - Current Implementation

**Last Updated**: 2025-11-21
**Status**: Production

---

## Overview

The Martingalian notification system is a unified notification framework built on Laravel's notification system with dual throttling mechanisms (database and cache-based), support for real users and virtual admin, and comprehensive audit logging.

---

## Core Components

### 1. NotificationService
**Location**: `packages/martingalian/core/src/Support/NotificationService.php`

**Primary Method**:
```php
public static function send(
    User $user,                           // Real or virtual admin user
    string $canonical,                    // Notification template identifier
    array $referenceData = [],            // Data for template interpolation
    ?object $relatable = null,            // Context model (Account, ApiSystem, etc.)
    ?int $duration = null,                // Throttle duration override
    ?array $cacheKey = null               // Cache key data array for cache-based throttling
): bool
```

**Returns**:
- `true` if notification was sent
- `false` if throttled or blocked

**Throttling Logic**:
- `$duration = null` → Use default from `notifications` table
- `$duration = 0` → No throttling (always send)
- `$duration > 0` → Custom throttle window in seconds
- If `$cacheKey` provided → Use cache-based throttling (builds key from array + template)
- If `$cacheKey` is null → Use database-based throttling

---

### 2. NotificationLogListener
**Location**: `packages/martingalian/core/src/Listeners/NotificationLogListener.php`

**Purpose**: Listens to Laravel's `NotificationSent` and `NotificationFailed` events to create audit trail entries.

**Key Methods**:
- `handleSent(NotificationSent $event)` - Logs successful notifications
- `handleFailed(NotificationFailed $event)` - Logs failed notifications
- `createLog()` - Creates notification_logs entry
- `extractUserId()` - Extracts user ID (null for admin virtual user)
- `extractRelatable()` - Extracts context model (NOT the user)

**Critical Detail**: Uses `isset()` instead of `property_exists()` to detect dynamically added `relatable` property:

```php
// CORRECT - detects dynamic properties
if (isset($notifiable->relatable) && is_object($notifiable->relatable)) {
    $relatable = $notifiable->relatable;
}
```

---

### 3. NotificationLog Model
**Location**: `packages/martingalian/core/src/Models/NotificationLog.php`

**Purpose**: Legal audit trail for ALL notifications sent through the platform.

**Key Columns**:
- `user_id` (nullable) - WHO received the notification (null for admin virtual user)
- `relatable_type` / `relatable_id` - WHAT the notification is about (Account, ApiSystem, etc.) - NOT the user
- `canonical` - Notification template identifier
- `channel` - Delivery channel (mail, pushover)
- `recipient` - Email or Pushover key
- `sent_at` - When notification was sent
- `status` - delivered, failed

**Critical Separation**:
```
user_id = WHO received the notification
relatable = WHAT it's about (context)
```

---

## Throttling Mechanisms

### Database-Based Throttling (Default)

**When**: No `$cacheKey` parameter provided

**How It Works**:
1. Queries `notification_logs` table
2. Looks for last notification with same:
   - `canonical`
   - `relatable_type`
   - `relatable_id`
3. Checks if within throttle window
4. Blocks if within window, otherwise sends

**Code**:
```php
$throttleRelatable = $relatable ?? $user;

$lastNotification = NotificationLog::query()
    ->where('canonical', $canonical)
    ->where('relatable_type', get_class($throttleRelatable))
    ->where('relatable_id', $throttleRelatable->id)
    ->orderBy('created_at', 'desc')
    ->first();

if ($lastNotification && $lastNotification->created_at->isAfter($throttleWindow)) {
    return false; // Throttled
}
```

**Use Case**: Throttling based on context (e.g., one notification per Account per canonical per time window)

---

### Cache-Based Throttling

**When**: `$cacheKey` parameter provided

**How It Works**:
1. Takes `$cacheKey` array and builds string using template from `notifications` table
2. Uses atomic `Cache::add()` operation (SETNX in Redis) for race condition prevention
3. If key doesn't exist → Sets key and sends notification
4. If key exists → Returns false (throttled)

**Cache Key Building**:
```php
// Input parameters
$canonical = 'server_rate_limit_exceeded';
$cacheKey = ['api_system' => 'binance', 'account' => 1];

// Template from notifications.cache_key column
$template = ['api_system', 'account'];

// Built cache key format: {canonical}-{key1}:{value1},{key2}:{value2}
// Result: "server_rate_limit_exceeded-api_system:binance,account:1"
```

**Redis Key Structure**:
```
{laravel_prefix}{built_cache_key}

Example:
cacheKey = ['api_system' => 'binance']
template = ['api_system']
→ Built: "server_rate_limit_exceeded-api_system:binance"
→ Redis: "martingalian_database_server_rate_limit_exceeded-api_system:binance"
```

**Code Flow**:
```php
// 1. Build cache key from array + template
$builtCacheKey = self::buildCacheKey($canonical, $cacheKey, $notification->cache_key);
// Result: "server_rate_limit_exceeded-api_system:binance,account:1"

// 2. Atomic check-and-set (prevents race conditions across workers)
if (!Cache::add($builtCacheKey, true, $throttleDuration)) {
    return false; // Key already exists - throttled
}

// 3. Key was set successfully - continue to send notification
// Cache::add() returns true only if key didn't exist (atomic SETNX)
```

**Race Condition Prevention**:
- Uses `Cache::add()` (atomic SETNX operation in Redis)
- Returns `true` only if key was successfully created
- Returns `false` if key already exists (another worker got there first)
- Prevents duplicate notifications across multiple worker servers

**Redis Configuration**:
- Database: 1 (`database.redis.cache.database`)
- Prefix: `martingalian_database_`
- Value: `b:1;` (PHP serialized boolean)
- Operation: Atomic SETNX via `Cache::add()`

**Use Case**: Multi-component cache keys for fine-grained throttling (per-exchange AND per-account scenarios)

**Template Validation**:
- If required keys are missing from `$cacheKey` array, throws `InvalidArgumentException`
- Template is stored in `notifications.cache_key` column as JSON array
- Must provide all keys specified in template

---

## Virtual Admin User

**Access**: `Martingalian::admin()`

**Implementation**: Returns a non-persisted User instance with:
```php
$user->exists = false;
$user->is_virtual = true;
$user->email = <from martingalians table>
$user->pushover_key = <from martingalians table>
```

**Important**: Admin notifications have `user_id = NULL` in notification_logs.

---

## Passing Relatable Context

The `relatable` parameter is attached to the User object dynamically:

```php
// In NotificationService::send()
if ($relatable) {
    $user->relatable = $relatable;  // Dynamic property
}

$user->notify(new AlertNotification(...));
```

The listener extracts it using `isset()`:

```php
// In NotificationLogListener::extractRelatable()
if (isset($notifiable->relatable) && is_object($notifiable->relatable)) {
    return [$relatable->getMorphClass(), $relatable->getKey()];
}
```

---

## Usage Examples

### Admin Notification with Database Throttling
```php
NotificationService::send(
    user: Martingalian::admin(),
    canonical: 'server_rate_limit_exceeded',
    referenceData: [
        'exchange' => 'binance',
        'ip' => '127.0.0.1',
    ],
    relatable: $account  // Context: which account triggered this
);
```

### Admin Notification with Cache Throttling
```php
// Requires: notifications.cache_key = ['api_system'] for this canonical
NotificationService::send(
    user: Martingalian::admin(),
    canonical: 'system_error',
    referenceData: ['error' => 'Connection failed'],
    cacheKey: ['api_system' => 'binance']  // Cache key data array
);
// Builds: "system_error-api_system:binance"
```

### User Notification
```php
NotificationService::send(
    user: $user,
    canonical: 'websocket_error',
    referenceData: [
        'exchange' => 'binance',
        'ip' => Martingalian::ip(),
    ],
    relatable: $account
);
```

### Bypass Throttling
```php
NotificationService::send(
    user: Martingalian::admin(),
    canonical: 'urgent_alert',
    referenceData: ['message' => 'Critical issue'],
    duration: 0  // No throttle - always send
);
```

---

## Real-World Implementation Example: WebSocket Invalid JSON Detection

### Overview
The `websocket_invalid_json` notification demonstrates a production pattern for error threshold detection with cache-based counting and notification throttling.

### Pattern: Cache-Based Error Threshold

**Location**: `app/Console/Commands/Cronjobs/{Binance,Bybit}/UpdatePricesCommand.php`

**Problem**: WebSocket streams occasionally receive malformed JSON. Occasional errors are normal (network blips, API hiccups), but persistent errors indicate a problem requiring intervention.

**Solution**: Cache-based hit counter with threshold (3 hits per 60 seconds triggers notification).

**Implementation**:
```php
protected function processWebSocketMessage(string $msg): void
{
    $decoded = json_decode($msg, true);
    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
        $cacheKey = 'binance_invalid_json_hits';  // or 'bybit_invalid_json_hits'

        // Increment hit counter (with 60 second TTL)
        $hits = Cache::get($cacheKey, 0) + 1;
        Cache::put($cacheKey, $hits, 60);

        // Notify if threshold reached (3+ hits per minute)
        if ($hits >= 3) {
            $binanceSystem = ApiSystem::firstWhere('canonical', 'binance');
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'websocket_invalid_json',
                referenceData: [
                    'exchange' => 'BINANCE',  // or 'BYBIT'
                    'hits' => $hits,
                ],
                relatable: $binanceSystem,
                duration: 60,
                cacheKey: ['api_system' => 'binance']  // or ['api_system' => 'bybit']
            );
        }

        return;
    }

    // ... normal message processing
}
```

### Key Design Decisions

**1. Exchange-Agnostic Notification**
- Single `websocket_invalid_json` canonical (not separate per exchange)
- Exchange identified via `referenceData['exchange']`
- Relatable is the `ApiSystem` model instance
- Pattern enables reuse across Binance, Bybit, and future exchanges

**2. Dual Caching Strategy**
- **Error Counter Cache**: `{exchange}_invalid_json_hits` (60s TTL, separate per exchange)
- **Notification Throttle Cache**: Built from `['api_system' => '{exchange}']` → `"websocket_invalid_json-api_system:{exchange}"` (60s TTL, separate per exchange)

**3. Self-Healing**
- Errors 1-2 within 60s: Silently discarded, counter expires naturally
- Error 3+ within 60s: Notification triggered once (throttled for 60s)
- After 60s of no errors: Counter resets, notification can fire again

**4. Message Context**
The notification message includes the hit count for debugging:
```php
referenceData: [
    'exchange' => 'BINANCE',
    'hits' => 5,  // Actual count when threshold exceeded
]
```

This renders as: "Received 5 invalid JSON payload(s) in less than 60 seconds from Binance WebSocket stream."

### Cache Keys Explained

| Purpose | Cache Key Input | Built Cache Key | TTL | Scope |
|---------|----------------|-----------------|-----|-------|
| Error counting | N/A (manual) | `binance_invalid_json_hits` | 60s | Per exchange |
| Notification throttle | `['api_system' => 'binance']` | `websocket_invalid_json-api_system:binance` | 60s | Per exchange |

**Why separate keys?**
- Error counter tracks ALL invalid JSON occurrences (manual counter, not via NotificationService)
- Notification throttle prevents spam after threshold reached (built by NotificationService)
- Both expire independently, allowing fresh detection cycles

### Related Patterns

This pattern is reusable for any threshold-based error detection:
- Database connection failures (3+ per minute)
- API rate limit hits (5+ per minute)
- Authentication failures (10+ per hour)

**Template**:
1. Cache counter with TTL matching detection window
2. Check threshold before notifying
3. Pass hit count in referenceData for context
4. Use NotificationService throttling to prevent spam
5. Self-healing via cache expiration

---

## Database Schema

### notification_logs Table
```sql
CREATE TABLE notification_logs (
    id BIGINT UNSIGNED PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE,
    notification_id BIGINT UNSIGNED NULL,  -- FK to notifications
    canonical VARCHAR(255),                -- Template identifier
    user_id BIGINT UNSIGNED NULL,          -- WHO received (null for admin)
    relatable_type VARCHAR(255) NULL,      -- WHAT it's about
    relatable_id BIGINT UNSIGNED NULL,
    channel VARCHAR(255),                  -- mail, pushover
    recipient VARCHAR(255),                -- Email or Pushover key
    message_id VARCHAR(255) NULL,
    sent_at TIMESTAMP,
    status VARCHAR(255),                   -- delivered, failed
    -- Additional audit fields...
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    INDEX idx_canonical (canonical),
    INDEX idx_user_id (user_id),
    INDEX idx_relatable (relatable_type, relatable_id)
);
```

---

## Testing Command

### Command: `test:notification`
**Location**: `app/Console/Commands/Tests/TestNotificationCommand.php`

**Usage**:
```bash
# Admin notification with database throttling
php artisan test:notification --canonical=server_rate_limit_exceeded

# Admin notification with cache throttling
php artisan test:notification --canonical=server_rate_limit_exceeded --cache_key=my_key

# Bypass throttling
php artisan test:notification --canonical=server_rate_limit_exceeded --duration=0

# Clean logs
php artisan test:notification --clean

# User notification
php artisan test:notification --account_id=1 --canonical=websocket_error
```

---

## Key Changes from Previous Implementation

### ❌ **REMOVED**:
1. `Throttler` class and deprecated `throttle_logs` database table
2. Separate `_admin` suffix conventions

### ✅ **NEW**:
1. Unified `NotificationService::send()` with simpler signature
2. Database throttling uses `notification_logs` (dual purpose: audit + throttle)
3. Cache throttling uses **array-based cache keys** with template building from `notifications.cache_key` column
4. `user_id` vs `relatable` separation in notification_logs
5. Dynamic property detection using `isset()` instead of `property_exists()`
6. Streamlined test command
7. `buildCacheKey()` method constructs cache keys from array data + JSON template
8. Atomic `Cache::add()` for race condition prevention across multiple workers

---

## Common Issues & Solutions

### Issue: Relatable Not Saved
**Check**: Is the relatable passed to `NotificationService::send()`?
**Solution**: Always pass context model as `relatable` parameter

### Issue: user_id Not NULL for Admin
**Check**: Using `Martingalian::admin()` for user parameter?
**Solution**: Admin must use virtual user, not a real User instance

### Issue: Cache Throttling Not Working
**Check**: Redis database and prefix configuration
**Verify**: `redis-cli -n 1 KEYS "martingalian_database_*"`

### Issue: Database Throttling Not Working
**Check**: Is same `relatable` being passed?
**Debug**: Query `notification_logs` for matching canonical + relatable

---

## Configuration

**Redis Cache**:
- Driver: `redis` (`cache.default`)
- Connection: `cache` (`cache.stores.redis.connection`)
- Database: `1` (`database.redis.cache.database`)
- Prefix: `martingalian_database_`

**Notifications Table**:
- Default throttle duration: Configured per canonical
- Stored in `default_throttle_duration` column

---

## Related Files

**Core**:
- `packages/martingalian/core/src/Support/NotificationService.php`
- `packages/martingalian/core/src/Listeners/NotificationLogListener.php`
- `packages/martingalian/core/src/Models/NotificationLog.php`
- `packages/martingalian/core/src/Models/Notification.php`

**Testing**:
- `app/Console/Commands/Tests/TestNotificationCommand.php`
- `tests/Feature/Console/Commands/TestNotificationCommandTest.php`

**Migration**:
- `packages/martingalian/core/database/migrations/2024_11_26_000000_create_martingalian_complete_schema.php`

---

*For complete implementation details, see `/home/bruno/ingestion/REMEMBER.md`*
