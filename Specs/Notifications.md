# Notifications System

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
4. `NotificationService::sendToUser()` or `::sendToAdmin()` dispatches notification
5. `AlertNotification` routes to channels based on user preferences
6. **For Email**: `ZeptoMailTransport` sends via Zeptomail API, stores response in message headers
7. **For Pushover**: Direct HTTP POST to Pushover API
8. Laravel fires `NotificationSent` event
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
NotificationService::sendToUser() / sendToAdmin()
  ↓
AlertNotification dispatched
  ↓
┌─────────────────┬─────────────────┐
│     Pushover    │      Email      │
│   (HTTP POST)   │ (ZeptoMailTransport)
│                 │       ↓         │
│                 │ POST to Zeptomail API
│                 │       ↓         │
│                 │ Store response in headers
└─────────────────┴─────────────────┘
  ↓
NotificationSent event fired
  ↓
NotificationLogListener::handleNotificationSent()
  ↓
NotificationLog created (audit trail)
  ↓
[Later] Webhook from Zeptomail
  ↓
NotificationWebhookController::zeptomail()
  ↓
NotificationLog updated (confirmed_at, opened_at, bounced_at)
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
**Methods**: `sendToUser()`, `sendToAdmin()`, `sendToAdminByCanonical()`, `sendDirectToEmail()`, `sendDirectToPushover()`

