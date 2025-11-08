# Notifications System (Part 1)

## Overview
Multi-channel notifications (Pushover, Email via Zeptomail) with comprehensive legal audit trail. User preferences, throttling, user-friendly messages, delivery tracking via webhooks, and full request/response logging for compliance.

### Key Features
- **Multi-channel delivery**: Pushover (push notifications) and Email (via Zeptomail transactional email service)
- **Legal audit trail**: Every notification logged in `notification_logs` table with full content dump
- **Delivery tracking**: Webhook integration for email opens, clicks, bounces, and emergency acknowledgments
- **Request/response logging**: Full HTTP headers and gateway responses stored for debugging
- **User preferences**: Per-user channel selection (`notification_channels` JSON field)
- **Throttling**: Per-canonical throttling to prevent notification spam
- **Severity levels**: Critical, High, Medium, Info with appropriate priority headers
- **User-friendly messages**: Template-based message builder with exchange/context interpolation

### Architecture Overview

**Notification Flow**:
1. Error occurs (API failure, system alert, trading event)
2. `ApiRequestLog` saved → Observer triggers `sendNotificationIfNeeded()`
3. `NotificationMessageBuilder::build()` creates user-friendly message
4. `NotificationService::send()` dispatches notification (unified for users and admin)
5. `AlertNotification` routes to channels based on user preferences via Laravel's notification system
6. **For Email**: `ZeptoMailTransport` sends via Zeptomail API, stores response in message headers
7. **For Pushover**: `PushoverChannel` sends via Pushover API
8. Laravel **automatically** fires `NotificationSent` event
9. `NotificationLogListener` creates audit log entry in `notification_logs` table
10. **Later**: Webhook from Zeptomail updates log with delivery confirmation

**Complete Data Flow**:
```
API Error
  ↓
ApiRequestLog (saved)
  ↓
ApiRequestLogObserver::saved()
  ↓
SendsNotifications::sendNotificationIfNeeded()
  ↓
NotificationMessageBuilder::build()
  ↓
NotificationService::send(user: $user OR Martingalian::admin())
  ↓
User->notifyWithGroup(AlertNotification) [Laravel Notification System]
  ↓
AlertNotification->via() determines channels
  ↓
┌──────────────────────┬───────────────────────┐
│   PushoverChannel    │    Mail Channel       │
│   (Laravel Package)  │  (ZeptoMailTransport) │
│         ↓            │         ↓             │
│   POST Pushover API  │  POST Zeptomail API   │
│         ↓            │         ↓             │
│   Returns receipt    │  Store response       │
│                      │  in message headers   │
└──────────────────────┴───────────────────────┘
  ↓
NotificationSent event AUTOMATICALLY fired by Laravel
  ↓
NotificationLogListener::handleNotificationSent()
  ↓
NotificationLog created (audit trail with full gateway data)
  ↓
[Later] Webhook from Zeptomail
  ↓
NotificationWebhookController::zeptomail()
  ↓
NotificationLog updated (opened_at, bounced_at)
```

## Single Source of Truth

**RULE**: All API error notifications originate from `ApiRequestLog` model (via `SendsNotifications` trait).

**Flow**: API fails → Log created → Observer → `sendNotificationIfNeeded()` → Analyzes HTTP codes → Sends

**BaseExceptionHandler**: ONLY HTTP code analysis (`isRateLimitedFromLog()`, `isForbiddenFromLog()`), NOT notifications

## Core Classes

### ApiRequestLog Model
**Location**: `packages/martingalian/core/src/Models/ApiRequestLog.php`
**Trait**: `SendsNotifications` - Contains ALL notification logic
**Method**: `sendNotificationIfNeeded()` - Single source of truth

### SendsNotifications Trait
**Location**: `packages/martingalian/core/src/Concerns/ApiRequestLog/SendsNotifications.php`
**Methods**: `sendNotificationIfNeeded()`, `sendUserNotification()`, `sendAdminNotification()`, `sendThrottledNotification()`
**Analyzes**: HTTP codes (401, 403, 429, 503), vendor codes, connection failures
**Routes**: Based on `user_types` JSON field

