# Notification Throttling Flow Analysis
*Comprehensive analysis of how notifications are throttled in the Martingalian system*

---

## Overview

The notification throttling system prevents notification spam by enforcing time-based limits on notification delivery. It uses a **database-driven, lock-based approach** with polymorphic context support for per-user or per-account throttling.

---

## Architecture Components

### 1. **Entry Point: ApiRequestLogObserver**
**Location**: `packages/martingalian/core/src/Observers/ApiRequestLogObserver.php`

```php
public function saved(ApiRequestLog $log): void
{
    // Delegate to the model's notification logic
    $log->sendNotificationIfNeeded();

    // ... other logic
}
```

**Trigger**: Every time an `ApiRequestLog` is saved (after API request completes)

**Responsibility**: Delegates to `SendsNotifications` trait for notification logic

---

### 2. **Business Logic: SendsNotifications Trait**
**Location**: `packages/martingalian/core/src/Concerns/ApiRequestLog/SendsNotifications.php`

**Flow**:
```
ApiRequestLog (saved)
    ↓
sendNotificationIfNeeded()
    ↓
├─ HTTP code < 400? → Skip (success)
├─ HTTP code ≥ 400? → Analyze error
    ↓
    ├─ Has account_id? → sendUserNotification()
    └─ No account_id? → sendAdminNotification()
```

#### Key Decision Points in `sendUserNotification()`:

| HTTP Code / Vendor Code | Canonical | Disables Account? | Creates Repeater? |
|------------------------|-----------|-------------------|-------------------|
| 429/418/403 (rate limit) | `api_rate_limit_exceeded` | ❌ No | ❌ No |
| Binance -2015 | `invalid_api_credentials` | ✅ Yes | ❌ No |
| Bybit 10003 | `invalid_api_key` | ✅ Yes | ❌ No |
| Bybit 10004 | `invalid_signature` | ✅ Yes | ❌ No |
| Bybit 10005 | `insufficient_permissions` | ✅ Yes | ❌ No |
| Bybit 10010 | `ip_not_whitelisted` | ❌ No | ✅ Yes (IP whitelist retry) |
| 403/401 forbidden | `api_access_denied` | ❌ No | ❌ No |
| 401 (generic) | `api_access_denied` | ✅ Yes | ❌ No |
| Account status errors | Various (liquidation, banned, etc.) | ✅ Yes | ❌ No |
| Insufficient balance | `insufficient_balance_margin` | ❌ No | ❌ No |
| KYC required | `kyc_verification_required` | ❌ No | ❌ No |
| System errors | `api_system_error` | ❌ No | ❌ No |
| Network errors | `api_network_error` | ❌ No | ❌ No |
| 503/504 server overload | `exchange_maintenance` | ❌ No | ❌ No |
| Connection failure | `api_connection_failed` | ❌ No | ❌ No |

---

### 3. **Throttling Orchestrator: sendThrottledNotification()**
**Location**: `SendsNotifications` trait, line 696-822

This is the **core orchestration method** that handles throttling logic for both user and admin notifications.

#### Key Architectural Decisions:

**A. User Types Routing**
```php
$notification = Notification::findByCanonical($messageCanonical);
$userTypes = $notification->user_types ?? ['user'];
$shouldSendToUser = in_array('user', $userTypes, true);
$shouldSendToAdmin = in_array('admin', $userTypes, true);
```

**B. Throttle Canonical Segregation**
```php
// Base throttle key (prefixed with API system)
$throttleCanonical = $handler->getApiSystem().'_'.$messageCanonical;
// e.g., "binance_api_rate_limit_exceeded"

// User notification uses base key
Throttler::using(NotificationService::class)
    ->withCanonical($throttleCanonical)
    ->for($user)  // ← Per-user throttling
    ->execute(...)

// Admin notification uses separate key with "_admin" suffix
Throttler::using(NotificationService::class)
    ->withCanonical($throttleCanonical.'_admin')  // ← Separate throttle window
    ->execute(...)  // ← No ->for() = global throttle
```

**Why separate keys?**
- Prevents admin notifications from being throttled when user just received same canonical
- Ensures both recipients get notified when `user_types=['admin', 'user']`
- Admin gets system-wide throttling (one notification per time window globally)
- User gets per-user throttling (one notification per user per time window)