**Admin Notification Flow**: `sendToAdmin()` uses two-tier lookup:
1. **First**: Attempts to find admin User by email (`config('martingalian.admin_user_email')`)
   - If found, uses standard `sendToUser()` flow (respects User's channels/preferences)
2. **Fallback**: Uses direct sending with credentials from `Martingalian` model:
   - `admin_pushover_user_key` (encrypted admin Pushover key)
   - `admin_user_email` (admin email address)
   - `notification_channels` (admin notification channels)

**sendToAdminByCanonical()**: Convenience method for canonical-based admin notifications
- Fetches message template from NotificationMessageBuilder
- Automatically sends to admin WITHOUT serverIp/exchange parameters
- Keeps email subjects clean and focused on issue type
- Usage: `NotificationService::sendToAdminByCanonical('api_rate_limit_exceeded', ['exchange' => 'binance'])`

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
- `ip` (string) - Server IP address (only included in email body for server-related issues)
- `exception` (string) - Exception message for WebSocket/system errors
- `account_info` (string) - Account name/identifier
- `hostname` (string) - Server hostname
- `wallet_balance`, `unrealized_pnl` - Trading metrics

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
- `canonical` - Message template identifier (e.g., 'api_rate_limit_exceeded')
- `relatable_type`, `relatable_id` - Polymorphic relation (Account, User, or null for admin)
- `channel` - Delivery channel ('mail', 'pushover')
- `recipient` - Email address or Pushover key
- `message_id` - Gateway message ID (Zeptomail `request_id`, Pushover `receipt`)
- `sent_at` - When notification was dispatched
- `confirmed_at` - When user opened/acknowledged (from webhook)
- `opened_at` - When email was opened (from Zeptomail webhook)
- `bounced_at` - When email bounced (from Zeptomail webhook)
- `status` - Current status ('sent', 'delivered', 'failed', 'bounced')
- `http_headers_sent` (JSON) - Request headers sent to gateway
- `http_headers_received` (JSON) - Response headers from gateway
- `gateway_response` (JSON) - Full API response from gateway
- `content_dump` (TEXT) - Full notification content for legal audit
- `raw_email_content` (TEXT) - HTML/text email body for mail viewers
- `error_message` - Error details if failed

**Indexes**:
- `canonical`, `channel`, `message_id`, `status`, `sent_at`
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

**How It Works**:
1. Laravel dispatches notification via `AlertNotification`
2. ZeptoMailTransport sends email, stores response/headers in message
3. Laravel fires `NotificationSent` event
4. NotificationLogListener captures event and creates `NotificationLog` entry
5. Webhooks later update the log with delivery confirmation

### Throttler
**Location**: `packages/martingalian/core/src/Support/Throttler.php`
**Namespace**: `Martingalian\Core\Support\Throttler`
**Window**: 30 minutes
**Table**: `throttle_logs`

## Channels & Configuration

### Pushover
- Title: `[hostname] Title`
- Priorities: -2 (lowest), -1, 0 (normal), 1 (high), 2 (emergency/siren)
- URL support

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

## Throttling

### Two Canonical Types
**Throttle Canonical**: `{system}_{error_type}` (e.g., `binance_rate_limit_exceeded`) - For throttle rule lookup
**Message Canonical**: `{error_type}` (e.g., `api_rate_limit_exceeded`) - For message template

### Admin Throttle Key Suffix
**Rule**: Admin notifications append `_admin` suffix to throttle canonical
- User throttle key: `binance_rate_limit_exceeded`
- Admin throttle key: `binance_rate_limit_exceeded_admin`
- Prevents admin from being throttled when user just received notification
- Allows both user and admin to receive when `user_types=['admin', 'user']`

**Implementation**: Applied in SendsNotifications trait (line 796)
```php
// User notification
Throttler::using(NotificationService::class)
    ->withCanonical('binance_rate_limit_exceeded')
    ->for($user)
    ->execute(function () { ... });

// Admin notification (separate throttle key)
Throttler::using(NotificationService::class)
    ->withCanonical('binance_rate_limit_exceeded_admin')
    ->execute(function () { ... });
```

### Usage
```php
Throttler::using(NotificationService::class)
    ->withCanonical('binance_rate_limit_exceeded')  // Throttle canonical
    ->execute(function () {
        $data = NotificationMessageBuilder::build('api_rate_limit_exceeded', ['exchange' => 'binance']);
        NotificationService::sendToUser(...);
    });
```

### Throttle Durations
**Seeder**: `database/seeders/ThrottleRulesSeeder.php`

- **Supervisor restarts**: 60s (was 120s)
- **WebSocket errors**: 900s / 15 min (was 1800s)
- **IP not whitelisted**: 900s / 15 min (was 1800s)
- **API rate limits**: 1800s / 30 min (was 3600s)
- **Connection failures**: 900s / 15 min (was 1800s)
- **Exchange maintenance**: 3600s / 1 hour (was 7200s)
- **Credential issues**: 1800s / 30 min (was 3600s)

**Rationale**: Reduced by 50% for faster notification response while still preventing spam

## Message Patterns

### Design Principles
1. **Audience-appropriate**: Admin messages are technical and direct with executable commands; user messages are clear and actionable
2. **No tutorials**: State what needs to be done, not why or how in detail
3. **Direct language**: Remove jargon like "optimization needed", avoid hedging like "no rush"
4. **Copy-paste friendly**: Important data (IPs, account names, commands) on separate lines using `[COPY]` and `[CMD]` markers
5. **Context-aware**: Only include relevant information (e.g., no server IP for account-level issues)
6. **Personalized**: Template handles "Hello {name}," salutation
7. **Actionable commands**: Admin notifications include specific supervisor/system commands using `[CMD]` markers
8. **Exception context**: WebSocket errors and system failures include exception messages for debugging

### Account Info Format
For rate limits and errors: `Account ID: 5 (John Trader / Binance)` - includes exchange

## Configuration

### Environment Variables

**Zeptomail Configuration** (`.env`):
```env
# Zeptomail API credentials
ZEPTOMAIL_MAIL_KEY=your-encrypted-api-key-here

# Zeptomail endpoint (default: https://api.zeptomail.com)
ZEPTO_MAIL_ENDPOINT=https://api.zeptomail.com

# HTTP client settings
ZEPTO_MAIL_TIMEOUT=30
ZEPTO_MAIL_RETRIES=2
ZEPTO_MAIL_RETRY_MS=200

# Email tracking
ZEPTO_MAIL_TRACK_OPENS=true
ZEPTO_MAIL_TRACK_CLICKS=true

# Webhook security
ZEPTOMAIL_WEBHOOK_SECRET=your-webhook-secret-here
```

**Pushover Configuration** (`.env`):
```env
# Pushover application key
ADMIN_USER_PUSHOVER_APPLICATION_KEY=your-pushover-app-key

# Pushover user key (if not using delivery groups)
ADMIN_USER_PUSHOVER_USER_KEY=your-pushover-user-key
```

**Mail Configuration** (`config/mail.php`):
```php
'mailers' => [
    'zeptomail' => [
        'transport' => 'zeptomail',
    ],
],

'from' => [
    'address' => env('MAIL_FROM_ADDRESS', 'noreply@martingalian.com'),
    'name' => env('MAIL_FROM_NAME', 'Martingalian'),
],
```

**Services Configuration** (`config/services.php`):
```php
'zeptomail' => [
    'mail_key' => env('ZEPTOMAIL_MAIL_KEY'),
    'endpoint' => env('ZEPTO_MAIL_ENDPOINT', 'https://api.zeptomail.com'),
    'timeout' => env('ZEPTO_MAIL_TIMEOUT', 30),
    'retries' => env('ZEPTO_MAIL_RETRIES', 2),
    'retry_sleep_ms' => env('ZEPTO_MAIL_RETRY_MS', 200),
    'template_key' => env('ZEPTO_MAIL_TEMPLATE_KEY'),
    'template_alias' => env('ZEPTO_MAIL_TEMPLATE_ALIAS'),
    'bounce_address' => env('ZEPTO_MAIL_BOUNCE_ADDRESS'),
    'track_opens' => env('ZEPTO_MAIL_TRACK_OPENS', true),
    'track_clicks' => env('ZEPTO_MAIL_TRACK_CLICKS', true),
    'client_reference' => env('ZEPTO_MAIL_CLIENT_REFERENCE'),
    'force_batch' => env('ZEPTO_MAIL_FORCE_BATCH', false),
],

'pushover' => [
    'token' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
],
```

**Webhook Configuration** (`config/martingalian.php`):
```php
'api' => [
    'webhooks' => [
        // Zeptomail webhook secret for HMAC signature verification
        // Get from: Zeptomail Dashboard > Settings > Webhooks > Secret Key
        'zeptomail_secret' => env('ZEPTOMAIL_WEBHOOK_SECRET'),

        // Pushover callback URL for emergency-priority receipt acknowledgment
        'pushover_callback' => env('APP_URL').'/api/webhooks/pushover/receipt',
    ],

    'pushover' => [
        'delivery_groups' => [
            'exceptions' => [
                'group_key' => env('PUSHOVER_DELIVERY_GROUP_EXCEPTIONS'),
                'priority' => 2, // Emergency
            ],
            'default' => [
                'group_key' => env('PUSHOVER_DELIVERY_GROUP_DEFAULT'),
                'priority' => 0, // Normal
            ],
            'indicators' => [
                'group_key' => env('PUSHOVER_DELIVERY_GROUP_INDICATORS'),
                'priority' => 0, // Normal
            ],
        ],
    ],
],
```

### Webhook Setup

**Zeptomail Webhook Configuration**:
1. Log into Zeptomail Dashboard
2. Navigate to: Settings > Webhooks
3. Create new webhook:
   - **URL**: `https://yourdomain.com/api/webhooks/zeptomail/events`
   - **Events**: Select all: `email_open`, `email_link_click`, `hardbounce`, `softbounce`
   - **Secret Key**: Copy the secret and add to `.env` as `ZEPTOMAIL_WEBHOOK_SECRET`
4. Test webhook delivery

**Pushover Callback Configuration**:
- Callback URL automatically passed with emergency-priority notifications
- URL: `https://yourdomain.com/api/webhooks/pushover/receipt`
- Used for emergency notification acknowledgment tracking

### Event Listener Registration

**Location**: `bootstrap/providers.php`

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class, // Registers NotificationLogListener
];
```

**Event Service Provider** (`app/Providers/EventServiceProvider.php`):
```php
protected $subscribe = [
    \Martingalian\Core\Listeners\NotificationLogListener::class,
];
```

### Database Tables

**notification_logs** - Created via migration
- Indexes on: `canonical`, `channel`, `message_id`, `status`, `sent_at`, `relatable_type + relatable_id`
- Stores full audit trail for all notifications
- Updated by webhooks for delivery confirmation

**throttle_logs** - For notification throttling
- Prevents duplicate notifications within time window
- Cleaned up periodically (old entries deleted)

## Testing

### Integration Tests
**Notification Behavior Tests**:
- `tests/Integration/Observers/ApiRequestLogObserverNotificationTest.php` - API error notification flow
- `tests/Integration/Mail/AlertNotificationEmailTest.php` - Email rendering and components

**Throttling Tests**:
- `tests/Feature/Support/NotificationThrottlerTest.php` - Comprehensive throttling behavior (18 tests)
  - Throttle window enforcement
  - Per-user throttling independence
  - Admin throttling segregation (separate throttle keys)
  - Auto-create throttle rules
  - User activation and config checks

**Key Test Coverage**:
- ✅ Dual user type notifications (both admin and user receive when `user_types=['admin', 'user']`)
- ✅ Admin throttle key segregation (admin not throttled when user just received notification)
- ✅ Email components, security (HTML escaping), formatting
- ✅ Server IP removal from admin email subjects
- ✅ Exchange name display using ApiSystem->name

**Driver**: Log mail driver (validates rendering without sending)

### Preventing Real Pushover
`IntegrationTestCase` prevents real Pushover via:
1. Fake token in config
2. Mocked Guzzle client (100 queued responses)
3. Http facade fake for `sendDirectToPushover`
4. Does NOT use `Notification::fake()` (emails still render to log)

## Common Canonicals

### Throttle Canonicals (per-exchange)
Format: `{exchange}_{error_type}` - Used in `Throttler::withCanonical()`
- `binance_api_rate_limit_exceeded`, `binance_api_connection_failed`
- `bybit_api_rate_limit_exceeded`, `bybit_api_connection_failed`
- `taapi_api_rate_limit_exceeded`, `coinmarketcap_api_rate_limit_exceeded`

### Message Canonicals (base, reusable)
Used in `NotificationMessageBuilder::build()` - 30+ available templates

**Server-Related** (includes server IP/hostname):
- `ip_not_whitelisted` → ['user'] - Server IP needs whitelisting on exchange
- `api_rate_limit_exceeded` → ['admin'] - Rate limit hit, operational
- `api_connection_failed` → ['admin'] - Network connectivity from server
- `api_system_error` → ['admin'] - Server-side timeout/system error
- `api_network_error` → ['admin'] - Network issues from server
- `api_access_denied` → ['user'] - May be IP-related access issue
- `exchange_maintenance` → ['user'] - Exchange down for maintenance
- `stale_price_detected` → ['admin'] - Price data not updating
- `forbidden_hostname_added` → ['admin'] - Server IP banned by exchange

**Account-Related** (NO server IP):
- `invalid_api_credentials` → ['user'] - API key invalid/expired
- `account_in_liquidation` → ['user'] - Exchange account in liquidation
- `account_reduce_only_mode` → ['user'] - Exchange account restricted
- `account_trading_banned` → ['user'] - Exchange account ban
- `kyc_verification_required` → ['user'] - Exchange account verification needed
- `account_unauthorized` → ['user'] - Exchange permission issue
- `insufficient_permissions` → ['user'] - API key lacks required permissions

**Trading & Monitoring**:
- `pnl_alert` → ['user'] - Trading performance notification

## Email Template Details

### Blade Template Processing
**Location**: `resources/views/emails/notification.blade.php`

**Special Markup Rendering**:
```php
// [COPY]text[/COPY] converts to prominent, selectable IP display
$processedMessage = preg_replace_callback(
    '/\[COPY\](.*?)\[\/COPY\]/s',
    function($matches) {
        $text = trim($matches[1]);
        return '<div class="ip-address">' . e($text) . '</div>';
    },
    $notificationMessage
);