#### Server Context in Notifications
**Architectural Rule**: Admin notifications do NOT include server IP/exchange in email subjects

**User Notifications** (MAY include server IP/exchange in email subject):
- Server-specific issues where context helps user understand (e.g., `ip_not_whitelisted`)
- Email subject shows: `"Title - Server IP on Exchange"` when serverIp and exchange provided

**Admin Notifications** (NO server IP/exchange in email subject):
- All admin notifications send WITHOUT serverIp/exchange parameters
- Server context (if relevant) is included ONLY in email message body
- This keeps email subjects clean and focused on the issue, not the infrastructure
- Admin knows which server from hostname in email footer

**Why**: Admin email subjects should focus on the problem type, not which server detected it. Server context (when relevant) goes in the email body. This prevents subject line clutter and makes email filtering/searching easier.

### AlertNotification
**Location**: `packages/martingalian/core/src/Notifications/AlertNotification.php`
**Extends**: Laravel `Notification`
**Channels**: Pushover, Email (respects `user->notification_channels`)
**Rule**: Only sends to active users (`is_active = true`)

### NotificationService
**Location**: `packages/martingalian/core/src/Support/NotificationService.php`
**Namespace**: `Martingalian\Core\Support\NotificationService`
**Methods**: `send()`, `sendToAdminByCanonical()`

**CRITICAL: Unified Notification Architecture**:
The notification system has been simplified to use a **single unified method** that leverages Laravel's notification system for both users and admin.

**Key Architectural Change**:
- **Before**: Separate `sendToUser()` and `sendToAdmin()` with manual event firing
- **After**: Single `send()` method using Laravel's notification system for ALL notifications
- **Admin User**: Virtual User instance via `Martingalian::admin()` (non-persisted, `is_virtual = true`)

**NotificationService::send()**: Unified notification method
```php
NotificationService::send(
    user: $user,               // Real User OR Martingalian::admin()
    message: 'Alert message',
    title: 'Alert Title',
    canonical: 'alert_type',
    deliveryGroup: 'exceptions',
    severity: NotificationSeverity::High,
    relatable: $relatableModel // Optional: ApiSystem, Step, Account, etc.
)
```

**How Admin Notifications Work Now**:
1. `Martingalian::admin()` returns a **virtual User** instance
2. Virtual user has all notification credentials from `martingalian` table
3. Laravel's notification system handles sending (channels, routing, events)
4. `NotificationSent` event **automatically fired** by Laravel
5. `NotificationLogListener` captures event and logs to `notification_logs`
6. Virtual user has `is_virtual = true` flag preventing accidental database saves

**NotificationService::sendToAdminByCanonical()**: Convenience method for template-based admin notifications
```php
NotificationService::sendToAdminByCanonical(
    canonical: 'api_rate_limit_exceeded',
    context: ['exchange' => 'binance'],
    deliveryGroup: 'exceptions',
    relatable: $apiSystem
)
```
- Fetches message template from `NotificationMessageBuilder`
- Automatically uses `Martingalian::admin()` as recipient
- Keeps email subjects clean (no serverIp/exchange parameters)

### Martingalian::admin() - Virtual Admin User
**Location**: `packages/martingalian/core/src/Concerns/Martingalian/HasGetters.php`
**Namespace**: `Martingalian\Core\Concerns\Martingalian\HasGetters`
**Usage**: `Martingalian::admin()`

**Purpose**: Returns a virtual (non-persisted) User instance configured with admin notification credentials.

**Key Characteristics**:
- **Non-persisted**: `exists = false`, never touches database
- **Protected**: `is_virtual = true` flag prevents accidental `save()` calls
- **Cached**: Uses `once()` helper for singleton behavior per request
- **Fully functional**: Works seamlessly with Laravel's notification system

**Implementation**:
```php
// In HasGetters trait
public static function admin(): User
{
    return once(function () {
        $martingalian = self::findOrFail(1);

        return tap(new User, function (User $user) use ($martingalian) {
            $user->exists = false;
            $user->is_virtual = true;
            $user->name = 'System Administrator';
            $user->email = $martingalian->admin_user_email;
            $user->pushover_key = $martingalian->admin_pushover_user_key;
            $user->notification_channels = $martingalian->notification_channels ?? ['pushover'];
            $user->is_active = true;
        });
    });
}
```

