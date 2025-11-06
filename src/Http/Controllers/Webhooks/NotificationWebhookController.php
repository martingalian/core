<?php

declare(strict_types=1);

namespace Martingalian\Core\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Log;
use Martingalian\Core\Models\NotificationLog;
use Throwable;

/**
 * NotificationWebhookController
 *
 * Handles delivery confirmation webhooks from notification gateways:
 * - Zeptomail: Hard bounce, soft bounce, open events
 * - Pushover: Receipt acknowledgment callbacks
 *
 * Updates notification_logs table with delivery confirmation timestamps and status.
 */
final class NotificationWebhookController extends Controller
{
    /**
     * Handle Zeptomail webhook events.
     *
     * Processes: hard bounce, soft bounce, open events.
     *
     * @see https://www.zoho.com/zeptomail/help/webhooks.html
     */
    public function zeptomail(Request $request): JsonResponse
    {
        try {
            // DEBUG: Log raw webhook received
            Log::info('[ZEPTOMAIL WEBHOOK] === RAW WEBHOOK RECEIVED ===', [
                'raw_body' => $request->getContent(),
                'all_headers' => $request->headers->all(),
                'ip' => $request->ip(),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
            ]);

            // Verify webhook signature for security
            if (! $this->verifyZeptomailSignature($request)) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Invalid signature', [
                    'ip' => $request->ip(),
                    'headers' => $request->headers->all(),
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            Log::info('[ZEPTOMAIL WEBHOOK] ✓ Signature verified successfully');

            // Zeptomail sends events as JSON
            $payload = $request->json()->all();

            // DEBUG: Log parsed payload
            Log::info('[ZEPTOMAIL WEBHOOK] Parsed JSON payload', [
                'payload' => $payload,
                'payload_keys' => array_keys($payload),
            ]);

            // Log all incoming webhook calls
            Log::info('[ZEPTOMAIL WEBHOOK] Received webhook', [
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'ip' => $request->ip(),
            ]);

            // Extract event type and event data (both are arrays)
            $eventNames = $payload['event_name'] ?? null;
            $eventMessages = $payload['event_message'] ?? null;

            if (! is_array($eventNames) || ! is_array($eventMessages) || count($eventNames) === 0 || count($eventMessages) === 0) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Missing event_name or event_message', [
                    'payload' => $payload,
                ]);

