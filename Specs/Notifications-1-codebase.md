# Notifications System - Codebase Examples (Part 1)

This document provides **real-world implementation examples** from the actual codebase showing how the Notifications system is used in practice.

## Overview

The Notifications spec (Notifications-1.md) describes the architecture and components. This document shows **how it's actually implemented** with concrete code examples extracted from production code.

---

## Table of Contents

1. [Standard Notification Pattern](#standard-notification-pattern)
2. [API Error Notifications (SendsNotifications)](#api-error-notifications-sendsnotifications)
3. [Job-Based Notifications](#job-based-notifications)
4. [Building Messages with NotificationMessageBuilder](#building-messages-with-notificationmessagebuilder)
5. [Throttling Notifications](#throttling-notifications)
6. [User vs Admin Notifications](#user-vs-admin-notifications)

---

## Standard Notification Pattern

**Pattern used throughout the codebase:**

```php
// 1. Build message data from template
$messageData = NotificationMessageBuilder::build(
    canonical: 'notification_canonical',
    context: [
        'exchange' => $apiSystem,
        'ip' => $serverIp,
        'hostname' => $hostname,
        // ... other context variables
    ]
);

// 2. Throttle and send with unpacked data
Throttler::using(NotificationService::class)
    ->withCanonical($throttleCanonical)
    ->execute(function () use ($messageData) {
        NotificationService::send(
            user: Martingalian::admin(),
            message: $messageData['emailMessage'],
            title: $messageData['title'],
            canonical: 'notification_canonical',
            deliveryGroup: 'exceptions',
            severity: $messageData['severity'],
            pushoverMessage: $messageData['pushoverMessage'],
            actionUrl: $messageData['actionUrl'],
            actionLabel: $messageData['actionLabel']
        );
    });
```

**Key Points:**
- **Step 1**: Build message from template using `NotificationMessageBuilder::build()`
- **Step 2**: Wrap in `Throttler` to prevent spam
- **Step 3**: Call `NotificationService::send()` with unpacked `$messageData` array
- **Never**: Call `send()` directly with raw message strings (bypasses templates)

---

## API Error Notifications (SendsNotifications)

### Location
`packages/martingalian/core/src/Concerns/ApiRequestLog/SendsNotifications.php`

### Trigger Point
**ApiRequestLogObserver** triggers notifications when API requests are saved:

```php
// packages/martingalian/core/src/Observers/ApiRequestLogObserver.php
final class ApiRequestLogObserver
{
    public function saved(ApiRequestLog $log): void
    {
        // Delegate to the model's notification logic
        $log->sendNotificationIfNeeded();
    }
}
```

### Entry Point: sendNotificationIfNeeded()

```php
// From: SendsNotifications trait (line 31)
public function sendNotificationIfNeeded(): void
{
    // Skip if no HTTP response code yet (request still in progress)
    if ($this->http_response_code === null) {
        return;
    }

    // Skip if successful response (2xx or 3xx)
    if ($this->http_response_code < 400) {
        return;
    }

    // Load API system to determine which exception handler to use
    $apiSystem = ApiSystem::find($this->api_system_id);
    if (!$apiSystem) {
        return;
    }

    // Create the appropriate exception handler for HTTP code analysis
    $handler = BaseExceptionHandler::make($apiSystem->canonical);

    // Case 1: User-level API call (has account_id)
    if ($this->account_id) {
        $account = Account::find($this->account_id);
        if ($account) {
            $this->sendUserNotification($handler, $account);
        }
        return;
    }

    // Case 2: System-level API call (account_id is NULL)
    $this->sendAdminNotification($handler);
}
```

---

### Example 1: Rate Limit Error (User Notification)

**From: SendsNotifications::sendUserNotification() (line 81)**

```php
// 429 / 418 / 403 - Rate Limit
if ($handler->isRateLimitedFromLog($httpCode, $vendorCode)) {
    $this->sendThrottledNotification(
        handler: $handler,
        account: $account,
        hasSpecificUser: $hasSpecificUser,
        messageCanonical: 'api_rate_limit_exceeded',
        hostname: $hostname
    );
    return;
}
```

**How sendThrottledNotification() works (line 696):**

```php
protected function sendThrottledNotification(
    BaseExceptionHandler $handler,
    Account $account,
    bool $hasSpecificUser,
    string $messageCanonical,
    string $hostname,
    bool $disableAccount = false
): void {
    // Check who should receive this notification type
    $notification = Notification::findByCanonical($messageCanonical);
    if (!$notification) {
        return;
    }

    // Disable account if this is a critical error
    if ($disableAccount) {
        $account->update([
            'can_trade' => false,
            'disabled_reason' => $messageCanonical,
            'disabled_at' => now(),
        ]);
    }

    $userTypes = $notification->user_types ?? ['user'];
    $shouldSendToUser = in_array('user', $userTypes, true);
    $shouldSendToAdmin = in_array('admin', $userTypes, true);

    // Throttle canonical: Prefixed with API system
    $throttleCanonical = $handler->getApiSystem().'_'.$messageCanonical;

    // Fetch API system model
    $exchangeCanonical = $handler->getApiSystem();
    $apiSystem = ApiSystem::where('canonical', $exchangeCanonical)->first();
    $exchange = $apiSystem ? $apiSystem->name : ucfirst($exchangeCanonical);

    // Build account info string
    $accountInfo = "Account ID: {$account->id}";
    if ($account->user) {
        $accountInfo = "Account ID: {$account->id} ({$account->user->name} / {$exchange})";
    }

    // Determine if this notification is server-related
    $serverRelatedCanonicals = [
        'api_rate_limit_exceeded',
        'api_connection_failed',
        'ip_not_whitelisted',
        // ... more canonicals
    ];
    $isServerRelated = in_array($messageCanonical, $serverRelatedCanonicals, true);

    // Build context
    $context = [
        'exchange' => $exchangeCanonical,
        'account_name' => $exchange.' Account #'.$account->id,
        'account_info' => $accountInfo,
    ];

    if ($isServerRelated) {
        $context['ip'] = Martingalian::ip();
        $context['hostname'] = $hostname;
    }

    // 🔥 BUILD MESSAGE FROM TEMPLATE
    $messageData = NotificationMessageBuilder::build(
        canonical: $messageCanonical,
        context: $context,
        user: $hasSpecificUser ? $account->user : null
    );

    // Send to user if appropriate
    if ($shouldSendToUser && $hasSpecificUser) {
        $user = $account->user;
        $serverIp = $isServerRelated ? Martingalian::ip() : null;

        Throttler::using(NotificationService::class)
            ->withCanonical($throttleCanonical)
            ->for($user)
            ->execute(function () use ($user, $messageData, $messageCanonical, $exchange, $serverIp) {
                NotificationService::send(
                    user: $user,
                    message: $messageData['emailMessage'],
                    title: $messageData['title'],
                    canonical: $messageCanonical,
                    deliveryGroup: null,  // Users don't use delivery groups
                    severity: $messageData['severity'],
                    pushoverMessage: $messageData['pushoverMessage'],
                    actionUrl: $messageData['actionUrl'],
                    actionLabel: $messageData['actionLabel'],
                    exchange: $exchange,
                    serverIp: $serverIp
                );
            });
    }

    // Send to admin if appropriate
    if ($shouldSendToAdmin) {
        Throttler::using(NotificationService::class)
            ->withCanonical($throttleCanonical.'_admin')
            ->execute(function () use ($messageData, $messageCanonical) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: $messageData['emailMessage'],
                    title: $messageData['title'],
                    canonical: $messageCanonical,
                    deliveryGroup: 'exceptions',
                    severity: $messageData['severity'],
                    pushoverMessage: $messageData['pushoverMessage'],
                    actionUrl: $messageData['actionUrl'],
                    actionLabel: $messageData['actionLabel']
                    // NOTE: NO exchange/serverIp for admin (clean email subjects)
                );
            });
    }
}
```

---

### Example 2: Invalid API Credentials (Admin Notification)

**From: SendsNotifications::sendAdminNotification() (line 336)**

```php
// Binance ambiguous error -2015 (could be credentials, IP, or permissions)
if ($handler->isCredentialsOrIpErrorFromLog($vendorCode)) {
    // 1. Build message data from template
    $messageData = NotificationMessageBuilder::build(
        canonical: 'invalid_api_credentials',
        context: [
            'exchange' => $apiSystem,
            'ip' => $serverIp,
            'hostname' => $hostname,
            'account_info' => 'System-level API call (admin account)',
            'account_name' => 'Admin Account',
        ]
    );

    $throttleCanonical = $apiSystem.'_invalid_api_credentials';

    // 2. Throttle and send
    Throttler::using(NotificationService::class)
        ->withCanonical($throttleCanonical)
        ->execute(function () use ($messageData, $apiSystem, $serverIp) {
            NotificationService::send(
                user: Martingalian::admin(),
                message: $messageData['emailMessage'],
                title: $messageData['title'],
                canonical: 'invalid_api_credentials',
                deliveryGroup: 'exceptions',
                severity: $messageData['severity'],
                pushoverMessage: $messageData['pushoverMessage'],
                actionUrl: $messageData['actionUrl'],
                actionLabel: $messageData['actionLabel'],
                exchange: $apiSystem,
                serverIp: $serverIp
            );
        });

    return;
}
```

---

### Example 3: IP Not Whitelisted

**From: SendsNotifications::sendUserNotification() (line 149)**

```php
// Bybit specific: IP Not Whitelisted (10010)
if ($handler->isIpNotWhitelistedFromLog($vendorCode)) {
    $this->sendThrottledNotification(
        handler: $handler,
        account: $account,
        hasSpecificUser: $hasSpecificUser,
        messageCanonical: 'ip_not_whitelisted',
        hostname: $hostname
        // disableAccount = false (default, don't disable - other servers can continue)
    );
    return;
}
```

This flows through `sendThrottledNotification()` which:
1. Checks if `ip_not_whitelisted` is server-related (yes)
2. Includes `ip` and `hostname` in context
3. Builds message using `NotificationMessageBuilder::build()`
4. Sends to user AND admin (if configured in notification's `user_types`)

---

## Job-Based Notifications

### Example: Price Spike Check Error

**Location**: `packages/martingalian/core/src/Jobs/Models/ExchangeSymbol/CheckPriceSpikeAndCooldownJob.php` (line 88)

**Context**: Batch job processing symbols, encounters error during price spike detection

```php
try {
    // ... price spike detection logic ...
} catch (Throwable $e) {
    $summary['errors']++;

    // 1. Build message data from template
    $messageData = NotificationMessageBuilder::build(
        canonical: 'price_spike_check_symbol_error',
        context: [
            'message' => "[{$ex->id}] {$ex->parsed_trading_pair} - ".ExceptionParser::with($e)->friendlyMessage(),
        ]
    );

    // 2. Throttle and send
    Throttler::using(NotificationService::class)
        ->withCanonical('price_spike_check_symbol_error')
        ->execute(function () use ($messageData) {
            NotificationService::send(
                user: Martingalian::admin(),
                message: $messageData['emailMessage'],
                title: $messageData['title'],
                canonical: 'price_spike_check_symbol_error',
                deliveryGroup: 'exceptions',
                severity: $messageData['severity'],
                pushoverMessage: $messageData['pushoverMessage'],
                actionUrl: $messageData['actionUrl'],
                actionLabel: $messageData['actionLabel']
            );
        });

    $summary['details'][] = [
        'symbol_id' => $ex->id,
        'pair' => (string) $ex->parsed_trading_pair,
        'error' => $e->getMessage(),
    ];
}
```

**Key Details:**
- Uses `context['message']` to pass custom error details
- Template in NotificationMessageBuilder falls back to this custom message
- Admin-only notification (no user involved)
- Throttled to prevent spam (900 seconds / 15 minutes)

---

## Building Messages with NotificationMessageBuilder

### Basic Template Usage

**From: NotificationMessageBuilder::build() (line 68)**

```php
return match ($canonicalString) {
    'price_spike_check_symbol_error' => [
        'severity' => NotificationSeverity::Medium,
        'title' => 'Price Spike Check Error',
        'emailMessage' => is_string($context['message'] ?? null)
            ? $context['message']
            : 'An error occurred during batch price spike detection. The symbol may be missing required candle data or there was a calculation error.',
        'pushoverMessage' => is_string($context['message'] ?? null)
            ? $context['message']
            : 'Price spike check failed for symbol',
        'actionUrl' => null,
        'actionLabel' => null,
    ],

    'ip_not_whitelisted' => [
        'severity' => NotificationSeverity::High,
        'title' => 'Server IP Needs Whitelisting',
        'emailMessage' => "⚠️ Action Required\n\nOne of our worker servers is not whitelisted on your {$exchangeTitle} account.\nServer IP: [COPY]{$ip}[/COPY]\n\n...",
        'pushoverMessage' => "⚠️ Whitelist server IP on {$exchangeTitle}",
        'actionUrl' => self::getApiManagementUrl($exchange),
        'actionLabel' => 'Go to API Management',
    ],

    // ... more templates ...

    default => [
        'severity' => NotificationSeverity::Info,
        'title' => 'System Notification',
        'emailMessage' => is_string($context['message'] ?? null)
            ? $context['message']
            : 'A system event occurred that requires your attention.',
        'pushoverMessage' => is_string($context['message'] ?? null)
            ? $context['message']
            : 'System notification',
        'actionUrl' => null,
        'actionLabel' => null,
    ],
};
```

### Context Variable Extraction

**From: NotificationMessageBuilder::build() (line 40)**

```php
// Extract common context variables with type safety
$userName = $user !== null ? $user->name : 'there';

$exchangeRaw = $context['exchange'] ?? 'exchange';
$exchange = is_string($exchangeRaw) ? $exchangeRaw : 'exchange';
$exchangeTitle = ucfirst($exchange);

$ipRaw = $context['ip'] ?? 'unknown';
$ip = is_string($ipRaw) ? $ipRaw : 'unknown';

$hostnameRaw = $context['hostname'] ?? gethostname();
$hostname = is_string($hostnameRaw) ? $hostnameRaw : (string) gethostname();

// ... more extractions ...

// Then use these in the match statement templates
```

---

## Throttling Notifications

### Per-User Throttling

```php
Throttler::using(NotificationService::class)
    ->withCanonical('binance_api_rate_limit_exceeded')
    ->for($user)  // 🔥 Scope to specific user
    ->execute(function () use ($user, $messageData) {
        NotificationService::send(
            user: $user,
            message: $messageData['emailMessage'],
            // ... rest
        );
    });
```

### Global Throttling (Admin)

```php
Throttler::using(NotificationService::class)
    ->withCanonical('price_spike_check_symbol_error')
    // No ->for() = global throttle
    ->execute(function () use ($messageData) {
        NotificationService::send(
            user: Martingalian::admin(),
            message: $messageData['emailMessage'],
            // ... rest
        );
    });
```

### Separate User/Admin Throttles

**From: SendsNotifications::sendThrottledNotification() (line 781, 806)**

```php
// User throttle
if ($shouldSendToUser && $hasSpecificUser) {
    Throttler::using(NotificationService::class)
        ->withCanonical($throttleCanonical)  // e.g., "binance_api_rate_limit_exceeded"
        ->for($user)
        ->execute(function () { /* send to user */ });
}

// Admin throttle (separate key to avoid conflicts)
if ($shouldSendToAdmin) {
    Throttler::using(NotificationService::class)
        ->withCanonical($throttleCanonical.'_admin')  // e.g., "binance_api_rate_limit_exceeded_admin"
        ->execute(function () { /* send to admin */ });
}
```

**Why separate keys?**
- User gets notified → user's throttle window starts
- Admin should ALSO get notified (independent throttle)
- Adding `_admin` suffix prevents user's throttle from blocking admin notification

---

## User vs Admin Notifications

### User Notification

```php
NotificationService::send(
    user: $user,                         // Real User model
    message: $messageData['emailMessage'],
    title: $messageData['title'],
    canonical: 'api_rate_limit_exceeded',
    deliveryGroup: null,                 // 🔥 NULL for users (no delivery groups)
    severity: $messageData['severity'],
    pushoverMessage: $messageData['pushoverMessage'],
    actionUrl: $messageData['actionUrl'],
    actionLabel: $messageData['actionLabel'],
    exchange: 'Binance',                 // 🔥 Exchange name for email subject
    serverIp: '1.2.3.4'                  // 🔥 Server IP for email subject
);
```

**Email Subject Example**: `"API Rate Limit - Server 1.2.3.4 on Binance"`

### Admin Notification

```php
NotificationService::send(
    user: Martingalian::admin(),         // Virtual admin user
    message: $messageData['emailMessage'],
    title: $messageData['title'],
    canonical: 'api_rate_limit_exceeded',
    deliveryGroup: 'exceptions',         // 🔥 Delivery group for admin routing
    severity: $messageData['severity'],
    pushoverMessage: $messageData['pushoverMessage'],
    actionUrl: $messageData['actionUrl'],
    actionLabel: $messageData['actionLabel']
    // 🔥 NO exchange/serverIp = clean email subject
);
```

**Email Subject Example**: `"API Rate Limit"` (clean, no server context)

**Server context** (hostname, IP) is in the email **body**, not the subject.

---

## Anti-Patterns to Avoid

### ❌ WRONG: Bypassing NotificationMessageBuilder

```php
// DON'T DO THIS
NotificationService::send(
    user: Martingalian::admin(),
    message: "Rate limit exceeded on Binance!",  // ❌ Raw message
    title: 'API Error',                          // ❌ Raw title
    canonical: 'api_rate_limit_exceeded',
    deliveryGroup: 'exceptions'
);
```

**Why wrong?**
- Bypasses message templates
- No severity, actionUrl, or pushoverMessage
- Inconsistent formatting across notifications
- Harder to maintain

### ✅ CORRECT: Using NotificationMessageBuilder

```php
// DO THIS
$messageData = NotificationMessageBuilder::build(
    canonical: 'api_rate_limit_exceeded',
    context: ['exchange' => 'binance']
);

Throttler::using(NotificationService::class)
    ->withCanonical('binance_api_rate_limit_exceeded')
    ->execute(function () use ($messageData) {
        NotificationService::send(
            user: Martingalian::admin(),
            message: $messageData['emailMessage'],
            title: $messageData['title'],
            canonical: 'api_rate_limit_exceeded',
            deliveryGroup: 'exceptions',
            severity: $messageData['severity'],
            pushoverMessage: $messageData['pushoverMessage'],
            actionUrl: $messageData['actionUrl'],
            actionLabel: $messageData['actionLabel']
        );
    });
```

---

## Summary

**Standard Pattern:**
1. Build message: `$messageData = NotificationMessageBuilder::build(canonical, context)`
2. Throttle: `Throttler::using()->withCanonical()->execute()`
3. Send: `NotificationService::send()` with unpacked `$messageData`

**Key Files:**
- `SendsNotifications.php` - API error notification logic (most comprehensive examples)
- `CheckPriceSpikeAndCooldownJob.php` - Job-based notification example
- `NotificationMessageBuilder.php` - All message templates

**Never:**
- Call `send()` with raw message strings
- Skip throttling (causes notification spam)
- Use delivery groups for user notifications (only for admin)
