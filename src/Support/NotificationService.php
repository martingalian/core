<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\User;
use Martingalian\Core\Notifications\AlertNotification;

/**
 * NotificationService
 *
 * Unified notification service that leverages Laravel's notification system.
 * Works with both real User models and virtual admin users (Martingalian::admin()).
 *
 * Usage:
 *   NotificationService::send(
 *       user: $user,  // Real or virtual user
 *       message: 'BTC price reached $50,000',
 *       title: 'Price Alert',
 *       deliveryGroup: 'indicators'
 *   );
 *
 *   // Admin notifications using virtual user:
 *   NotificationService::send(
 *       user: Martingalian::admin(),
 *       message: 'System error detected',
 *       title: 'System Alert',
 *       relatable: $apiSystem
 *   );
 */
final class NotificationService
{
    /**
     * Send a notification to a user (real or virtual admin user).
     *
     * This unified method handles both regular user notifications and admin notifications
     * through Laravel's standard notification system. The virtual admin user (Martingalian::admin())
     * works seamlessly with this approach.
     *
     * @param  User  $user  The user to notify (real User or virtual admin via Martingalian::admin())
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title (hostname will be prepended for Pushover)
     * @param  string|null  $canonical  Notification canonical identifier (e.g., 'ip_not_whitelisted', 'api_rate_limit_exceeded')
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @param  array<string, mixed>  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @param  NotificationSeverity|null  $severity  Severity level for visual styling
     * @param  string|null  $pushoverMessage  Override message for Pushover (defaults to $message)
     * @param  string|null  $actionUrl  URL for action button in email
     * @param  string|null  $actionLabel  Label for action button
     * @param  string|null  $exchange  Exchange name for email subject (e.g., 'binance', 'bybit')
     * @param  string|null  $serverIp  Server IP address for email subject (e.g., '192.168.1.100')
     * @param  object|null  $relatable  Optional relatable model (ApiSystem, Step, Account) for audit trail
     * @return bool True if notification was sent, false otherwise
     */
    public static function send(
        User $user,
        string $message,
        string $title = 'Alert',
        ?string $canonical = null,
        ?string $deliveryGroup = 'default',
        array $additionalParameters = [],
        ?NotificationSeverity $severity = null,
        ?string $pushoverMessage = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $exchange = null,
        ?string $serverIp = null,
        ?object $relatable = null
    ): bool {
        // Check if notifications are globally enabled
        if (! config('martingalian.notifications_enabled', true)) {
            return false;
        }

        // Check if user is active
        if (! $user->is_active) {
            return false;
        }

        // Build additional parameters with action URL and label
        $params = $additionalParameters;
        if ($actionUrl) {
            $params['url'] = $actionUrl;
        }
        if ($actionLabel) {
            $params['url_title'] = $actionLabel;
        }

        // Add relatable model to user for NotificationLogListener to extract
        if ($relatable) {
            $user->relatable = $relatable;
        }

        // Send notification using Laravel's notification system
        $user->notifyWithGroup(
            new AlertNotification(
                message: $message,
                title: $title,
                canonical: $canonical,
                deliveryGroup: $deliveryGroup,
                additionalParameters: $params,
                severity: $severity,
                pushoverMessage: $pushoverMessage,
                exchange: $exchange,
                serverIp: $serverIp
            ),
            $deliveryGroup
        );

        return true;
    }

    /**
     * Send a notification to admin using a canonical notification template.
     * Convenience wrapper that fetches message data from NotificationMessageBuilder.
     *
     * @param  string  $canonical  Notification canonical identifier (e.g., 'api_rate_limit_exceeded')
     * @param  array<string, mixed>  $context  Context variables for template interpolation (e.g., ['exchange' => 'binance'])
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @param  object|null  $relatable  Optional relatable model for audit trail
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToAdminByCanonical(
        string $canonical,
        array $context = [],
        ?string $deliveryGroup = 'default',
        ?object $relatable = null
    ): bool {
        // Get notification message data from builder
        $messageData = NotificationMessageBuilder::getMessage($canonical, $context);

        if (! $messageData) {
            return false;
        }

        // Send to admin using virtual user
        return self::send(
            user: Martingalian::admin(),
            message: $messageData['emailMessage'],
            title: $messageData['title'],
            canonical: $canonical,
            deliveryGroup: $deliveryGroup,
            severity: $messageData['severity'],
            pushoverMessage: $messageData['pushoverMessage'],
            actionUrl: $messageData['actionUrl'],
            actionLabel: $messageData['actionLabel'],
            relatable: $relatable
        );
    }
}