**C. Context Segregation**
```php
$serverRelatedCanonicals = [
    'api_rate_limit_exceeded',
    'api_connection_failed',
    'ip_not_whitelisted',
    // ... etc
];

$isServerRelated = in_array($messageCanonical, $serverRelatedCanonicals, true);

$context = [
    'exchange' => $exchangeCanonical,
    'account_name' => $exchange.' Account #'.$account->id,
];

if ($isServerRelated) {
    $context['ip'] = Martingalian::ip();
    $context['hostname'] = $hostname;
}
```

**D. Account Disabling**
```php
if ($disableAccount) {
    $account->update([
        'can_trade' => false,
        'disabled_reason' => $messageCanonical,
        'disabled_at' => now(),
    ]);
}
```

---

### 4. **Throttler: Unified Throttling System**
**Location**: `packages/martingalian/core/src/Support/Throttler.php`

**Design Pattern**: Fluent API with database-backed throttle rules

```php
Throttler::using(NotificationService::class)
    ->withCanonical('binance_api_rate_limit_exceeded')
    ->for($user)  // Optional: per-user throttling
    ->throttleFor(300)  // Optional: override throttle seconds
    ->execute(function () {
        NotificationService::send(...);
    });
```

#### Core Method: `execute(Closure $callback): bool`

**Returns**:
- `true` = Throttled (callback NOT executed)
- `false` = Not throttled (callback executed)

**Algorithm**:

```php
1. Lookup ThrottleRule by canonical
   ├─ Not found + auto_create=false → Throttle (do not execute)
   └─ Not found + auto_create=true → Create rule with defaults

2. Get throttle_seconds (override or from rule)
   └─ If 0 seconds → Execute immediately without throttle check

3. START TRANSACTION (with pessimistic locking)
   │
   ├─ Find ThrottleLog WHERE canonical AND contextable (lockForUpdate)
   │
   ├─ If no log exists:
   │  └─ Create ThrottleLog with last_executed_at = now()
   │  └─ Return shouldExecute = true
   │
   ├─ If log exists:
   │  └─ Check if log->canExecuteAgain(throttleSeconds)
   │     ├─ Yes → Update last_executed_at = now()
   │     │       Return shouldExecute = true
   │     └─ No → Return shouldExecute = false (throttled)
   │
4. END TRANSACTION

5. IF shouldExecute:
   └─ Execute callback() OUTSIDE transaction
   └─ Return false (not throttled)
   ELSE:
   └─ Return true (throttled)
```

**Critical Design Decision**: Callback executed **OUTSIDE transaction** to prevent deadlocks.

---

### 5. **Database Schema**

#### `throttle_rules` Table
```sql
CREATE TABLE throttle_rules (
    id BIGINT PRIMARY KEY,
    canonical VARCHAR(255) UNIQUE,        -- e.g., "binance_api_rate_limit_exceeded"
    throttle_seconds INT,                 -- Time window in seconds
    description TEXT,                     -- Human-readable description
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Example Data**:
```sql
INSERT INTO throttle_rules (canonical, throttle_seconds, description) VALUES
('binance_api_rate_limit_exceeded', 300, 'Binance rate limit notifications'),
('binance_api_rate_limit_exceeded_admin', 300, 'Binance rate limit (admin)'),
('binance_ip_not_whitelisted', 600, 'IP whitelist notifications'),
('binance_ip_not_whitelisted_admin', 600, 'IP whitelist (admin)'),
('exchange_symbol_no_taapi_data', 0, 'No throttling - immediate notification');
```

#### `throttle_logs` Table
```sql
CREATE TABLE throttle_logs (
    id BIGINT PRIMARY KEY,
    canonical VARCHAR(255),               -- Links to throttle_rules.canonical
    contextable_type VARCHAR(255) NULL,   -- Polymorphic: "App\Models\User"
    contextable_id BIGINT NULL,           -- User/Account ID
    last_executed_at TIMESTAMP,           -- Last successful execution
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    UNIQUE KEY (canonical, contextable_type, contextable_id)
);
```

**Example Data** (after notifications sent):
```sql
-- User notification (per-user throttling)
(canonical: 'binance_api_rate_limit_exceeded', contextable_type: 'App\Models\User', contextable_id: 5, last_executed_at: '2025-01-14 10:30:00')