                // Zeptomail requires status 200 even for errors
                return response()->json(['status' => 'error', 'message' => 'Missing event_name or event_message'], 200);
            }

            // Get first event (Zeptomail sends arrays but typically one event per webhook)
            $eventType = $eventNames[0];
            $eventData = $eventMessages[0];

            if (! is_string($eventType) || ! is_array($eventData)) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Invalid event format', [
                    'event_type' => $eventType,
                    'event_data_type' => gettype($eventData),
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid event format'], 200);
            }

            Log::info('[ZEPTOMAIL WEBHOOK] Processing event', [
                'event_type' => $eventType,
                'event_data' => $eventData,
            ]);

            // Process based on event type
            /** @var array<string, mixed> $eventData */
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
                'payload' => $request->json()->all(),
            ]);

            // Zeptomail requires status 200 even for errors
            return response()->json(['status' => 'error', 'message' => 'Internal error'], 200);
        }
    }

    /**
     * Handle Pushover receipt acknowledgment callback.
     *
     * Called when user acknowledges an emergency-priority notification.
     *
     * @see https://pushover.net/api#receipt
     */
    public function pushover(Request $request): JsonResponse
    {
        try {
            // Pushover sends callbacks as form data
            $receipt = $request->input('receipt');
            $acknowledged = $request->input('acknowledged');
            $acknowledgedAt = $request->input('acknowledged_at');

            if (! $receipt) {
                return response()->json(['success' => false, 'message' => 'Missing receipt'], 200);
            }

            // Find notification log by receipt (stored in gateway_response)
            $notificationLog = NotificationLog::where('channel', 'pushover')
                ->where('gateway_response->receipt', $receipt)
                ->first();

            if (! $notificationLog) {
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

    /**
     * Handle Zeptomail bounce events (hard or soft).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleZeptomailBounce(Request $request, array $data, string $bounceType): void
    {
        // Extract request_id from webhook payload (at root level of event_message object)
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Extract bounce details from event_data array
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
            $errorMessage .= ' - '.$diagnosticMessage;
        }
        if ($bouncedRecipient !== null) {
            $errorMessage .= ' (Recipient: '.$bouncedRecipient.')';
        }

        Log::info('[ZEPTOMAIL WEBHOOK] Handling bounce', [
            'bounce_type' => $bounceType,
            'request_id' => $requestId,
            'bounce_reason' => $bounceReason,
            'recipient' => $bouncedRecipient,
        ]);

        // Find notification log by message_id (which stores the request_id from API response)
        $notificationLog = null;
        if ($requestId !== null) {
            $notificationLog = NotificationLog::where('message_id', $requestId)
                ->where('channel', 'mail')
                ->first();

            Log::info('[ZEPTOMAIL WEBHOOK] Searched by request_id', [
                'request_id' => $requestId,
                'found' => $notificationLog !== null,
            ]);
        }

        // Fallback: try to match by recipient email if request_id not found
        if (! $notificationLog) {
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

                Log::info('[ZEPTOMAIL WEBHOOK] Searched by email fallback', [
                    'email' => $recipientEmail,
                    'found' => $notificationLog !== null,
                ]);
            }
        }

        if (! $notificationLog) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for bounce', [
                'request_id' => $requestId,
                'recipient' => $bouncedRecipient,
            ]);

            return;
        }

        // Update status and bounce timestamp based on bounce type
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

    /**
     * Handle Zeptomail open events.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleZeptomailOpen(Request $request, array $data): void
    {
        // DEBUG: Log complete event data structure
        Log::info('[ZEPTOMAIL WEBHOOK] === PROCESSING OPEN EVENT ===', [
            'full_data' => $data,
            'data_keys' => array_keys($data),
        ]);

        // Extract request_id from webhook payload (at root level of event_message object)
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Extract open details from event_data array
        $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
        $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
        $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
        $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];

        // Extract timestamp when user opened the email
        $openedAt = is_string($details['time'] ?? null) ? $details['time'] : null;

        Log::info('[ZEPTOMAIL WEBHOOK] Extracted open event data', [
            'request_id' => $requestId,
            'opened_at' => $openedAt,
            'event_data_array' => $eventDataArray,
            'event_data' => $eventData,
            'details_array' => $detailsArray,
            'details' => $details,
        ]);

        // Find notification log by message_id (which stores the request_id from API response)
        $notificationLog = null;
        if ($requestId) {
            // DEBUG: First check if ANY record exists with this message_id
            $anyRecord = NotificationLog::where('message_id', $requestId)->where('channel', 'mail')->first();
            Log::info('[ZEPTOMAIL WEBHOOK] Checking for any matching record', [
                'request_id' => $requestId,
                'any_record_found' => $anyRecord !== null,
                'record_data' => $anyRecord ? [
                    'id' => $anyRecord->id,
                    'message_id' => $anyRecord->message_id,
                    'opened_at' => $anyRecord->opened_at,
                    'status' => $anyRecord->status,
                ] : null,
            ]);

            $notificationLog = NotificationLog::where('message_id', $requestId)
                ->where('channel', 'mail')
                ->whereNull('opened_at') // Only update if not already opened
                ->first();

            Log::info('[ZEPTOMAIL WEBHOOK] Searched by request_id (open)', [
                'request_id' => $requestId,
                'found' => $notificationLog !== null,
                'query' => "message_id={$requestId}, channel=mail, opened_at IS NULL",
            ]);
        }

        // Fallback: try to match by recipient email if request_id not found
        if (! $notificationLog) {
            $emailInfo = is_array($data['email_info'] ?? null) ? $data['email_info'] : [];
            $toArray = is_array($emailInfo['to'] ?? null) ? $emailInfo['to'] : [];
            $toFirst = is_array($toArray[0] ?? null) ? $toArray[0] : [];
            $emailAddress = is_array($toFirst['email_address'] ?? null) ? $toFirst['email_address'] : [];
            $recipientEmail = is_string($emailAddress['address'] ?? null) ? $emailAddress['address'] : null;

            if ($recipientEmail !== null) {
                $notificationLog = NotificationLog::where('channel', 'mail')
                    ->where('recipient', $recipientEmail)
                    ->whereNull('opened_at') // Only update if not already opened
                    ->orderBy('sent_at', 'desc')
                    ->first();

                Log::info('[ZEPTOMAIL WEBHOOK] Searched by email fallback (open)', [
                    'email' => $recipientEmail,
                    'found' => $notificationLog !== null,
                ]);
            }
        }

        if (! $notificationLog) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for open event', [
                'request_id' => $requestId,
                'data' => $data,
            ]);

            return;
        }

        // Use timestamp from webhook payload, or fallback to when we received the webhook
        $openedAtTimestamp = $openedAt !== null
            ? \Carbon\Carbon::parse($openedAt)
            : now();

        Log::info('[ZEPTOMAIL WEBHOOK] About to update notification log', [
            'notification_log_id' => $notificationLog->id,
            'before_update' => [
                'opened_at' => $notificationLog->opened_at,
                'status' => $notificationLog->status,
            ],
            'update_values' => [
                'opened_at' => $openedAtTimestamp,
                'status' => 'opened',
            ],
        ]);

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
            'after_update' => [
                'opened_at' => $notificationLog->fresh()->opened_at,
                'status' => $notificationLog->fresh()->status,
            ],
        ]);
    }

    /**
     * Handle Zeptomail click events.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleZeptomailClick(Request $request, array $data): void
    {
        // Extract request_id from webhook payload
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Extract click details from event_data array
        $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
        $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
        $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
        $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];
        $clickedAt = is_string($details['time'] ?? null) ? $details['time'] : null;
        $clickedLink = is_string($details['clicked_link'] ?? null) ? $details['clicked_link'] : null;

        Log::info('[ZEPTOMAIL WEBHOOK] Handling click event', [
            'request_id' => $requestId,
            'clicked_at' => $clickedAt,
            'link' => $clickedLink,
        ]);

        // Find notification log by message_id
        $notificationLog = null;
        if ($requestId) {
            $notificationLog = NotificationLog::where('message_id', $requestId)
                ->where('channel', 'mail')
                ->first();

            Log::info('[ZEPTOMAIL WEBHOOK] Searched by request_id (click)', [
                'request_id' => $requestId,
                'found' => $notificationLog !== null,
            ]);
        }

        // Fallback: try to match by recipient email if request_id not found
        if (! $notificationLog) {
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

                Log::info('[ZEPTOMAIL WEBHOOK] Searched by email fallback (click)', [
                    'email' => $recipientEmail,
                    'found' => $notificationLog !== null,
                ]);
            }
        }

        if (! $notificationLog) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for click event', [
                'request_id' => $requestId,
                'data' => $data,
            ]);

            return;
        }

        // Store click event in gateway_response
        $notificationLog->update([
            'http_headers_received' => $request->headers->all(),
            'gateway_response' => array_merge(
                $notificationLog->gateway_response ?? [],
                ['click_event' => $data]
            ),
        ]);

        Log::info('[ZEPTOMAIL WEBHOOK] Click event processed successfully', [
            'notification_log_id' => $notificationLog->id,
            'link' => $clickedLink,
        ]);
    }

    /**
     * Verify Zeptomail webhook signature.
     *
     * Zeptomail uses 'producer-signature' header with format:
     * ts=<timestamp>;s=<signature>;s-algorithm=HmacSHA256
     *
     * @see https://www.zoho.com/zeptomail/help/webhooks.html#alink5
     */
    private function verifyZeptomailSignature(Request $request): bool
    {
        $configuredSecret = config('martingalian.api.webhooks.zeptomail_secret');

        // Reject if no secret is configured - webhooks must always be authenticated
        if (! $configuredSecret) {
            Log::error('[ZEPTOMAIL WEBHOOK] No webhook secret configured - rejecting webhook');

            return false;
        }

        // Zeptomail uses 'producer-signature' header (updated webhook format)
        $signatureHeader = $request->header('producer-signature');

        if (! $signatureHeader) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No producer-signature header present');

            return false;
        }

        // Parse signature header format: ts=<timestamp>;s=<signature>;s-algorithm=HmacSHA256
        $parts = [];
        foreach (explode(';', $signatureHeader) as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $parts[mb_trim($key)] = mb_trim($value);
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['s'] ?? null;

        if (! $timestamp || ! $signature) {
            Log::warning('[ZEPTOMAIL WEBHOOK] Invalid signature format', [
                'header' => $signatureHeader,
                'parsed_parts' => $parts,
            ]);

            return false;
        }

        // URL decode the signature
        $signature = urldecode($signature);

        // Get raw request body
        $payload = $request->getContent();

        // Zeptomail signs ONLY the payload (not timestamp + payload as documented)
        // Calculate expected signature using HMAC-SHA256
        /** @var string $configuredSecret */
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $configuredSecret, true));

        Log::info('[ZEPTOMAIL WEBHOOK] Signature verification', [
            'timestamp' => $timestamp,
            'payload_length' => strlen($payload),
            'signature_received' => $signature,
            'signature_expected' => $expectedSignature,
            'match' => hash_equals($expectedSignature, $signature),
        ]);

        // Compare signatures
        if (hash_equals($expectedSignature, $signature)) {
            Log::info('[ZEPTOMAIL WEBHOOK] Signature verified successfully');

            return true;
        }

        Log::warning('[ZEPTOMAIL WEBHOOK] Invalid signature', [
            'timestamp' => $timestamp,
            'signature_received' => $signature,
            'signature_expected' => $expectedSignature,
        ]);

        return false;
    }
}
