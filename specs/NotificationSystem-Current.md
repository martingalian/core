# Notification System - Current Implementation

**Last Updated**: 2025-12-03
**Status**: Production

---

## Overview

The Martingalian notification system is a unified notification framework built on Laravel's notification system with dual throttling mechanisms (database and cache-based), support for real users and virtual admin, and comprehensive audit logging.

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              NOTIFICATION TRIGGERS                               │
├─────────────────────────────────────────────────────────────────────────────────┤
│  ApiRequestLogObserver          ForbiddenHostnameObserver        Cronjob Commands│
│  (API errors)                   (IP/account blocks)              (stale data, etc)│
└─────────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                          NotificationService::send()                             │
│  ┌─────────────────┐  ┌──────────────────────┐  ┌─────────────────────────────┐ │
│  │ Throttle Check  │→ │ NotificationMessage  │→ │ $user->notify(Alert...)     │ │
│  │ (cache or DB)   │  │ Builder::build()     │  │                             │ │
│  └─────────────────┘  └──────────────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              AlertNotification                                   │
│  ┌─────────────────┐  ┌──────────────────────┐  ┌─────────────────────────────┐ │
│  │ via() - channels│  │ toPushover()         │  │ toMail() → AlertMail       │ │
│  │ (user prefs)    │  │ (severity→priority)  │  │ → Blade template           │ │
│  └─────────────────┘  └──────────────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│                         NotificationLogListener                                  │
│  Listens to: NotificationSent, NotificationFailed                               │
│  Creates: NotificationLog (audit trail)                                         │
└─────────────────────────────────────────────────────────────────────────────────┘
```

---

## Core Components

### 1. NotificationService
**Location**: `packages/martingalian/core/src/Support/NotificationService.php`

The unified entry point for all notifications. Handles throttling, message building, and dispatch.

**Primary Method**:
```php
public static function send(
    User $user,                           // Real or virtual admin user
    string $canonical,                    // Notification template identifier
    array $referenceData = [],            // Data for template interpolation
    ?object $relatable = null,            // Context model (Account, ApiSystem, etc.)
    ?int $duration = null,                // Throttle duration override
    ?array $cacheKeys = null              // Cache key data array for cache-based throttling
): bool
```

**Returns**:
- `true` if notification was sent
- `false` if throttled, blocked, or notifications disabled

**Flow**:
1. Check if notifications globally enabled (`config('martingalian.notifications_enabled')`)
2. Load `Notification` model by canonical
3. Determine throttle duration (parameter → database default)
4. If `$cacheKeys` provided → build cache key string → atomic `Cache::add()` throttle
5. Else → database throttle via `NotificationLog` query
6. Call `NotificationMessageBuilder::build()` for message content
7. Attach `$relatable` as dynamic property on User
8. Dispatch `AlertNotification` via `$user->notify()`

**Throttling Logic**:
- `$duration = null` → Use default from `notifications.cache_duration` column
- `$duration = 0` → No throttling (always send)
- `$duration > 0` → Custom throttle window in seconds

---

### 2. NotificationMessageBuilder
**Location**: `packages/martingalian/core/src/Support/NotificationMessageBuilder.php`

Pure function that transforms canonicals into user-friendly content. Uses a `match` statement for all canonicals.

**Method**:
```php
public static function build(
    string|Notification $canonical,
    array $context = [],
    ?User $user = null
): array
```

**Returns**:
```php
[
    'severity' => NotificationSeverity,
    'title' => string,
    'emailMessage' => string,
    'pushoverMessage' => string,
    'actionUrl' => ?string,
    'actionLabel' => ?string,
]
```

**Context Support**:
- **New format** (Eloquent models): `'apiSystem' => $model`, `'account' => $model`
- **Legacy format** (raw values): `'exchange' => 'binance'`, `'http_code' => 418`

**Special Markers** (parsed by Blade template):
- `[COPY]text[/COPY]` → Styled copyable IP address box
- `[CMD]text[/CMD]` → Styled command block

---

### 3. AlertNotification
**Location**: `packages/martingalian/core/src/Notifications/AlertNotification.php`

Laravel Notification class that supports Pushover and Mail channels.

**Key Characteristics**:
- **Not queued** — sent synchronously so routing has access to notification object
- **Multi-channel**: Channels determined by `$notifiable->notification_channels`
- **Inactive user filtering**: `via()` returns `[]` if `$notifiable->is_active` is false
- **Severity-based priority**: Critical → emergency Pushover priority (2) with siren sound

**Constructor Parameters**:
```php
public function __construct(
    public string $message,
    public string $title = 'Alert',
    public ?string $canonical = null,
    public ?string $deliveryGroup = null,
    public array $additionalParameters = [],
    public ?NotificationSeverity $severity = null,
    public ?string $pushoverMessage = null,  // Override for Pushover
    public ?string $exchange = null,
    public ?string $serverIp = null
)
```

---

### 4. NotificationLogListener
**Location**: `packages/martingalian/core/src/Listeners/NotificationLogListener.php`

Event subscriber that creates audit trail entries for all notifications.

**Listens To**:
- `NotificationSent` → creates log with status `delivered`
- `NotificationFailed` → creates log with status `failed`

**Key Extractions**:
- `extractCanonical()` — from notification's `canonical` or `messageCanonical` property
- `extractUserId()` — User ID or null for virtual admin
- `extractRelatable()` — uses `isset()` for dynamic property detection
- `extractRecipient()` — email or Pushover key based on channel
- `extractGatewayResponse()` — Zeptomail `X-Zepto-Response` header or Pushover response
- `extractRawEmailContent()` — HTML body for legal audit

**Critical Detail**: Uses `isset($notifiable->relatable)` not `property_exists()` because relatable is added dynamically.

---

### 5. NotificationLog Model
**Location**: `packages/martingalian/core/src/Models/NotificationLog.php`

Dual-purpose model: legal audit trail AND database-based throttling source.

**Key Columns**:
| Column | Purpose |
|--------|---------|
| `uuid` | Unique identifier (HasUuids trait) |
| `notification_id` | FK to notifications registry |
| `canonical` | Template identifier |
| `user_id` | WHO received (null for admin) |
| `relatable_type/id` | WHAT it's about (polymorphic) |
| `channel` | mail, pushover |
| `recipient` | Email or Pushover key |
| `message_id` | Zeptomail request_id or Pushover receipt |
| `sent_at` | Timestamp |
| `status` | delivered, failed |
| `opened_at` | Email open tracking |
| `soft_bounced_at` | Soft bounce timestamp |
| `hard_bounced_at` | Hard bounce timestamp |
| `gateway_response` | JSON gateway response |
| `content_dump` | Full notification content |
| `raw_email_content` | HTML email body |
| `error_message` | Failure reason |

**Critical Separation**:
```
user_id = WHO received the notification
relatable = WHAT it's about (context model)
```

**Scopes**: `byCanonical()`, `byChannel()`, `byStatus()`, `failed()`, `delivered()`

---

### 6. Notification Model
**Location**: `packages/martingalian/core/src/Models/Notification.php`

Registry of notification templates. Controls WHAT to say, not HOW OFTEN.

**Key Columns**:
| Column | Purpose |
|--------|---------|
| `canonical` | Unique identifier |
| `title` | Display title |
| `description` | Short description |
| `detailed_description` | Full documentation |
| `usage_reference` | Where it's used in code |
| `verified` | Production verification status |
| `default_severity` | NotificationSeverity enum |
| `cache_key` | JSON array of required cache key fields |
| `cache_duration` | Default throttle in seconds |

---

### 7. NotificationSeverity Enum
**Location**: `packages/martingalian/core/src/Enums/NotificationSeverity.php`

```php
enum NotificationSeverity: string
{
    case Critical = 'critical';  // Red, emergency Pushover, high email priority
    case High = 'high';          // Amber, normal Pushover, high email priority
    case Medium = 'medium';      // Green
    case Info = 'info';          // Blue
}
```

**Methods**: `label()`, `icon()`, `color()`, `backgroundColor()`

---

### 8. AlertMail Mailable
**Location**: `packages/martingalian/core/src/Mail/AlertMail.php`

Mailable wrapper for email notifications.

**Features**:
- Subject with optional exchange suffix
- High priority headers for Critical/High severity (X-Priority: 1, Importance: high)
- Uses `martingalian::emails.notification` Blade template

---

### 9. Email Blade Template
**Location**: `packages/martingalian/core/resources/views/emails/notification.blade.php`

Full HTML email template with:
- Responsive table layout for email client compatibility
- Severity badge with dynamic colors
- `[COPY]` → `.ip-address` styled box (user-select: all)
- `[CMD]` → `.command-block` styled box (monospace, user-select: all)
- Action button if URL provided
- Footer with contact info and timestamp

---

## Throttling Mechanisms

### Database-Based Throttling (Default)

**When**: No `$cacheKeys` parameter provided

**How It Works**:
1. Queries `notification_logs` table
2. Looks for last notification with same canonical + relatable
3. Checks if within throttle window
4. Blocks if within window, otherwise sends

```php
$throttleRelatable = $relatable ?? $user;