-- Admin notification (global throttling)
(canonical: 'binance_api_rate_limit_exceeded_admin', contextable_type: NULL, contextable_id: NULL, last_executed_at: '2025-01-14 10:30:00')

-- Another user (independent throttle window)
(canonical: 'binance_api_rate_limit_exceeded', contextable_type: 'App\Models\User', contextable_id: 12, last_executed_at: '2025-01-14 10:35:00')
```

---

## Complete Flow Example: Rate Limit Error

### Scenario
Binance returns HTTP 429 (rate limit exceeded) for Account #5 (User: Alice)

### Step-by-Step Flow

```
1. BinanceApiClient makes request → 429 error
   ↓
2. ApiRequestLog created with:
   - http_response_code: 429
   - account_id: 5
   - api_system_id: 1 (Binance)
   - error_message: "Rate limit exceeded"
   ↓
3. ApiRequestLogObserver::saved() triggered
   ↓
4. SendsNotifications::sendNotificationIfNeeded()
   ├─ http_response_code = 429 → Proceed
   ├─ account_id exists → sendUserNotification()
   ↓
5. sendUserNotification()
   ├─ handler->isRateLimitedFromLog(429) → TRUE
   ├─ Call sendThrottledNotification()
   ↓
6. sendThrottledNotification()
   ├─ Lookup Notification by canonical 'api_rate_limit_exceeded'
   ├─ user_types = ['admin'] (rate limits are operational, not user-actionable)
   ├─ shouldSendToUser = false
   ├─ shouldSendToAdmin = true
   ├─ Build throttle canonical: 'binance_api_rate_limit_exceeded_admin'
   ├─ Build message context (no user-specific data)
   ↓
7. Admin Notification with Throttler
   ↓
   Throttler::using(NotificationService::class)
       ->withCanonical('binance_api_rate_limit_exceeded_admin')
       ->execute(function () {
           NotificationService::send(
               user: Martingalian::admin(),
               message: "Rate limit exceeded...",
               title: "API Rate Limit",
               canonical: 'api_rate_limit_exceeded',
               deliveryGroup: 'default'
           );
       });
   ↓
8. Throttler::execute()
   ├─ Lookup ThrottleRule('binance_api_rate_limit_exceeded_admin')
   │  └─ Found: throttle_seconds = 300
   │
   ├─ START TRANSACTION
   │  ├─ Find ThrottleLog WHERE canonical='binance_api_rate_limit_exceeded_admin' AND contextable IS NULL
   │  │  └─ lockForUpdate() (pessimistic lock)
   │  │
   │  ├─ CASE 1: Log not found (first notification ever)
   │  │  └─ Create log: last_executed_at = 2025-01-14 10:30:00
   │  │  └─ Return shouldExecute = true
   │  │
   │  ├─ CASE 2: Log exists, last_executed_at = 10:25:00 (5 minutes ago)
   │  │  └─ canExecuteAgain(300)? → 10:25:00 + 300s = 10:30:00 (now) → TRUE
   │  │  └─ Update last_executed_at = 10:30:00
   │  │  └─ Return shouldExecute = true
   │  │
   │  └─ CASE 3: Log exists, last_executed_at = 10:27:00 (3 minutes ago)
   │     └─ canExecuteAgain(300)? → 10:27:00 + 300s = 10:32:00 (future) → FALSE
   │     └─ Return shouldExecute = false (THROTTLED)
   │
   └─ END TRANSACTION
   ↓
9. If shouldExecute = true:
   └─ Execute callback: NotificationService::send()
      ↓
      AlertNotification dispatched via Laravel notification system
      ↓
      NotificationSent event fired
      ↓
      NotificationLogListener creates audit log

   If shouldExecute = false:
   └─ Return true (throttled, notification skipped)
```

---

## Special Cases

### Case 1: Zero-Second Throttle (Immediate Execution)
```php
// throttle_rules: canonical='exchange_symbol_no_taapi_data', throttle_seconds=0

