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
            // Verify webhook signature for security
            if (! $this->verifyZeptomailSignature($request)) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Invalid signature', [
                    'ip' => $request->ip(),
                    'headers' => $request->headers->all(),
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            // Zeptomail sends events as JSON
            $payload = $request->json()->all();

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
                    'confirmed_at' => \Carbon\Carbon::createFromTimestamp((int) $acknowledgedAt),
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

        // Update status based on bounce type
        $status = $bounceType === 'hard_bounce' ? 'failed' : 'bounced';

        $notificationLog->update([
            'status' => $status,
            'bounced_at' => $bounceTime ? \Carbon\Carbon::parse($bounceTime) : now(),
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
        // Extract request_id from webhook payload (at root level of event_message object)
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Extract open details from event_data array
        $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
        $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
        $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
        $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];

        // Extract timestamp when user opened the email
        $openedAt = is_string($details['time'] ?? null) ? $details['time'] : null;

        Log::info('[ZEPTOMAIL WEBHOOK] Handling open event', [
            'request_id' => $requestId,
            'opened_at' => $openedAt,
        ]);

        // Find notification log by message_id (which stores the request_id from API response)
        $notificationLog = null;
        if ($requestId) {
            $notificationLog = NotificationLog::where('message_id', $requestId)
                ->where('channel', 'mail')
                ->whereNull('confirmed_at') // Only update if not already confirmed
                ->first();

            Log::info('[ZEPTOMAIL WEBHOOK] Searched by request_id (open)', [
                'request_id' => $requestId,
                'found' => $notificationLog !== null,
                'query' => "message_id={$requestId}, channel=mail, confirmed_at IS NULL",
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
                    ->whereNull('confirmed_at') // Only update if not already confirmed
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
        $confirmedAt = $openedAt !== null
            ? \Carbon\Carbon::parse($openedAt)
            : now();

        $notificationLog->update([
            'confirmed_at' => $confirmedAt,
            'opened_at' => $confirmedAt,
            'status' => 'delivered',
            'http_headers_received' => $request->headers->all(),
            'gateway_response' => array_merge(
                $notificationLog->gateway_response ?? [],
                ['open_event' => $data]
            ),
        ]);

        Log::info('[ZEPTOMAIL WEBHOOK] Open event processed successfully', [
            'notification_log_id' => $notificationLog->id,
            'confirmed_at' => $confirmedAt,
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
     * @see https://www.zoho.com/zeptomail/help/webhooks.html#alink5
     */
    private function verifyZeptomailSignature(Request $request): bool
    {
        $secret = config('martingalian.api.webhooks.zeptomail_secret');

        // Reject if no secret is configured - webhooks must always be authenticated
        if (! $secret) {
            Log::error('[ZEPTOMAIL WEBHOOK] No webhook secret configured - rejecting webhook');

            return false;
        }

        $signature = $request->header('X-Zeptomail-Signature');

        if (! $signature) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No signature header present');

            return false;
        }

        // Get raw request body
        $payload = $request->getContent();

        // Calculate expected signature
        /** @var string $secret */
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        // Compare signatures
        return hash_equals($expectedSignature, $signature);
    }
}