$isThrottled = NotificationLog::query()
    ->where('canonical', $canonical)
    ->where('relatable_type', get_class($throttleRelatable))
    ->where('relatable_id', $throttleRelatable->id)
    ->where('created_at', '>', now()->subSeconds($throttleDuration))
    ->exists();
```

**Use Case**: Throttling based on context (one notification per Account per canonical per time window)

---

### Cache-Based Throttling

**When**: `$cacheKeys` parameter provided

**How It Works**:
1. Builds cache key string from `$cacheKeys` array + `notifications.cache_key` template
2. Uses atomic `Cache::add()` (SETNX in Redis)
3. If key doesn't exist → sets key, returns true (send)
4. If key exists → returns false (throttled)

**Cache Key Building**:
```php
// Input
$canonical = 'server_rate_limit_exceeded';
$cacheKeys = ['api_system' => 'binance', 'account' => 1];
$template = ['api_system', 'account'];  // from notifications.cache_key

// Output: "server_rate_limit_exceeded-api_system:binance,account:1"
```

**Race Condition Prevention**:
- `Cache::add()` is atomic SETNX in Redis
- First worker wins, others get false
- Prevents duplicates across multiple worker servers

**Redis Configuration**:
- Database: 1
- Prefix: `martingalian_database_`
- Operation: Atomic SETNX

---

## Virtual Admin User

**Access**: `Martingalian::admin()`

**Location**: `packages/martingalian/core/src/Concerns/Martingalian/HasGetters.php`

**Implementation**:
```php
public static function admin(): User
{
    return once(function () {
        $martingalian = self::findOrFail(1);

        return tap(new User, function (User $user) use ($martingalian) {
            $user->exists = false;
            $user->is_virtual = true;
            $user->setAttribute('name', 'System Administrator');
            $user->setAttribute('email', $martingalian->email);
            $user->setAttribute('pushover_key', $martingalian->admin_pushover_user_key);
            $user->setAttribute('notification_channels', $martingalian->notification_channels ?? ['pushover']);
            $user->setAttribute('is_active', true);
        });
    });
}
```

**Protection**: User model's `save()` throws `RuntimeException` if `is_virtual = true`

**Important**: Admin notifications have `user_id = NULL` in notification_logs.

---

## User Notification Routing

**Location**: `packages/martingalian/core/src/Models/User.php`

### Mail Routing
```php
public function routeNotificationForMail($notification): ?string
{
    return $this->email;
}
```

### Pushover Routing
```php
public function routeNotificationForPushover($notification): ?PushoverReceiver
{
    $martingalian = Martingalian::find(1);
    $appToken = $martingalian->admin_pushover_application_key;

    // Check for delivery group (temp property or notification object)
    $deliveryGroup = $this->_temp_delivery_group
        ?? ($notification->deliveryGroup ?? null);

    if ($deliveryGroup) {
        // Route to group key from config
        $groupConfig = config("martingalian.api.pushover.delivery_groups.{$deliveryGroup}");
        return PushoverReceiver::withUserKey($groupConfig['group_key'])
            ->withApplicationToken($appToken);
    }

    // Route to user's individual key
    return PushoverReceiver::withUserKey($this->pushover_key)
        ->withApplicationToken($appToken);
}
```

### Channel Accessor
Maps stored channel names to actual channel classes:
```php
'pushover' → PushoverChannel::class
'mail' → 'mail'
```

---

## Notification Triggers

### 1. ApiRequestLogObserver
**Location**: `packages/martingalian/core/src/Observers/ApiRequestLogObserver.php`

**Triggers on**: `saved` event for `ApiRequestLog`

**Responsibilities**:
1. **Error notifications**: For HTTP 4xx/5xx, uses `NotificationHandler` to get canonical
2. **TAAPI deactivation**: For consistent "no data" errors, auto-disables `ExchangeSymbol`

**Flow**:
```php
ApiRequestLog saved (HTTP 4xx/5xx)
    ↓