// [CMD]command[/CMD] converts to styled command blocks
$processedMessage = preg_replace_callback(
    '/\[CMD\](.*?)\[\/CMD\]/s',
    function($matches) {
        $text = trim($matches[1]);
        return '<div class="command-block">' . e($text) . '</div>';
    },
    $processedMessage
);
```

**CSS Styling**:
```css
.ip-address {
    font-family: 'Courier New', Courier, monospace;
    font-size: 20px;
    font-weight: 700;
    user-select: all;  /* Easy to select/copy */
}

.command-block {
    font-family: 'Courier New', Courier, Consolas, Monaco, monospace;
    font-size: 14px;
    font-weight: 700;
    background-color: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-left: 4px solid #3b82f6;
    padding: 12px 16px;
    user-select: all;  /* Easy to select/copy */
}
```

**Components**:
- Severity badge with dynamic background color and icon
- User salutation with name
- Message body with newline support (`nl2br`)
- Action button (if actionUrl provided)
- Footer with timestamp and support email
- Responsive design for mobile devices

## Design Rules

1. **Email subject (user notifications)**: NO hostname prefix, MAY include server IP/exchange for server-specific issues
2. **Email subject (admin notifications)**: NO hostname prefix, NO server IP/exchange (clean, focused subjects)
3. **Email footer**: Includes hostname and timestamp
4. **Pushover title**: WITH hostname `[hostname] Title`
5. **Pushover message**: NO server IPs (cleaner mobile alerts - IPs only in email body)
6. **Salutation**: Template handles "Hello {name}," (not in NotificationMessageBuilder)
7. **Data formatting**: Important data on separate lines with minimal padding
8. **Server IP context (admin)**: Never in email subject, included in email body when relevant
9. **Server IP context (user)**: May appear in email subject for server-specific issues (e.g., IP whitelisting)
10. **Exchange name display**: Use `ApiSystem->name` from database, not `ucfirst(canonical)`
11. **Priority headers**: Critical/High severity get email priority headers
12. **Delivery**: Immediate (NOT queued) for routing access
13. **Testing**: Real rendering via log driver (not `Mail::fake()`)
14. **Routing**: Operational errors to admin, actionable errors to user
15. **Dual user types**: When `user_types=['admin', 'user']`, both receive notification (separate throttle keys)
16. **Throttle key segregation**: Admin uses `{canonical}_admin` to prevent cross-throttling with user
17. **IP highlighting**: Use `[COPY]IP[/COPY]` for IP addresses to make them prominent and easy to copy in emails
18. **Command display**: Use `[CMD]command[/CMD]` for system commands (supervisor, SQL queries, bash) in admin emails
19. **Supervisor processes**: Reference correct process names: `update-binance-prices`, `update-bybit-prices`
20. **Exception context**: Pass exception messages via context array for WebSocket/system errors
