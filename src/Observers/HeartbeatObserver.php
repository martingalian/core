<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\Heartbeat;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Throwable;

/**
 * HeartbeatObserver
 *
 * Observes Heartbeat model changes and sends notifications when
 * the connection_status changes to certain states.
 *
 * Notification triggers:
 * - connected: WebSocket successfully connected/reconnected
 * - disconnected: Max internal reconnect attempts exhausted
 * - stale: Zombie connection detected (open but not receiving data)
 *
 * Does NOT notify on:
 * - reconnecting: Internal retry in progress, don't spam notifications
 */
final class HeartbeatObserver
{
    /**
     * Handle the Heartbeat "created" event.
     *
     * Sends notification when a new heartbeat is created with a connection status.
     */
    public function created(Heartbeat $heartbeat): void
    {
        // Only notify for actionable statuses on creation
        $status = $heartbeat->connection_status;

        if (! $status || $status === Heartbeat::STATUS_UNKNOWN || $status === Heartbeat::STATUS_RECONNECTING) {
            return;
        }

        $this->sendStatusChangeNotification($heartbeat, null, $status);
    }

    /**
     * Handle the Heartbeat "updated" event.
     *
     * Sends notifications when connection_status changes to actionable states.
     */
    public function updated(Heartbeat $heartbeat): void
    {
        // Only process if connection_status was changed during the last save
        if (! $heartbeat->wasChanged('connection_status')) {
            return;
        }

        $oldStatus = $heartbeat->getOriginal('connection_status');
        $newStatus = $heartbeat->connection_status;

        // Don't notify if status didn't actually change
        if ($oldStatus === $newStatus) {
            return;
        }

        // Don't notify for reconnecting - internal retry in progress
        if ($newStatus === Heartbeat::STATUS_RECONNECTING) {
            return;
        }

        // Don't notify for unknown status transitions
        if ($newStatus === Heartbeat::STATUS_UNKNOWN) {
            return;
        }

        $this->sendStatusChangeNotification($heartbeat, $oldStatus, $newStatus);
    }

    /**
     * Send notification for status change.
     */
    public function sendStatusChangeNotification(Heartbeat $heartbeat, ?string $oldStatus, string $newStatus): void
    {
        try {
            $apiSystem = $heartbeat->apiSystem;
            if (! $apiSystem) {
                return;
            }

            // Build appropriate message based on status
            $message = $this->buildMessage($heartbeat, $oldStatus, $newStatus);

            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'websocket_status_change',
                referenceData: [
                    'apiSystem' => $apiSystem,
                    'status' => $newStatus,
                    'message' => $message,
                    'group' => $heartbeat->group,
                ],
                relatable: $apiSystem,
                cacheKeys: [
                    'api_system' => $apiSystem->canonical,
                    'group' => $heartbeat->group ?? 'null',
                    'status' => $newStatus,
                ]
            );
        } catch (Throwable $e) {
            // Observer notification failures should not crash the application
            error_log('[HeartbeatObserver] Failed to send notification: '.$e->getMessage());
        }
    }

    /**
     * Build status-specific message.
     */
    public function buildMessage(Heartbeat $heartbeat, ?string $oldStatus, string $newStatus): string
    {
        $from = $oldStatus ?? 'none';
        $message = "Status: {$from} → {$newStatus}";

        // Add close code info for disconnected/stale states
        if (in_array($newStatus, [Heartbeat::STATUS_DISCONNECTED, Heartbeat::STATUS_STALE])) {
            if ($heartbeat->last_close_code !== null) {
                $closeDescription = Heartbeat::describeCloseCode($heartbeat->last_close_code);
                $message .= "\nClose code: {$heartbeat->last_close_code} ({$closeDescription})";
            }

            if ($heartbeat->last_close_reason !== null) {
                $message .= "\nClose reason: {$heartbeat->last_close_reason}";
            }
        }

        return $message;
    }
}
