<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Martingalian\Core\Models\NotificationLog;
use Martingalian\Core\Models\NotificationThrottleRule;
use Martingalian\Core\Models\User;
use Martingalian\Core\Notifications\AlertNotification;

/**
 * NotificationThrottler
 *
 * Manages throttled notifications based on database-driven rules.
 * Prevents notification spam by enforcing per-user, per-notification-type throttling.
 *
 * Usage:
 *   NotificationThrottler::sendToUser(
 *       user: $user,
 *       messageCanonical: 'ip_not_whitelisted',
 *       message: 'Worker IP is not whitelisted on exchange',
 *       title: 'IP Whitelist Error',
 *       deliveryGroup: 'exceptions'
 *   );
 */
final class NotificationThrottler
{
    /**
     * Send a notification to a specific user if throttling rules allow it.
     *
     * This method:
     * 1. Checks if a throttle rule exists for the given message_canonical
     * 2. Checks if the user last received this notification outside the throttle window
     * 3. Sends notification only if eligible
     * 4. Updates notification_logs with the current timestamp
     *
     * @param  User  $user  The user to notify
     * @param  string  $messageCanonical  Canonical identifier for the notification type
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title (hostname will be prepended automatically)
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @param  array  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToUser(
        User $user,
        string $messageCanonical,
        string $message,
        string $title = 'Alert',
        ?string $deliveryGroup = 'default',
        array $additionalParameters = []
    ): bool {
        // Check if notifications are globally enabled
        if (! config('martingalian.notifications_enabled', true)) {
            return false;
        }

        // Check if user is active
        if (! $user->is_active) {
            return false;
        }

        // Get the throttle rule for this message type
        $throttleRule = NotificationThrottleRule::findByCanonical($messageCanonical);

        if (! $throttleRule) {
            // Check if auto-create is enabled
            if (config('martingalian.auto_create_missing_throttle_rules', true)) {
                // Auto-create a new throttle rule with default settings
                $throttleRule = NotificationThrottleRule::create([
                    'message_canonical' => $messageCanonical,
                    'throttle_seconds' => config('martingalian.default_throttle_seconds', 1800),
                    'is_active' => true,
                ]);
            } else {
                // No throttle rule exists and auto-create is disabled - do not send notification
                return false;
            }
        }

        // Check if we can send to this user
        if (! self::canSendToUser($user->id, $messageCanonical, $throttleRule->throttle_seconds)) {
            return false;
        }

        // Send notification
        $user->notifyWithGroup(
            new AlertNotification(
                message: $message,
                title: '['.gethostname().'] '.$title,
                deliveryGroup: $deliveryGroup,
                additionalParameters: $additionalParameters
            ),
            $deliveryGroup
        );

        // Update or create notification log
        self::recordNotificationSent($user->id, $messageCanonical);

        return true;
    }

    /**
     * Send a notification to the admin user defined in config.
     * Used when no specific user is available (e.g., virtual accounts, system-level operations).
     * Creates a virtual admin user from config - does not require user to exist in database.
     *
     * @param  string  $messageCanonical  Canonical identifier for the notification type
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title (hostname will be prepended automatically)
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @param  array  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToAdmin(
        string $messageCanonical,
        string $message,
        string $title = 'Alert',
        ?string $deliveryGroup = 'default',
        array $additionalParameters = []
    ): bool {
        // Check if notifications are globally enabled
        if (! config('martingalian.notifications_enabled', true)) {
            return false;
        }

        // Get admin details from config
        $adminEmail = config('martingalian.admin_user_email');
        $adminName = config('martingalian.admin_user_name', 'Admin');
        $adminPushoverKey = config('martingalian.admin_user_pushover_key');

        // Determine available notification channels
        $channels = [];

        // Check if email is configured (requires mail config and admin email)
        if ($adminEmail && config('mail.mailers.'.config('mail.default'))) {
            $channels[] = 'mail';
        }

        // Check if Pushover is configured (requires pushover key)
        if ($adminPushoverKey) {
            $channels[] = \NotificationChannels\Pushover\PushoverChannel::class;
        }

        // Must have at least one channel configured
        if (empty($channels)) {
            return false;
        }

        // Get the throttle rule for this message type
        $throttleRule = NotificationThrottleRule::findByCanonical($messageCanonical);

        if (! $throttleRule) {
            // Check if auto-create is enabled
            if (config('martingalian.auto_create_missing_throttle_rules', true)) {
                // Auto-create a new throttle rule with default settings
                $throttleRule = NotificationThrottleRule::create([
                    'message_canonical' => $messageCanonical,
                    'throttle_seconds' => config('martingalian.default_throttle_seconds', 1800),
                    'is_active' => true,
                ]);
            } else {
                // No throttle rule exists and auto-create is disabled - do not send notification
                return false;
            }
        }

        // Use NULL for admin in throttling (virtual admin user - no database record)
        $adminUserId = null;

        // Check if we can send to admin based on throttling
        if (! self::canSendToAdmin($messageCanonical, $throttleRule->throttle_seconds)) {
            return false;
        }

        // Create a virtual admin user for notification routing
        $virtualAdmin = new User();
        $virtualAdmin->id = null;
        $virtualAdmin->name = $adminName;
        $virtualAdmin->email = $adminEmail;
        $virtualAdmin->pushover_key = $adminPushoverKey;
        $virtualAdmin->is_active = true;
        $virtualAdmin->notification_channels = $channels;
        $virtualAdmin->exists = false; // Mark as non-persisted

        // Send notification using the virtual user
        $virtualAdmin->notifyWithGroup(
            new AlertNotification(
                message: $message,
                title: '['.gethostname().'] '.$title,
                deliveryGroup: $deliveryGroup,
                additionalParameters: $additionalParameters
            ),
            $deliveryGroup
        );

        // Update or create notification log for admin
        self::recordNotificationSent($adminUserId, $messageCanonical);

        return true;
    }

    /**
     * Check if a notification can be sent to a specific user.
     * Returns true if the user has never received this notification type,
     * or if enough time has passed since they last received it.
     */
    private static function canSendToUser(int $userId, string $messageCanonical, int $throttleSeconds): bool
    {
        $log = NotificationLog::query()
            ->where('user_id', $userId)
            ->where('message_canonical', $messageCanonical)
            ->first();

        // Never sent to this user before
        if (! $log) {
            return true;
        }

        // Check if enough time has passed
        return $log->canSendAgain($throttleSeconds);
    }

    /**
     * Check if a notification can be sent to the admin.
     * Returns true if the admin has never received this notification type,
     * or if enough time has passed since they last received it.
     * Uses user_id = NULL for admin notifications.
     */
    private static function canSendToAdmin(string $messageCanonical, int $throttleSeconds): bool
    {
        $log = NotificationLog::query()
            ->whereNull('user_id')
            ->where('message_canonical', $messageCanonical)
            ->first();

        // Never sent to admin before
        if (! $log) {
            return true;
        }

        // Check if enough time has passed
        return $log->canSendAgain($throttleSeconds);
    }

    /**
     * Record that a notification was sent to a user right now.
     * Creates a new log entry if it doesn't exist, or updates the existing one.
     * Accepts NULL for admin notifications (virtual user).
     */
    private static function recordNotificationSent(?int $userId, string $messageCanonical): void
    {
        NotificationLog::updateOrCreate(
            [
                'user_id' => $userId,
                'message_canonical' => $messageCanonical,
            ],
            [
                'last_sent_at' => now(),
            ]
        );
    }
}