Throttler::execute() {
    if ($throttleSeconds === 0) {
        $callback();  // Execute immediately
        return false; // No throttle log created
    }
}
```

**Use case**: Critical notifications that should never be throttled (e.g., symbol deactivation)

---

### Case 2: Per-User vs Global Throttling

**Per-User** (contextable provided):
```php
Throttler::using(NotificationService::class)
    ->withCanonical('binance_api_rate_limit_exceeded')
    ->for($user)  // ← Creates separate log per user
    ->execute(...);
```

**Throttle Log**:
```sql
(canonical: 'binance_api_rate_limit_exceeded', contextable_type: 'App\Models\User', contextable_id: 5)
(canonical: 'binance_api_rate_limit_exceeded', contextable_type: 'App\Models\User', contextable_id: 12)
```
- User #5 can receive notification every 5 minutes
- User #12 can receive notification every 5 minutes
- **Independent windows**

**Global** (no contextable):
```php
Throttler::using(NotificationService::class)
    ->withCanonical('binance_api_rate_limit_exceeded_admin')
    ->execute(...);  // ← No ->for() call
```

**Throttle Log**:
```sql
(canonical: 'binance_api_rate_limit_exceeded_admin', contextable_type: NULL, contextable_id: NULL)
```
- Only ONE notification sent system-wide every 5 minutes
- All admin notifications share this throttle window

---

### Case 3: User + Admin Dual Notifications

**Scenario**: Notification canonical has `user_types = ['user', 'admin']`

```php
// sendThrottledNotification() handles this:

// Send to user (if has specific user)
if ($shouldSendToUser && $hasSpecificUser) {
    Throttler::using(NotificationService::class)
        ->withCanonical($throttleCanonical)  // "binance_invalid_api_key"
        ->for($user)  // Per-user throttle
        ->execute(...);
}

// Send to admin (independent throttle)
if ($shouldSendToAdmin) {
    Throttler::using(NotificationService::class)
        ->withCanonical($throttleCanonical.'_admin')  // "binance_invalid_api_key_admin"
        ->execute(...);  // Global throttle
}
```

**Result**:
- User receives notification (if not throttled for this user)
- Admin receives notification (if not throttled globally)
- **Separate throttle windows ensure both can receive notification**

---

### Case 4: Server-Related vs Account-Related Notifications

**Server-Related** (includes IP/hostname in email subject):
```php
$serverRelatedCanonicals = [
    'api_rate_limit_exceeded',
    'ip_not_whitelisted',
    'api_connection_failed',
    // ...
];

if (in_array($canonical, $serverRelatedCanonicals)) {
    NotificationService::send(
        serverIp: Martingalian::ip(),  // Included in email subject
        exchange: 'Binance'
    );
}
```

**Email Subject**: `"API Rate Limit - Server 1.2.3.4 on Binance"`

**Account-Related** (no server context):
```php
NotificationService::send(
    serverIp: null,  // Not included
    exchange: null
);
```

**Email Subject**: `"Insufficient Balance"`

---

## Race Condition Prevention

### Problem
Multiple concurrent API requests fail simultaneously:
```
Thread 1: ApiRequestLog saved → sendNotificationIfNeeded()
Thread 2: ApiRequestLog saved → sendNotificationIfNeeded()
Thread 3: ApiRequestLog saved → sendNotificationIfNeeded()
```

Without locking, all 3 threads would send notifications.

### Solution: Pessimistic Locking

```php
DB::transaction(function () {
    // 1. Thread 1 acquires lock
    $log = ThrottleLog::where('canonical', 'binance_api_rate_limit_exceeded_admin')
        ->lockForUpdate()  // ← Pessimistic lock
        ->first();

    // Threads 2 & 3 wait here until Thread 1 releases lock

    // 2. Thread 1 creates log or checks timestamp
    if (!$log) {
        ThrottleLog::create(['last_executed_at' => now()]);
        return true;  // Execute
    }

    if ($log->canExecuteAgain(300)) {
        $log->update(['last_executed_at' => now()]);
        return true;  // Execute
    }

    return false;  // Throttled

    // 3. Thread 1 commits transaction, releases lock
});