BaseNotificationHandler::make($exchange)  // Factory
    ↓
$handler->getCanonical($httpCode, $vendorCode)
    ↓
NotificationService::send(...)
```

---

### 2. ForbiddenHostnameObserver
**Location**: `packages/martingalian/core/src/Observers/ForbiddenHostnameObserver.php`

**Triggers on**: `created` event for `ForbiddenHostname`

**Routing Logic**:
- **Admin notifications**: `TYPE_IP_BANNED`, `TYPE_IP_RATE_LIMITED` (system-wide)
- **User notifications**: `TYPE_IP_NOT_WHITELISTED`, `TYPE_ACCOUNT_BLOCKED` (account-specific)

```php
$notifyAdmin = in_array($record->type, [
    ForbiddenHostname::TYPE_IP_BANNED,
    ForbiddenHostname::TYPE_IP_RATE_LIMITED,
], true);

$user = $notifyAdmin
    ? Martingalian::admin()
    : $record->account?->user;
```

---

### 3. NotificationHandlers
**Location**: `packages/martingalian/core/src/Support/NotificationHandlers/`

**Base Class**: `BaseNotificationHandler` (abstract)
- Factory method: `make($apiCanonical)` returns exchange-specific handler
- Abstract method: `getCanonical(int $httpCode, ?int $vendorCode): ?string`

**BinanceNotificationHandler**:
| HTTP Code | Vendor Code | Canonical |
|-----------|-------------|-----------|
| 418 | - | `server_ip_forbidden` |
| 429 | - | `server_rate_limit_exceeded` |
| 400 | -1003 | `server_rate_limit_exceeded` |

**BybitNotificationHandler**:
| HTTP Code | Vendor Code | Canonical |
|-----------|-------------|-----------|
| 401 | - | `server_ip_forbidden` |
| 200 | 10003, 10004, 10005, 10007, 10009, 10010 | `server_ip_forbidden` |
| 403, 429 | - | `server_rate_limit_exceeded` |
| 200 | 10006, 10018, 170005, 170222 | `server_rate_limit_exceeded` |

---

## ForbiddenHostname Model
**Location**: `packages/martingalian/core/src/Models/ForbiddenHostname.php`

Tracks IP addresses blocked from making API calls.

**Types**:
| Constant | Description | Sent To | Resolution |
|----------|-------------|---------|------------|
| `TYPE_IP_NOT_WHITELISTED` | User forgot to whitelist IP | User | Add IP to API key |
| `TYPE_IP_RATE_LIMITED` | Temporary rate limit | Admin | Auto-recovers |
| `TYPE_IP_BANNED` | Permanent IP ban | Admin | Contact exchange |
| `TYPE_ACCOUNT_BLOCKED` | API key issue | User | Regenerate key |

**Helper Methods**:
- `isActive()` — ban still in effect?
- `isSystemWide()` — affects all accounts? (`account_id` is null)
- `isTemporary()` — auto-recovers?
- `requiresUserAction()` — user can fix?
- `requiresExchangeSupport()` — need to contact exchange?

---

## Notification Canonicals Registry

### Currently in Database (11)

| Canonical | Title | Severity | Cache Key Template |
|-----------|-------|----------|-------------------|
| `exchange_symbol_no_taapi_data` | Exchange Symbol Auto-Deactivated | Info | `['exchange_symbol', 'exchange']` |
| `server_ip_forbidden` | Server IP Forbidden by Exchange | Critical | `['api_system', 'server']` |
| `server_rate_limit_exceeded` | Server Rate Limit Exceeded | Info | `['api_system', 'account', 'server']` |
| `slow_query_detected` | Slow Database Query Detected | High | - |
| `stale_dispatched_steps_detected` | Stale Dispatched Steps Detected | Critical | - |
| `stale_price_detected` | Stale Price Detected | High | - |
| `token_delisting` | Token Delisting Detected | High | - |
| `update_prices_restart` | Price Stream Restart | Info | - |
| `websocket_error` | WebSocket Error | High | - |
| `websocket_invalid_json` | WebSocket: Invalid JSON Response | High | `['api_system']` |
| `websocket_prices_update_error` | WebSocket Prices: Database Update Error | Critical | - |

### ForbiddenHostname Canonicals (4)
**Seeder**: `ForbiddenHostnameNotificationsSeeder`

| Canonical | Title | Severity | Cache Key Template | Sent To |
|-----------|-------|----------|-------------------|---------|
| `server_ip_not_whitelisted` | Server IP Not Whitelisted | High | `['account_id', 'ip_address']` | User |
| `server_ip_rate_limited` | Server IP Rate Limited | High | `['api_system', 'ip_address']` | Admin |
| `server_ip_banned` | Server IP Permanently Banned | Critical | `['api_system', 'ip_address']` | Admin |
| `server_account_blocked` | Account API Access Blocked | Critical | `['account_id', 'api_system']` | User |

---

## Usage Examples

### Admin Notification with Database Throttling
```php
NotificationService::send(
    user: Martingalian::admin(),
    canonical: 'stale_price_detected',
    referenceData: [
        'exchange' => 'binance',
        'oldest_symbol' => 'BTCUSDT',
        'oldest_minutes' => 15,
    ],
    relatable: $apiSystem
);
```

### Admin Notification with Cache Throttling
```php
NotificationService::send(
    user: Martingalian::admin(),
    canonical: 'server_rate_limit_exceeded',
    referenceData: [
        'apiSystem' => $apiSystem,
        'apiRequestLog' => $log,
        'account' => $account,
    ],
    relatable: $apiSystem,
    cacheKeys: [
        'api_system' => $apiSystem->canonical,
        'account' => $account->id,
        'server' => gethostname(),
    ]
);
```

### User Notification
```php
NotificationService::send(
    user: $account->user,
    canonical: 'server_ip_not_whitelisted',
    referenceData: [
        'exchange' => 'binance',
        'ip_address' => Martingalian::ip(),
        'account_name' => $account->name,
    ],
    relatable: $apiSystem,
    cacheKeys: [
        'account_id' => $account->id,
        'ip_address' => Martingalian::ip(),
    ]
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

## Testing

### Test Command
**Location**: `app/Console/Commands/Tests/TestNotificationCommand.php`

```bash
# Test specific canonical
php artisan test:notification server_rate_limit_exceeded

# Test with account context
php artisan test:notification --account_id=1 server_ip_not_whitelisted

# Bypass throttling
php artisan test:notification --duration=0 server_rate_limit_exceeded

# Clean test logs
php artisan test:notification --clean
```

### Integration Tests
**Location**: `tests/Integration/Notifications/`

- `ForbiddenHostnameNotificationTest.php` — tests all 4 ForbiddenHostname notification types
- `ApiRequestLogNotificationTest.php` — tests API error notifications
- `NotificationToggleTest.php` — tests notification enable/disable

---

## Related Files

### Core
- `packages/martingalian/core/src/Support/NotificationService.php`
- `packages/martingalian/core/src/Support/NotificationMessageBuilder.php`
- `packages/martingalian/core/src/Notifications/AlertNotification.php`
- `packages/martingalian/core/src/Listeners/NotificationLogListener.php`
- `packages/martingalian/core/src/Models/Notification.php`
- `packages/martingalian/core/src/Models/NotificationLog.php`
- `packages/martingalian/core/src/Mail/AlertMail.php`
- `packages/martingalian/core/resources/views/emails/notification.blade.php`
- `packages/martingalian/core/src/Enums/NotificationSeverity.php`

### Triggers
- `packages/martingalian/core/src/Observers/ApiRequestLogObserver.php`
- `packages/martingalian/core/src/Observers/ForbiddenHostnameObserver.php`
- `packages/martingalian/core/src/Support/NotificationHandlers/BaseNotificationHandler.php`
- `packages/martingalian/core/src/Support/NotificationHandlers/BinanceNotificationHandler.php`
- `packages/martingalian/core/src/Support/NotificationHandlers/BybitNotificationHandler.php`

### Models
- `packages/martingalian/core/src/Models/ForbiddenHostname.php`
- `packages/martingalian/core/src/Models/Martingalian.php`
- `packages/martingalian/core/src/Models/User.php`

### Seeders
- `packages/martingalian/core/database/seeders/ForbiddenHostnameNotificationsSeeder.php`

### Testing
- `app/Console/Commands/Tests/TestNotificationCommand.php`
- `tests/Integration/Notifications/ForbiddenHostnameNotificationTest.php`

---

## Configuration

### Redis Cache
- Driver: `redis`
- Connection: `cache`
- Database: `1`
- Prefix: `martingalian_database_`

### Global Toggle
```php
// config/martingalian.php
'notifications_enabled' => env('NOTIFICATIONS_ENABLED', true),
```

**CRITICAL**: After changing `NOTIFICATIONS_ENABLED` in `.env`, you MUST restart the `schedule-work` supervisor process. Long-running PHP processes (like `schedule:work`) cache config in memory and won't see `.env` changes until restarted.

```bash
sudo supervisorctl restart schedule-work
# Or restart all:
sudo supervisorctl restart all
```

### Supervisor Managed Processes

All notification-related processes must be managed by supervisor to ensure config changes take effect:

| Process | Config File | Purpose |
|---------|-------------|---------|
| `horizon` | `/etc/supervisor/conf.d/horizon.conf` | Queue workers |
| `schedule-work` | `/etc/supervisor/conf.d/schedule-work.conf` | Cron scheduler (runs CheckStaleDataCommand, etc.) |
| `update-binance-prices` | `/etc/supervisor/conf.d/update-binance-prices.conf` | Binance websocket |
| `update-bybit-prices` | `/etc/supervisor/conf.d/update-bybit-prices.conf` | Bybit websocket |

---

## Common Issues & Solutions

### Issue: Notification Not Sent
**Check**: Is `notifications_enabled` config true?
**Check**: Is the canonical in the `notifications` table?
**Check**: Is throttling blocking it? Check `notification_logs`

### Issue: Relatable Not Saved in Log
**Check**: Is relatable passed to `NotificationService::send()`?
**Solution**: Always pass context model as `relatable` parameter

### Issue: user_id Not NULL for Admin
**Check**: Using `Martingalian::admin()` for user parameter?
**Solution**: Admin must use virtual user, not a real User instance

### Issue: Cache Throttling Not Working
**Check**: Redis database and prefix configuration
**Verify**: `redis-cli -n 1 KEYS "martingalian_database_*"`
**Check**: Does `notifications.cache_key` have the template?

### Issue: Database Throttling Not Working
**Check**: Is same `relatable` being passed?
**Debug**: Query `notification_logs` for matching canonical + relatable

### Issue: Missing Canonical
**Check**: Is canonical in `notifications` table?
**Check**: Is canonical in `NotificationMessageBuilder`?
**Solution**: Add to both locations

---

## Changelog

### 2025-12-03
- Removed orphaned `throttleLogs()` relationships from User and Account models
- Documented complete notification system architecture
- Added ForbiddenHostname notification canonicals (4 types)

### Previous
- Removed `Throttler` class and `throttle_logs` table
- Unified `NotificationService::send()` with simpler signature
- Implemented dual throttling (database + cache)
- Added atomic `Cache::add()` for race condition prevention
