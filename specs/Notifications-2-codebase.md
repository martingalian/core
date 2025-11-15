# Notifications System - Codebase Examples (Part 2)

This document provides **real-world implementation examples** for Zeptomail integration, webhook handling, and delivery tracking.

## Overview

Notifications-2 spec covers Zeptomail integration and webhook processing. This document shows the actual implementation with code examples from production.

---

## Table of Contents

1. [Webhook Entry Points](#webhook-entry-points)
2. [Zeptomail Webhook Processing](#zeptomail-webhook-processing)
3. [Email Open Tracking](#email-open-tracking)
4. [Bounce Handling](#bounce-handling)
5. [Pushover Receipt Acknowledgment](#pushover-receipt-acknowledgment)
6. [Signature Verification](#signature-verification)

---

## Webhook Entry Points

### Routes

```php
// From: routes/api.php or web.php
Route::post('/api/webhooks/zeptomail/events', [NotificationWebhookController::class, 'zeptomail'])
    ->name('webhooks.zeptomail');

Route::post('/api/webhooks/pushover/receipt', [NotificationWebhookController::class, 'pushover'])
    ->name('webhooks.pushover');
```

### Controller Location
`packages/martingalian/core/src/Http/Controllers/Webhooks/NotificationWebhookController.php`

---

## Zeptomail Webhook Processing

### Entry Point: zeptomail() Method

**From: NotificationWebhookController (line 32)**

```php
public function zeptomail(Request $request): JsonResponse
{
    try {
        // DEBUG: Log raw webhook received
        Log::info('[ZEPTOMAIL WEBHOOK] === RAW WEBHOOK RECEIVED ===', [
            'raw_body' => $request->getContent(),
            'all_headers' => $request->headers->all(),
            'ip' => $request->ip(),
        ]);

        // Verify webhook signature for security
        if (!$this->verifyZeptomailSignature($request)) {
            Log::warning('[ZEPTOMAIL WEBHOOK] Invalid signature', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        Log::info('[ZEPTOMAIL WEBHOOK] ✓ Signature verified successfully');

        // Zeptomail sends events as JSON
        $payload = $request->json()->all();

        // Extract event type and event data (both are arrays)
        $eventNames = $payload['event_name'] ?? null;
        $eventMessages = $payload['event_message'] ?? null;

        if (!is_array($eventNames) || !is_array($eventMessages) ||
            count($eventNames) === 0 || count($eventMessages) === 0) {
            // Zeptomail requires status 200 even for errors
            return response()->json(['status' => 'error'], 200);
        }

        // Get first event
        $eventType = $eventNames[0];
        $eventData = $eventMessages[0];

        Log::info('[ZEPTOMAIL WEBHOOK] Processing event', [
            'event_type' => $eventType,
            'event_data' => $eventData,
        ]);

        // Process based on event type
        match ($eventType) {
            'hardbounce' => $this->handleZeptomailBounce($request, $eventData, 'hard_bounce'),
            'softbounce' => $this->handleZeptomailBounce($request, $eventData, 'soft_bounce'),
            'email_open' => $this->handleZeptomailOpen($request, $eventData),
            'email_link_click' => $this->handleZeptomailClick($request, $eventData),
            default => Log::info('[ZEPTOMAIL WEBHOOK] Unhandled event type', ['event_type' => $eventType]),
        };

        return response()->json(['status' => 'success'], 200);
    } catch (Throwable $e) {
        Log::error('[ZEPTOMAIL WEBHOOK] Exception occurred', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Zeptomail requires status 200 even for errors
        return response()->json(['status' => 'error'], 200);
    }
}
```

**Key Points:**
- **Always returns 200** - Zeptomail requires HTTP 200 even for errors
- **Signature verification first** - Security before processing
- **Match expression** - Clean event routing
- **Extensive logging** - Debug webhook issues

---

## Email Open Tracking

### handleZeptomailOpen() Method

**From: NotificationWebhookController (line 274)**

```php
private function handleZeptomailOpen(Request $request, array $data): void
{
    // DEBUG: Log complete event data structure
    Log::info('[ZEPTOMAIL WEBHOOK] === PROCESSING OPEN EVENT ===', [
        'full_data' => $data,
        'data_keys' => array_keys($data),
    ]);

    // Extract request_id from webhook payload
    $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

    // Extract open details from nested event_data array
    $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
    $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
    $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
    $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];

    // Extract timestamp when user opened the email
    $openedAt = is_string($details['time'] ?? null) ? $details['time'] : null;

    Log::info('[ZEPTOMAIL WEBHOOK] Extracted open event data', [
        'request_id' => $requestId,
        'opened_at' => $openedAt,
    ]);

    // Find notification log by message_id (stores request_id from API response)
    $notificationLog = null;
    if ($requestId) {
        $notificationLog = NotificationLog::where('message_id', $requestId)
            ->where('channel', 'mail')
            ->whereNull('opened_at')  // Only update if not already opened
            ->first();

        Log::info('[ZEPTOMAIL WEBHOOK] Searched by request_id (open)', [
            'request_id' => $requestId,
            'found' => $notificationLog !== null,
        ]);
    }

    // Fallback: try to match by recipient email if request_id not found
    if (!$notificationLog) {
        $emailInfo = is_array($data['email_info'] ?? null) ? $data['email_info'] : [];
        $toArray = is_array($emailInfo['to'] ?? null) ? $emailInfo['to'] : [];
        $toFirst = is_array($toArray[0] ?? null) ? $toArray[0] : [];
        $emailAddress = is_array($toFirst['email_address'] ?? null) ? $toFirst['email_address'] : [];
        $recipientEmail = is_string($emailAddress['address'] ?? null) ? $emailAddress['address'] : null;

        if ($recipientEmail !== null) {
            $notificationLog = NotificationLog::where('channel', 'mail')
                ->where('recipient', $recipientEmail)
                ->whereNull('opened_at')  // Only update if not already opened
                ->orderBy('sent_at', 'desc')
                ->first();

            Log::info('[ZEPTOMAIL WEBHOOK] Searched by email fallback (open)', [
                'email' => $recipientEmail,
                'found' => $notificationLog !== null,
            ]);
        }
    }

    if (!$notificationLog) {
        Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for open event', [
            'request_id' => $requestId,
        ]);
        return;
    }

    // Use timestamp from webhook payload, or fallback to now
    $openedAtTimestamp = $openedAt !== null
        ? \Carbon\Carbon::parse($openedAt)
        : now();

    Log::info('[ZEPTOMAIL WEBHOOK] About to update notification log', [
        'notification_log_id' => $notificationLog->id,
        'opened_at' => $openedAtTimestamp,
    ]);

    // 🔥 UPDATE NOTIFICATION LOG
    $notificationLog->update([
        'opened_at' => $openedAtTimestamp,
        'status' => 'opened',
        'http_headers_received' => $request->headers->all(),
        'gateway_response' => array_merge(
            $notificationLog->gateway_response ?? [],
            ['open_event' => $data]
        ),
    ]);

    Log::info('[ZEPTOMAIL WEBHOOK] ✓ Open event processed successfully', [
        'notification_log_id' => $notificationLog->id,
        'opened_at' => $openedAtTimestamp,
    ]);
}
```

**Key Points:**
- **Dual lookup strategy**: First by `request_id`, fallback to `recipient` email
- **Idempotent**: `whereNull('opened_at')` prevents duplicate processing
- **Timestamp preservation**: Uses webhook's timestamp, not server time
- **Event storage**: Appends event data to `gateway_response` JSON field
- **Extensive logging**: Every step logged for debugging

---

## Bounce Handling

### handleZeptomailBounce() Method

**From: NotificationWebhookController (line 172)**

```php
private function handleZeptomailBounce(Request $request, array $data, string $bounceType): void
{
    // Extract request_id from webhook payload
    $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

    // Extract bounce details from nested event_data array
    $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
    $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
    $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
    $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];

    $bounceReason = is_string($details['reason'] ?? null) ? $details['reason'] : 'Unknown';
    $diagnosticMessage = is_string($details['diagnostic_message'] ?? null) ? $details['diagnostic_message'] : null;
    $bouncedRecipient = is_string($details['bounced_recipient'] ?? null) ? $details['bounced_recipient'] : null;
    $bounceTime = is_string($details['time'] ?? null) ? $details['time'] : null;

    // Build comprehensive error message
    $errorMessage = $bounceReason;
    if ($diagnosticMessage !== null) {
        $errorMessage .= ' - ' . $diagnosticMessage;
    }
    if ($bouncedRecipient !== null) {
        $errorMessage .= ' (Recipient: ' . $bouncedRecipient . ')';
    }

    Log::info('[ZEPTOMAIL WEBHOOK] Handling bounce', [
        'bounce_type' => $bounceType,
        'request_id' => $requestId,
        'bounce_reason' => $bounceReason,
    ]);

    // Find notification log by message_id
    $notificationLog = null;
    if ($requestId !== null) {
        $notificationLog = NotificationLog::where('message_id', $requestId)
            ->where('channel', 'mail')
            ->first();
    }

    // Fallback: match by recipient email
    if (!$notificationLog) {
        $emailInfo = is_array($data['email_info'] ?? null) ? $data['email_info'] : [];
        $toArray = is_array($emailInfo['to'] ?? null) ? $emailInfo['to'] : [];
        $toFirst = is_array($toArray[0] ?? null) ? $toArray[0] : [];
        $emailAddress = is_array($toFirst['email_address'] ?? null) ? $toFirst['email_address'] : [];
        $recipientEmail = is_string($emailAddress['address'] ?? null) ? $emailAddress['address'] : null;

        if ($recipientEmail !== null) {
            $notificationLog = NotificationLog::where('channel', 'mail')
                ->where('recipient', $recipientEmail)
                ->orderBy('sent_at', 'desc')
                ->first();
        }
    }

    if (!$notificationLog) {
        Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for bounce', [
            'request_id' => $requestId,
        ]);
        return;
    }

    // Update status and bounce timestamp
    $status = $bounceType === 'hard_bounce' ? 'hard bounced' : 'soft bounced';
    $bounceTimestamp = $bounceTime ? \Carbon\Carbon::parse($bounceTime) : now();
    $bounceField = $bounceType === 'hard_bounce' ? 'hard_bounced_at' : 'soft_bounced_at';

    $notificationLog->update([
        'status' => $status,
        $bounceField => $bounceTimestamp,
        'error_message' => $errorMessage,
        'http_headers_received' => $request->headers->all(),
        'gateway_response' => array_merge(
            $notificationLog->gateway_response ?? [],
            ['bounce_event' => $data]
        ),
    ]);

    Log::info('[ZEPTOMAIL WEBHOOK] Bounce processed successfully', [
        'notification_log_id' => $notificationLog->id,
        'status' => $status,
    ]);
}
```

**Key Points:**
- **Bounce types**: Hard bounce (permanent failure) vs soft bounce (temporary)
- **Different fields**: `hard_bounced_at` vs `soft_bounced_at` columns
- **Error message building**: Combines reason + diagnostic + recipient
- **Status tracking**: `'hard bounced'` or `'soft bounced'` status
- **Dual lookup**: By `request_id` first, fallback to `recipient` email

---

## Pushover Receipt Acknowledgment

### pushover() Method

**From: NotificationWebhookController (line 133)**

```php
public function pushover(Request $request): JsonResponse
{
    try {
        // Pushover sends callbacks as form data
        $receipt = $request->input('receipt');
        $acknowledged = $request->input('acknowledged');
        $acknowledgedAt = $request->input('acknowledged_at');

        if (!$receipt) {
            return response()->json(['success' => false, 'message' => 'Missing receipt'], 200);
        }

        // Find notification log by receipt (stored in gateway_response)
        $notificationLog = NotificationLog::where('channel', 'pushover')
            ->where('gateway_response->receipt', $receipt)
            ->first();

        if (!$notificationLog) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 200);
        }

        // Update with acknowledgment timestamp
        if ($acknowledged && $acknowledgedAt !== null && is_numeric($acknowledgedAt)) {
            $notificationLog->update([
                'status' => 'delivered',
            ]);
        }

        return response()->json(['status' => 'success'], 200);
    } catch (Throwable $e) {
        return response()->json(['status' => 'error', 'message' => 'Internal error'], 200);
    }
}
```

**Key Points:**
- **Form data**: Pushover uses form encoding (not JSON like Zeptomail)
- **JSON query**: `gateway_response->receipt` - queries JSON column directly
- **Emergency acknowledgment**: Only for emergency-priority notifications
- **Status update**: Changes to `'delivered'` when acknowledged

---

## Signature Verification

### verifyZeptomailSignature() Method

**Pattern (typical implementation):**

```php
private function verifyZeptomailSignature(Request $request): bool
{
    // Get signature from header
    $signature = $request->header('X-Zeptomail-Signature');

    if (!$signature) {
        return false;
    }

    // Get secret from config
    $secret = config('martingalian.api.webhooks.zeptomail_secret');

    if (!$secret) {
        Log::error('[ZEPTOMAIL WEBHOOK] Missing webhook secret in config');
        return false;
    }

    // Get raw request body
    $payload = $request->getContent();

    // Calculate expected signature
    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    // Constant-time comparison to prevent timing attacks
    return hash_equals($expectedSignature, $signature);
}
```

**Key Points:**
- **HMAC-SHA256**: Standard secure signature algorithm
- **Header**: `X-Zeptomail-Signature`
- **Raw body**: Must use `getContent()`, not parsed JSON
- **Constant-time comparison**: `hash_equals()` prevents timing attacks
- **Config-driven**: Secret stored in `config/martingalian.php`

---

## Zeptomail Event Data Structure

### email_open Event

```json
{
  "event_name": ["email_open"],
  "event_message": [
    {
      "request_id": "abc123def456",
      "event_data": [
        {
          "details": [
            {
              "time": "2025-01-08 15:30:45",
              "user_agent": "Mozilla/5.0...",
              "ip_address": "1.2.3.4"
            }
          ]
        }
      ],
      "email_info": {
        "to": [
          {
            "email_address": {
              "address": "user@example.com",
              "name": "User Name"
            }
          }
        ]
      }
    }
  ]
}
```

### hardbounce / softbounce Event

```json
{
  "event_name": ["hardbounce"],
  "event_message": [
    {
      "request_id": "abc123def456",
      "event_data": [
        {
          "details": [
            {
              "reason": "Mailbox does not exist",
              "diagnostic_message": "550 5.1.1 User unknown",
              "bounced_recipient": "invalid@example.com",
              "time": "2025-01-08 15:30:45"
            }
          ]
        }
      ],
      "email_info": {
        "to": [
          {
            "email_address": {
              "address": "invalid@example.com"
            }
          }
        ]
      }
    }
  ]
}
```

---

## Database Updates

### NotificationLog Model Updates

**Fields updated by webhooks:**

```php
// Email open
$notificationLog->update([
    'opened_at' => Carbon::parse($openedAt),
    'status' => 'opened',
    'http_headers_received' => $request->headers->all(),
    'gateway_response' => array_merge($existing, ['open_event' => $data]),
]);

// Hard bounce
$notificationLog->update([
    'status' => 'hard bounced',
    'hard_bounced_at' => Carbon::parse($bounceTime),
    'error_message' => $errorMessage,
    'http_headers_received' => $request->headers->all(),
    'gateway_response' => array_merge($existing, ['bounce_event' => $data]),
]);

// Soft bounce
$notificationLog->update([
    'status' => 'soft bounced',
    'soft_bounced_at' => Carbon::parse($bounceTime),
    'error_message' => $errorMessage,
    'http_headers_received' => $request->headers->all(),
    'gateway_response' => array_merge($existing, ['bounce_event' => $data]),
]);

// Pushover acknowledgment
$notificationLog->update([
    'status' => 'delivered',
]);
```

---

## Testing Webhooks Locally

### Using ngrok or similar

```bash
# Start ngrok tunnel
ngrok http 8000

# Update Zeptomail webhook URL
# https://abc123.ngrok.io/api/webhooks/zeptomail/events

# Watch logs
tail -f storage/logs/laravel.log | grep "ZEPTOMAIL WEBHOOK"
```

### Manual Webhook Testing

```bash
# Simulate open event
curl -X POST http://localhost/api/webhooks/zeptomail/events \
  -H "Content-Type: application/json" \
  -H "X-Zeptomail-Signature: YOUR_SIGNATURE" \
  -d '{
    "event_name": ["email_open"],
    "event_message": [{
      "request_id": "test_request_id_123",
      "event_data": [{
        "details": [{
          "time": "2025-01-08 15:30:45"
        }]
      }]
    }]
  }'
```

---

## Common Patterns

### Lookup Strategy

**Standard pattern used in all webhook handlers:**

```php
// 1. Try to find by message_id (most reliable)
$notificationLog = NotificationLog::where('message_id', $requestId)
    ->where('channel', 'mail')
    ->first();

// 2. Fallback to recipient email (less reliable but works)
if (!$notificationLog && $recipientEmail) {
    $notificationLog = NotificationLog::where('channel', 'mail')
        ->where('recipient', $recipientEmail)
        ->orderBy('sent_at', 'desc')
        ->first();
}

// 3. If still not found, log warning and return
if (!$notificationLog) {
    Log::warning('[WEBHOOK] No notification log found', [
        'request_id' => $requestId,
        'recipient' => $recipientEmail,
    ]);
    return;
}
```

**Why this pattern?**
- `message_id` is most reliable (direct match)
- Email fallback handles edge cases where `message_id` wasn't stored
- Always log when notification not found (debugging)

### Event Data Merging

```php
'gateway_response' => array_merge(
    $notificationLog->gateway_response ?? [],
    ['open_event' => $data]
)
```

**Why merge?**
- Preserves original API response from send
- Adds webhook events as they arrive
- Creates audit trail: send → open → click → etc.

---

## Summary

**Key Patterns:**
1. **Always return HTTP 200** for Zeptomail webhooks
2. **Verify signatures** before processing
3. **Dual lookup strategy**: `message_id` first, fallback to `recipient`
4. **Merge event data** into `gateway_response` JSON field
5. **Log extensively** for debugging webhook issues
6. **Use match expressions** for clean event routing

**Key Files:**
- `NotificationWebhookController.php` - All webhook handling logic
- `NotificationLog.php` - Database model for tracking
- `ZeptoMailTransport.php` - Custom Symfony mailer transport

**Database Fields Updated:**
- `opened_at` - Email open tracking
- `hard_bounced_at` / `soft_bounced_at` - Bounce tracking
- `status` - Current delivery status
- `error_message` - Bounce/failure reasons
- `gateway_response` - Complete event audit trail