// 4. Thread 2 acquires lock, sees last_executed_at from Thread 1
//    → Throttled (canExecuteAgain returns false)
// 5. Thread 3 acquires lock, sees last_executed_at from Thread 1
//    → Throttled
```

**Result**: Only Thread 1 sends notification, Threads 2 & 3 are throttled.

---

## Admin Notification Flow (System-Level Errors)

**Scenario**: System-level API call fails (account_id is NULL)

```
1. ApiRequestLog created with:
   - account_id: NULL
   - api_system_id: 1 (Binance)
   - http_response_code: 429
   ↓
2. sendNotificationIfNeeded()
   ├─ account_id is NULL → sendAdminNotification()
   ↓
3. sendAdminNotification()
   ├─ Directly builds notification with admin context
   ├─ Uses exchange-prefixed throttle canonical
   ├─ Sends to Martingalian::admin() (virtual user)
   ↓
4. Throttler with admin-only canonical
   Throttler::using(NotificationService::class)
       ->withCanonical('binance_api_rate_limit_exceeded')  // No "_admin" suffix
       ->execute(...)
   ↓
5. NotificationService::send(user: Martingalian::admin(), ...)
```

**Key Difference**: No user routing logic, admin receives notification directly.

---

## Configuration

### Config: `config/martingalian.php`

```php
'notifications_enabled' => env('NOTIFICATIONS_ENABLED', true),

'auto_create_missing_throttle_rules' => env('AUTO_CREATE_THROTTLE_RULES', true),

'default_throttle_seconds' => env('DEFAULT_THROTTLE_SECONDS', 300),
```

### Seeder: `ThrottleRulesTableSeeder`

**Recommendation**: Create all throttle rules via seeder to avoid auto-creation at runtime.

```php
ThrottleRule::create([
    'canonical' => 'binance_api_rate_limit_exceeded',
    'throttle_seconds' => 300,
    'description' => 'Binance rate limit exceeded notifications (user)',
]);

ThrottleRule::create([
    'canonical' => 'binance_api_rate_limit_exceeded_admin',
    'throttle_seconds' => 300,
    'description' => 'Binance rate limit exceeded notifications (admin)',
]);

// Repeat for all canonicals...
```

---

## Performance Considerations

### Database Load

**Per notification attempt**:
1. SELECT from `throttle_rules` (1 query, indexed by canonical)
2. SELECT from `throttle_logs` with lock (1 query, indexed by canonical + contextable)
3. INSERT or UPDATE `throttle_logs` (1 query)

**Total**: 3 queries per notification attempt (very fast with indexes)

### Locking Duration

- Lock held only during timestamp check/update
- Notification sending happens OUTSIDE transaction (prevents long locks)
- Typical lock duration: < 10ms

### Index Requirements

```sql
-- throttle_rules
CREATE INDEX idx_canonical_active ON throttle_rules(canonical, is_active);

-- throttle_logs
CREATE UNIQUE INDEX idx_canonical_contextable ON throttle_logs(canonical, contextable_type, contextable_id);
CREATE INDEX idx_last_executed ON throttle_logs(last_executed_at);
```

---

## Debugging

### Check Throttle Status

```php
// Check if notification would be throttled
$log = ThrottleLog::where('canonical', 'binance_api_rate_limit_exceeded_admin')
    ->whereNull('contextable_type')
    ->first();

if ($log) {
    $rule = ThrottleRule::findByCanonical('binance_api_rate_limit_exceeded_admin');
    $canExecute = $log->canExecuteAgain($rule->throttle_seconds);
    $nextAllowedAt = $log->last_executed_at->addSeconds($rule->throttle_seconds);

    dump([
        'last_executed' => $log->last_executed_at,
        'next_allowed' => $nextAllowedAt,
        'can_execute_now' => $canExecute,
    ]);
}
```

### Clear Throttle (for testing)

```php
// Clear all throttle logs
ThrottleLog::truncate();

// Clear specific canonical
ThrottleLog::where('canonical', 'binance_api_rate_limit_exceeded_admin')->delete();

