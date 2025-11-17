# Notification System - Current Implementation

**Last Updated**: 2025-11-16
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
    User $user,                    // Real or virtual admin user
    string $canonical,             // Notification template identifier
    array $referenceData = [],     // Data for template interpolation
    ?object $relatable = null,     // Context model (Account, ApiSystem, etc.)
    ?int $duration = null,         // Throttle duration override
    ?string $cacheKey = null       // Literal cache key for cache-based throttling
): bool
```

**Returns**:
- `true` if notification was sent
- `false` if throttled or blocked

**Throttling Logic**:
- `$duration = null` → Use default from `notifications` table
- `$duration = 0` → No throttling (always send)
- `$duration > 0` → Custom throttle window in seconds
- If `$cacheKey` provided → Use cache-based throttling
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
1. Uses LITERAL cache key provided (no building/parsing)
2. Checks if key exists in Redis
3. Blocks if exists, otherwise sends
4. Sets cache key with TTL after successful send

**Redis Key Structure**:
```
{laravel_prefix}{your_literal_key}

Example:
cacheKey = "my_test_key"
→ Redis: martingalian_database_my_test_key
```

**Code**:
```php
if ($cacheKey) {
    if (Cache::has($cacheKey)) {
        return false; // Throttled
    }
}

// After sending...
if ($cacheKey && $throttleDuration) {
    Cache::put($cacheKey, true, $throttleDuration);
}
```

**Redis Configuration**:
- Database: 1 (`database.redis.cache.database`)
- Prefix: `martingalian_database_`
- Value: `b:1;` (PHP serialized boolean)

**Use Case**: Custom throttling keys for specific scenarios (e.g., per-exchange, per-operation)

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
    canonical: 'api_rate_limit_exceeded',
    referenceData: [
        'exchange' => 'binance',
        'ip' => '127.0.0.1',
    ],
    relatable: $account  // Context: which account triggered this
);
```

### Admin Notification with Cache Throttling
```php
NotificationService::send(
    user: Martingalian::admin(),
    canonical: 'system_error',
    referenceData: ['error' => 'Connection failed'],
    cacheKey: 'system_error_binance'  // Literal cache key
);
```

### User Notification
```php
NotificationService::send(
    user: $user,
    canonical: 'ip_not_whitelisted',
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
php artisan test:notification --canonical=api_rate_limit_exceeded

# Admin notification with cache throttling
php artisan test:notification --canonical=api_rate_limit_exceeded --cache_key=my_key

# Bypass throttling
php artisan test:notification --canonical=api_rate_limit_exceeded --duration=0

# Clean logs
php artisan test:notification --clean

# User notification
php artisan test:notification --account_id=1 --canonical=ip_not_whitelisted
```

---

## Key Changes from Previous Implementation

### ❌ **REMOVED**:
1. `Throttler` class and deprecated `throttle_logs` database table
2. `NotificationMessageBuilder` complexity
3. `buildThrottleCacheKey()` method (component-based key building)
4. Separate `_admin` suffix conventions

### ✅ **NEW**:
1. Unified `NotificationService::send()` with simpler signature
2. Database throttling uses `notification_logs` (dual purpose: audit + throttle)
3. Cache throttling uses LITERAL cache keys (no building)
4. `user_id` vs `relatable` separation in notification_logs
5. Dynamic property detection using `isset()` instead of `property_exists()`
6. Streamlined test command

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