**Safety Guard in User Model**:
```php
// In User model
public bool $is_virtual = false;

public function save(array $options = []): bool
{
    if ($this->is_virtual) {
        throw new \RuntimeException('Cannot save virtual admin user to database');
    }
    return parent::save($options);
}
```

**Usage Examples**:
```php
// Admin notification
NotificationService::send(
    user: Martingalian::admin(),
    message: 'System error detected',
    title: 'System Alert',
    canonical: 'api_system_error',
    relatable: $apiSystem
);

// User notification (same method!)
NotificationService::send(
    user: $user,
    message: 'Position opened',
    title: 'Trading Alert',
    canonical: 'position_opened',
    relatable: $position
);
```

**Why This Design?**:
1. **Single Code Path**: One notification method for all recipients
2. **Laravel Native**: Fully leverages Laravel's notification system
3. **Type Safety**: Same `User` type, no special handling needed
4. **DRY Principle**: No duplicate notification logic
5. **Maintainable**: Simpler codebase, easier to understand
6. **Testable**: Standard Laravel notification testing works

## Critical Developer Requirements

### ALWAYS Pass Canonical Parameter
**RULE**: Every call to `NotificationService::send()` SHOULD include the `canonical` parameter for proper audit tracking.

**Why**: Without a canonical:
- `NotificationLogListener` falls back to class name `"AlertNotification"`
- `notification_logs.notification_id` will be NULL (can't lookup Notification record)
- Notifications become untrackable and unidentifiable
- Audit trail is incomplete

**Correct**:
```php
NotificationService::send(
    user: Martingalian::admin(),
    message: 'Rate limit exceeded',
    title: 'API Rate Limit',
    canonical: 'api_rate_limit_exceeded',  // ✅ REQUIRED
    deliveryGroup: 'exceptions'
);
```

**Incorrect** (will log as "AlertNotification" with null notification_id):
```php
NotificationService::send(
    user: Martingalian::admin(),
    message: 'Rate limit exceeded',
    title: 'API Rate Limit',
    deliveryGroup: 'exceptions'  // ❌ Missing canonical
);
```

### ALWAYS Use IP Addresses, Not Hostnames
**RULE**: The `'ip'` context key MUST contain an IP address (IPv4/IPv6), NEVER a hostname.

**Why**: Users need IP addresses to:
- Whitelist on exchange APIs
- Troubleshoot network connectivity
- Identify which specific server has issues

**Correct**:
```php
$serverIp = gethostbyname(gethostname());  // Converts hostname to IP
NotificationMessageBuilder::build('ip_not_whitelisted', [
    'ip' => $serverIp,          // ✅ IP: "91.84.82.171"
    'hostname' => gethostname(), // Optional: "server-name"
]);
```

**Incorrect** (shows hostname instead of IP):
```php
NotificationMessageBuilder::build('ip_not_whitelisted', [
    'ip' => gethostname(),  // ❌ Hostname: "DELLXPS15" (useless for whitelisting)
]);
```

**Implementation Note**: In `SendsNotifications.php`, always calculate `$serverIp = gethostbyname($hostname)` early and use it for all `'ip'` context keys.

### NotificationLogListener Requires Canonical
**RULE**: `NotificationLogListener::extractCanonical()` extracts canonical from notification object properties.

**Extraction Order**:
1. `$notification->canonical` (if non-empty string)
2. `$notification->messageCanonical` (if non-empty string)
3. Fallback: `class_basename($notification)` → `"AlertNotification"`

**notification_id Lookup**:
- If canonical found: Looks up `Notification::where('canonical', $canonical)->value('id')`
- If canonical is NULL or doesn't exist in DB: `notification_id` remains NULL
- Always create Notification records first via seeder before using canonicals

**Best Practice**: Create all canonicals in `NotificationsTableSeeder.php` before using them in code.

### Notification Model
**Location**: `packages/martingalian/core/src/Models/Notification.php`
**Namespace**: `Martingalian\Core\Models\Notification`
**Purpose**: Registry of notification message templates (canonicals) available in the system

**Schema**:
- `canonical`: Unique identifier (e.g., 'ip_not_whitelisted')
- `title`: Default title for the notification
- `description`: Optional description of the canonical's purpose
- `default_severity`: Default severity level (Info, Medium, High, Critical)
- `is_active`: Whether the canonical can be used
- `user_types`: Array of ['user', 'admin'] indicating target audience

**Helper Methods**:
- `Notification::exists($canonical)`: Check if canonical exists and is active
- `Notification::findByCanonical($canonical)`: Get notification record by canonical
- `Notification::activeCanonicals()`: Get all active canonical strings as array
- `->active()`: Query scope for active notifications
- `->byCanonical($canonical)`: Query scope by canonical

**Separation**: Canonicals control WHAT to say; ThrottleRules control HOW OFTEN

### NotificationMessageBuilder
**Location**: `packages/martingalian/core/src/Support/NotificationMessageBuilder.php`
**Namespace**: `Martingalian\Core\Support\NotificationMessageBuilder`
**Accepts**: Base canonicals (e.g., `api_access_denied`) without exchange prefix
**Returns**: severity, title, emailMessage, pushoverMessage, actionUrl, actionLabel
**Context**: Exchange passed separately for interpolation (e.g., `{exchange: 'binance'}`)
**Templates**: 30+ predefined message templates covering API errors, system alerts, trading events

**Context Variables** (passed via `$context` array):
- `exchange` (string) - Exchange canonical identifier ('binance', 'bybit') for dynamic message interpolation
- `ip` (string) - **MUST be IP address** (IPv4/IPv6), NEVER hostname. Use `gethostbyname(gethostname())` to convert. Included in email body for server-related issues.
- `exception` (string) - Exception message for WebSocket/system errors
- `account_info` (string) - Account name/identifier
- `hostname` (string) - Server hostname (display only, NOT for whitelisting)
- `wallet_balance`, `unrealized_pnl` - Trading metrics

**CRITICAL**: The `'ip'` key MUST contain an actual IP address. Users need this for exchange API whitelisting. Hostnames are useless for this purpose.

**Exchange Name Display**: Templates receive exchange canonical (e.g., 'binance'), but when building notifications:
- Fetch `ApiSystem` model: `ApiSystem::where('canonical', $exchangeCanonical)->first()`
- Use `$apiSystem->name` for display (e.g., "Binance" not "binance")
- Fallback to `ucfirst($canonical)` only if model not found
- Applied in: SendsNotifications trait, NotificationService

### AlertMail
**Location**: `packages/martingalian/core/src/Mail/AlertMail.php`
**Namespace**: `Martingalian\Core\Mail\AlertMail`
**Template**: `resources/views/emails/notification.blade.php`
**Headers**: High-priority for Critical/High severity

**Email Subject Construction**:
- Base: Notification title
- **User notifications only** (admin notifications omit server context):
  - If `serverIp` and `exchange`: `"Title - Server IP on Exchange"`
  - If only `serverIp`: `"Title - Server IP"`
  - If `hostname` and `exchange`: `"Title - Server hostname on Exchange"`
  - Example: `"IP Whitelist Required - Server 1.2.3.4 on Binance"`
- **Admin notifications**: No server IP/exchange appended (clean subject)
  - Example: `"API Rate Limit Exceeded"` (not `"... - Server 1.2.3.4 on Binance"`)
- **Exchange name display**: Uses `ApiSystem->name` (e.g., "Binance") not `ucfirst(canonical)` (e.g., "Binance")

### NotificationLog Model
**Location**: `packages/martingalian/core/src/Models/NotificationLog.php`
**Namespace**: `Martingalian\Core\Models\NotificationLog`
**Purpose**: Legal audit trail for ALL notifications sent through the platform

**Schema** (`notification_logs` table):
- `id`, `uuid` - Unique identifiers
- `notification_id` - Foreign key to `notifications.id` (NULL if canonical not found)
- `canonical` - Message template identifier (e.g., 'api_rate_limit_exceeded')
- `relatable_type`, `relatable_id` - Polymorphic relation (Account, User, or null for admin)
- `channel` - Delivery channel ('mail', 'pushover')
- `recipient` - Email address or Pushover key
- `message_id` - Gateway message ID (Zeptomail `request_id`, Pushover `receipt`)
- `sent_at` - When notification was dispatched
- `opened_at` - When email was opened (from Zeptomail webhook, mail channel only)
- `bounced_at` - When email bounced (from Zeptomail webhook, mail channel only)
- `status` - Current status ('sent', 'delivered', 'failed', 'bounced')
- `http_headers_sent` (JSON) - Request headers sent to gateway
- `http_headers_received` (JSON) - Response headers from gateway
- `gateway_response` (JSON) - Full API response from gateway
- `content_dump` (TEXT) - Full notification content for legal audit
- `raw_email_content` (TEXT) - HTML/text email body for mail viewers
- `error_message` - Error details if failed

**notification_id Foreign Key**:
- Populated by `NotificationLogListener` when creating log entry
- Lookup: `Notification::where('canonical', $canonical)->value('id')`
- Will be NULL if:
  - Canonical parameter not passed to `sendToUser()`/`sendToAdmin()`
  - Canonical doesn't exist in `notifications` table
  - Canonical is NULL on AlertNotification object
- Used for: Linking notification logs to notification definitions for analytics/reporting

**Indexes**:
- `canonical`, `channel`, `message_id`, `status`, `sent_at`, `notification_id`
- `relatable_type` + `relatable_id` (composite)
- `uuid` (unique)

**Scopes**:
- `byCanonical($canonical)`, `byChannel($channel)`, `byStatus($status)`
- `confirmed()`, `unconfirmed()`, `failed()`, `delivered()`

### NotificationLogListener
**Location**: `packages/martingalian/core/src/Listeners/NotificationLogListener.php`
**Namespace**: `Martingalian\Core\Listeners\NotificationLogListener`
**Purpose**: Automatic audit logging for all notifications

**Events Listened**:
- `NotificationSent` - Logs successful dispatch
- `NotificationFailed` - Logs failures with error message

**Logged Data**:
- Canonical extracted from notification object
- **notification_id** looked up from `notifications` table using canonical
- Relatable model (Account, User, or null)
- Recipient based on channel
- Gateway response (extracted from `SentMessage`)
- HTTP headers (sent and received)
- Message ID for tracking (Zeptomail `request_id`, Pushover `receipt`)
- Raw email content (HTML/text) for mail channel
- Content dump (all notification properties for legal audit)

**Key Methods**:
- `extractCanonical()` - Gets canonical from notification (`canonical` or `messageCanonical` property)
- `extractRelatable()` - Determines Account/User/null
- `extractRecipient()` - Gets email or Pushover key based on channel
- `extractGatewayResponse()` - Parses API response from headers (Zeptomail uses `X-Zepto-Response` header)
- `extractHttpHeadersSent()` - Gets request headers from `X-Zepto-Request-Headers` header
- `extractMessageId()` - Extracts tracking ID (Zeptomail `request_id`, Pushover `receipt`)
- `extractRawEmailContent()` - Gets HTML/text body from original message
- `buildContentDump()` - Serializes all notification data to JSON

**notification_id Lookup Process**:
1. Extract canonical from notification object (`extractCanonical()`)
2. Lookup notification: `Notification::where('canonical', $canonical)->value('id')`
3. Store in `notification_logs.notification_id` (NULL if not found)
4. This links log entries to notification definitions for analytics

**How It Works**:
1. Laravel dispatches notification via `AlertNotification`
2. ZeptoMailTransport sends email, stores response/headers in message
3. Laravel fires `NotificationSent` event
4. NotificationLogListener captures event and creates `NotificationLog` entry
5. Webhooks later update the log with delivery confirmation

### Throttler
**Location**: `packages/martingalian/core/src/Support/Throttler.php`
**Namespace**: `Martingalian\Core\Support\Throttler`
**Purpose**: Unified throttling system for any action (notifications, API calls, supervisor restarts)
**Tables**: `throttle_rules` (configuration), `throttle_logs` (execution history)

**Key Features**:
- **Database-driven rules**: Throttle configuration stored in `throttle_rules` table with per-canonical timing
- **Execution tracking**: Every throttled action logged in `throttle_logs` with timestamps
- **Pessimistic locking**: Prevents race conditions in concurrent environments
- **Contextual throttling**: Optional `for($model)` allows per-user, per-account, or per-resource throttling
- **Auto-creation**: Missing throttle rules automatically created if `auto_create_missing_throttle_rules` config enabled

**CRITICAL: Throttler is disaggregated from NotificationService**:
- Throttler controls WHEN to execute (throttling logic)
- NotificationService controls WHAT to send (notification delivery)
- These are separate concerns - Throttler doesn't call NotificationService methods
- The closure passed to `execute()` contains the notification send call

## Channels & Configuration

### Pushover
- Title: No prefix (clean title for mobile devices)
- Priorities: -2 (lowest), -1, 0 (normal), 1 (high), 2 (emergency/siren)
- URL support
- Test notifications: `_temp_pushover_key` property allows testing with different keys without saving to database

### Email (via Zeptomail)
**Provider**: Zeptomail (Zoho transactional email service)
**Package**: `brunocfalcao/laravel-zepto-mail-api-driver` (custom Symfony Mailer transport)
**Location**: `/packages/brunocfalcao/laravel-zepto-mail-api-driver`
**Configuration**: `config/services.php` → `zeptomail` key

- Template: `resources/views/emails/notification.blade.php`
- Title: NO hostname prefix
- Salutation: "Hello {User Name},"
- Message: `nl2br(e())` for newlines
- **Special Markup**: `[COPY]text[/COPY]` renders as prominent, selectable monospace text (used for IP addresses)
- Footer: Support email, timestamp (Europe/Zurich) - **NO hostname** (security)
- **Headers**: Critical/High get `Priority: 1`, `X-Priority: 1`, `Importance: high`
- **Design**: Responsive HTML with severity badges, action buttons, mobile-friendly CSS

#### Zeptomail Integration Details

**Transport Driver**: Custom Symfony Mailer transport implementing `AbstractTransport`
**API Endpoint**: `https://api.zeptomail.com/v1.1/email`
**Authentication**: `Zoho-enczapikey` header with encrypted API key

**Configuration** (`config/services.php`):
```php
'zeptomail' => [
    'mail_key' => env('ZEPTOMAIL_API_KEY'),  // Encrypted API key
    'endpoint' => 'https://api.zeptomail.com',
    'timeout' => 30,
    'retries' => 2,
    'retry_sleep_ms' => 200,
    'track_opens' => true,   // Email open tracking
    'track_clicks' => true,  // Link click tracking
]
```

**HTTP Request Pattern** (CRITICAL):
```php
// CORRECT - Direct URL in post() method
$response = $http
    ->asJson()
    ->acceptJson()
    ->withHeaders(['Authorization' => 'Zoho-enczapikey '.$key, 'User-Agent' => '...'])
    ->post($baseUrl.$path, $payload);

// WRONG - Using ->baseUrl() causes HTTP 400 from WAF
$response = $http
    ->baseUrl($baseUrl)  // ❌ Rejected by Zeptomail WAF
    ->post($path, $payload);
```

**Why the Pattern Matters**:
- Zeptomail's WAF (Web Application Firewall) rejects requests using Laravel's `->baseUrl()` method
- Returns HTTP 400 with HTML content (`text/html;charset=UTF-8`) instead of JSON
- Response body is just 1 byte (a newline character)
- Must use direct URL construction: `->post($fullUrl)` instead of `->baseUrl()->post($path)`
- Fixed in commit `47ae734` (2025-11-03)

**User-Agent Header**:
```php
'User-Agent' => 'Martingalian/1.0 Laravel/'.app()->version().' PHP/'.PHP_VERSION
```
- Required to prevent WAF blocking
- Identifies the application making requests
- Helps with Zeptomail support troubleshooting

**ZeptoMailTransport Implementation**:
**Location**: `/packages/brunocfalcao/laravel-zepto-mail-api-driver/src/ZeptoMailTransport.php`
**Purpose**: Custom Symfony Mailer transport for Zeptomail API integration

**Key Features**:
- Implements `AbstractTransport` from Symfony Mailer
- Supports single emails (`/v1.1/email`) and batch emails (`/v1.1/email/batch`)
- Supports template emails (`/v1.1/email/template`)
- Custom headers for control: `X-Zepto-Track-Opens`, `X-Zepto-Track-Clicks`, `X-Zepto-Client-Reference`
- Stores response data in message headers for listener access:
  - `X-Zepto-Response`: Full API response JSON (includes `request_id`)
  - `X-Zepto-Request-Headers`: Request headers sent to API

**API Request Flow**:
1. Build payload (from, to, subject, htmlbody/textbody)
2. Add attachments and inline images (base64 encoded)
3. Add tracking flags from config
4. POST to Zeptomail API with `Zoho-enczapikey` authentication
5. Store response in message headers for NotificationLogListener
6. Throw exception if non-2xx or error in response

**Tracking Features**:
- `track_opens`: Embeds invisible tracking pixel in emails
- `track_clicks`: Rewrites links to track clicks via Zeptomail proxy
- Webhook callbacks sent to `/api/webhooks/zeptomail/events`
- Updates `notification_logs` table with `opened_at`, `confirmed_at` timestamps

**Webhook Events Supported**:
- `email_open`: User opened the email (updates `opened_at` + `confirmed_at`, `status = 'delivered'`)
- `email_link_click`: User clicked a link (stored in `gateway_response`)
- `hardbounce`: Email bounced permanently (updates `bounced_at`, `status = 'failed'`, `error_message`)
- `softbounce`: Email bounced temporarily (updates `bounced_at`, `status = 'bounced'`, `error_message`)

**Webhook Endpoints**:
- Zeptomail: `POST /api/webhooks/zeptomail/events` (route name: `webhooks.zeptomail`)
- Pushover: `POST /api/webhooks/pushover/receipt` (route name: `webhooks.pushover`)

**Webhook Security**:
- HMAC-SHA256 signature verification
- Secret stored in `config('martingalian.api.webhooks.zeptomail_secret')`
- Header: `X-Zeptomail-Signature`
- All webhooks require valid signature or return HTTP 401
- Signature verification: `hash_hmac('sha256', $payload, $secret)`

**NotificationWebhookController**:
**Location**: `app/Http/Controllers/Webhooks/NotificationWebhookController.php`
**Namespace**: `App\Http\Controllers\Webhooks\NotificationWebhookController`
**Note**: This is one of the few notification-related classes that remains in the `App\` namespace (Controller layer)
**Purpose**: Handles delivery confirmation webhooks from notification gateways

**Methods**:
- `zeptomail(Request)` - Processes Zeptomail webhook events
  - Verifies HMAC signature
  - Extracts event type and data from payload
  - Routes to handler based on event type
  - Returns HTTP 200 even for errors (Zeptomail requirement)
- `handleZeptomailBounce()` - Processes hard/soft bounce events
  - Finds log by `message_id` (matches `request_id` from API)
  - Fallback: searches by recipient email
  - Updates `bounced_at`, `status`, `error_message`, `gateway_response`
- `handleZeptomailOpen()` - Processes email open events
  - Finds log by `message_id`
  - Updates `confirmed_at`, `opened_at`, `status = 'delivered'`
- `handleZeptomailClick()` - Processes link click events
  - Stores click data in `gateway_response`
- `pushover(Request)` - Processes Pushover emergency receipt acknowledgment
  - Finds log by receipt in `gateway_response`
  - Updates `confirmed_at`, `status = 'delivered'`

**Webhook Payload Matching**:
- Primary: `message_id` matches Zeptomail `request_id`
- Fallback: `recipient` email address + recent `sent_at`
- For open events: Only updates if `confirmed_at` is null (prevents duplicate processing)

**Request/Response Logging**:
- `notification_logs.http_headers_sent`: Request headers (including Authorization)
- `notification_logs.http_headers_received`: Response headers from Zeptomail
- `notification_logs.gateway_response`: API response JSON (includes `request_id`)
- `notification_logs.message_id`: Zeptomail's `request_id` for webhook correlation

**Error Handling**:
- HTTP 400: Invalid payload (check `response_body` in logs)
- HTTP 401: Invalid API key
- HTTP 429: Rate limit exceeded (rare with transactional email)
- Exceptions logged with full response body for debugging

### Severity Levels
**Enum**: `Martingalian\Core\Enums\NotificationSeverity`
**Location**: `packages/martingalian/core/src/Enums/NotificationSeverity.php`
- **Critical**: Red - API credentials invalid, IP not whitelisted
- **High**: Orange - Rate limits, connection failures
- **Medium**: Yellow - Exchange maintenance
- **Info**: Blue - P&L alerts

### User Preferences
`notification_channels` JSON: `['mail', 'pushover']`, `['pushover']`, or `null` (defaults to Pushover)

### Testing Notifications (Test Pushover Feature)
**Purpose**: Allow users to test Pushover notifications with different keys before saving to database

**Implementation Pattern**:
```php
// In controller (ProfileController::testPushover)
$testUser = User::find($user->id);
$testUser->_temp_pushover_key = $pushoverKeyFromForm;
$testUser->notification_channels = [PushoverChannel::class];

NotificationService::send(
    user: $testUser,
    message: "Test notification...",
    title: 'Pushover Test',
    canonical: null,
    deliveryGroup: null  // null = use individual user key, not group
);
```

**How It Works**:
1. User enters pushover_key in profile form (not yet saved)
2. Frontend sends AJAX POST to `/profile/test-pushover` with key
3. Controller creates fresh User instance from database
4. Sets `_temp_pushover_key` property (not persisted, bypasses encryption)
5. Notification sent using temporary key instead of encrypted database value
6. Temporary property discarded after request (never saved)

**Key Implementation Details**:
- `User::routeNotificationForPushover()` checks `_temp_pushover_key` first before `pushover_key`
- Pattern mirrors existing `_temp_delivery_group` for delivery group testing
- Bypasses encryption issues with in-memory testing
- Frontend validates pushover_key field and enables/disables test button dynamically

**Error Handling**:
```php
// Friendly error for invalid Pushover key
if (str_contains($e->getMessage(), 'user identifier is not a valid user')) {
    return response()->json([
        'success' => false,
        'message' => 'Invalid Pushover key. Please check that you entered the correct User Key from your Pushover account.',
    ], 422);
}
```

**User Model Implementation**:
```php
// In User::routeNotificationForPushover()
// Check for temporary key first (used for testing without saving to database)
$pushoverKey = $this->_temp_pushover_key ?? $this->pushover_key;

if (! $pushoverKey) {
    return null;
}

return PushoverReceiver::withUserKey($pushoverKey)
    ->withApplicationToken($appToken);
```

### Delivery Groups
Config: `config('martingalian.api.pushover.delivery_groups')`
- **exceptions**: Priority 2 (emergency) - Critical system errors
- **default**: Priority 0 (normal) - Standard notifications
- **indicators**: Priority 0 (normal) - Data quality alerts

## Notification Routing

### user_types Field (JSON)
- **['user']**: Account owner only (requires user action)
- **['admin']**: Admin only (operational/system concerns)
- **['admin', 'user']**: Both (rare)

### Routing Logic
- If `user_types=['admin']` → admin ONLY (even if account has user)
- If `user_types=['user']` → user ONLY (if account has user)
- If `user_types=['admin', 'user']` → BOTH admin AND user receive notification
- If account has no user → all notifications default to admin

**Throttle Key Segregation**: Admin and user notifications use separate throttle keys
- User notification throttle key: `{canonical}` (e.g., `binance_rate_limit_exceeded`)
- Admin notification throttle key: `{canonical}_admin` (e.g., `binance_rate_limit_exceeded_admin`)
- This prevents admin notifications from being throttled when user just received same canonical
- Ensures both recipients get notified when `user_types=['admin', 'user']`

### Examples
- `api_rate_limit_exceeded`: ['admin'] - Operational, auto-handled
- `api_access_denied`: ['user'] - User must fix credentials/IP
- `pnl_alert`: ['user'] - Trading performance

**Why**: Users shouldn't see transient operational errors (rate limits, connection failures). Admins monitor system health. Users get actionable notifications only.