// Clear for specific user
ThrottleLog::where('canonical', 'binance_api_rate_limit_exceeded')
    ->where('contextable_type', User::class)
    ->where('contextable_id', 5)
    ->delete();
```

---

## Common Issues & Solutions

### Issue 1: Notifications Not Being Sent

**Symptoms**: No notifications received despite API errors

**Debug Steps**:
```php
// 1. Check if notifications globally enabled
config('martingalian.notifications_enabled')  // Should be true

// 2. Check if user is active
$user->is_active  // Should be true

// 3. Check throttle rule exists
ThrottleRule::findByCanonical('binance_api_rate_limit_exceeded_admin')

// 4. Check throttle log
ThrottleLog::where('canonical', 'binance_api_rate_limit_exceeded_admin')->first()

// 5. Check last execution time
$log->last_executed_at  // If recent, notification is throttled
```

### Issue 2: Too Many Duplicate Notifications

**Cause**: Throttle seconds too low or throttle rule missing

**Solution**:
```sql
-- Increase throttle seconds
UPDATE throttle_rules
SET throttle_seconds = 600
WHERE canonical = 'binance_api_rate_limit_exceeded_admin';

-- Or disable auto-creation
# .env
AUTO_CREATE_THROTTLE_RULES=false
```

### Issue 3: Admin and User Both Receiving Notifications (Unintended)

**Cause**: Notification `user_types` includes both `['user', 'admin']`

**Solution**: Update notification record
```sql
-- Make it admin-only
UPDATE notifications
SET user_types = '["admin"]'
WHERE canonical = 'api_rate_limit_exceeded';
```

### Issue 4: Race Conditions / Duplicate Notifications

**Cause**: Missing unique constraint on `throttle_logs`

**Solution**:
```sql
CREATE UNIQUE INDEX idx_canonical_contextable
ON throttle_logs(canonical, contextable_type, contextable_id);
```

---

## Summary

### Key Architectural Principles

1. **Separation of Concerns**
   - `SendsNotifications`: Business logic (what to notify)
   - `Throttler`: Throttling logic (when to notify)
   - `NotificationService`: Delivery logic (how to notify)

2. **Database-Driven Configuration**
   - Throttle rules stored in database (easily adjustable)
   - Throttle logs track execution history
   - No hardcoded throttle durations

3. **Race Condition Safety**
   - Pessimistic locking prevents duplicate notifications
   - Transaction commits before callback execution
   - Unique constraints enforce one log per context

4. **Flexible Context**
   - Per-user throttling for user notifications
   - Global throttling for admin notifications
   - Polymorphic support for any model

5. **User/Admin Segregation**
   - Separate throttle keys (`_admin` suffix)
   - Independent throttle windows
   - Both can receive notifications without interference

### Best Practices

✅ **DO**:
- Create throttle rules via seeder (not auto-creation)
- Use separate canonicals for admin (`_admin` suffix)
- Set appropriate throttle_seconds (300-600 for most notifications)
- Use `throttleFor(0)` for critical, never-throttle notifications
- Pass `canonical` parameter to all `NotificationService::send()` calls

❌ **DON'T**:
- Use same canonical for user and admin notifications
- Set throttle_seconds too low (< 60 seconds)
- Rely on auto-creation in production
- Execute long-running operations inside `Throttler::execute()` callback
- Manually create `ThrottleLog` entries (let Throttler handle it)

---

## Related Files

- **ApiRequestLogObserver**: `/packages/martingalian/core/src/Observers/ApiRequestLogObserver.php`
- **SendsNotifications Trait**: `/packages/martingalian/core/src/Concerns/ApiRequestLog/SendsNotifications.php`
- **Throttler**: `/packages/martingalian/core/src/Support/Throttler.php`
- **ThrottleRule Model**: `/packages/martingalian/core/src/Models/ThrottleRule.php`
- **ThrottleLog Model**: `/packages/martingalian/core/src/Models/ThrottleLog.php`
- **NotificationService**: `/packages/martingalian/core/src/Support/NotificationService.php`
- **Notification Model**: `/packages/martingalian/core/src/Models/Notification.php`

---

*Analysis completed: 2025-01-14*
*System: Martingalian Trading Bot v1.0*
