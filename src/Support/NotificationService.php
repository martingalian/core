<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Events\NotificationSent;
use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\User;
use Martingalian\Core\Notifications\AlertNotification;

/**
 * NotificationService
 *
 * Handles notification logic separate from throttling concerns.
 * Provides convenience methods for sending notifications with proper config checks,
 * user validation, hostname prefixing, and admin user lookup.
 *
 * Usage:
 *   NotificationService::sendToUser(
 *       user: $user,
 *       message: 'BTC price reached $50,000',
 *       title: 'Price Alert',
 *       deliveryGroup: 'indicators'
 *   );
 *
 *   NotificationService::sendToAdmin(
 *       message: 'System backup completed',
 *       title: 'Backup Complete'
 *   );
 */
final class NotificationService
{
    /**
     * Send a notification to a specific user.
     *
     * This method handles:
     * - Config check (martingalian.notifications_enabled)
     * - User active check (is_active)
     * - Hostname prefixing ([hostname] Title)
     * - Notification construction and delivery
     *
     * @param  User  $user  The user to notify
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title (hostname will be prepended automatically)
     * @param  string|null  $canonical  Notification canonical identifier (e.g., 'ip_not_whitelisted', 'api_rate_limit_exceeded')
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @param  array<string, mixed>  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @param  NotificationSeverity|null  $severity  Severity level for visual styling
     * @param  string|null  $pushoverMessage  Override message for Pushover (defaults to $message)
     * @param  string|null  $actionUrl  URL for action button in email
     * @param  string|null  $actionLabel  Label for action button
     * @param  string|null  $exchange  Exchange name for email subject (e.g., 'binance', 'bybit')
     * @param  string|null  $serverIp  Server IP address for email subject (e.g., '192.168.1.100')
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToUser(
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
        ?string $serverIp = null
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

        // Send notification (hostname will be in email footer, Pushover still gets prefix)
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
     * Send a notification to the admin using credentials from Martingalian model.
     * Used when no specific user is available (e.g., system-level operations, virtual accounts).
     *
     * Always sends directly using admin credentials from Martingalian model (admin_pushover_user_key
     * and admin_user_email). Does NOT lookup User records to avoid sending to wrong account.
     *
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title (hostname will be prepended automatically)
     * @param  string|null  $canonical  Notification canonical identifier (e.g., 'ip_not_whitelisted', 'api_rate_limit_exceeded')
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @param  array<string, mixed>  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @param  NotificationSeverity|null  $severity  Severity level for visual styling
     * @param  string|null  $pushoverMessage  Override message for Pushover (defaults to $message)
     * @param  string|null  $actionUrl  URL for action button in email
     * @param  string|null  $actionLabel  Label for action button
     * @param  string|null  $exchange  Exchange name for email subject (e.g., 'binance', 'bybit')
     * @param  string|null  $serverIp  Server IP address for email subject (e.g., '192.168.1.100')
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToAdmin(
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
        ?string $serverIp = null
    ): bool {
        // Check if notifications are globally enabled
        if (! config('martingalian.notifications_enabled', true)) {
            return false;
        }

        // Get Martingalian model for admin configuration
        $martingalian = Martingalian::find(1);

        if (! $martingalian) {
            return false;
        }

        // Admin notifications ALWAYS use direct sending (no User model)
        // We fire NotificationSent events manually so NotificationLogListener can log them

        // Get notification channels from Martingalian model (defaults to Pushover only if not set)
        // Database stores simple strings: ['pushover', 'mail']
        $channelsRaw = $martingalian->notification_channels ?? ['pushover'];
        /** @var array<int, string> $channels */
        // @phpstan-ignore-next-line - PHPDoc type is certain from model cast
        $channels = is_array($channelsRaw) ? $channelsRaw : ['pushover'];

        // Build additional parameters with action URL and label
        $params = $additionalParameters;
        if ($actionUrl) {
            $params['url'] = $actionUrl;
        }
        if ($actionLabel) {
            $params['url_title'] = $actionLabel;
        }

        // Create pseudo-notifiable object for admin
        $adminNotifiable = (object) [
            'email' => $martingalian->admin_user_email,
            'pushover_key' => $martingalian->admin_pushover_user_key,
            'is_active' => true,
        ];

        // Create the AlertNotification instance
        $notification = new AlertNotification(
            message: $message,
            title: $title,
            canonical: $canonical,
            deliveryGroup: $deliveryGroup,
            additionalParameters: $params,
            severity: $severity,
            pushoverMessage: $pushoverMessage,
            exchange: $exchange,
            serverIp: $serverIp
        );

        // Send to configured channels
        foreach ($channels as $channel) {
            // Map simple strings to class names (same pattern as User model)
            $normalizedChannel = match ($channel) {
                'pushover', \NotificationChannels\Pushover\PushoverChannel::class => 'pushover',
                'mail', \Illuminate\Notifications\Channels\MailChannel::class => 'mail',
                default => null,
            };

            if ($normalizedChannel === 'pushover') {
                // Get admin Pushover key from database (not config)
                if ($martingalian->admin_pushover_user_key) {
                    $sent = self::sendDirectToPushover(
                        pushoverKey: $martingalian->admin_pushover_user_key,
                        message: $pushoverMessage ?? $message,
                        title: '['.gethostname().'] '.$title, // Hostname prefix for Pushover
                        deliveryGroup: $deliveryGroup,
                        additionalParameters: $params
                    );

                    // Fire NotificationSent event so NotificationLogListener logs it
                    if ($sent) {
                        event(new NotificationSent(
                            notifiable: $adminNotifiable,
                            notification: $notification,
                            channel: 'pushover',
                            response: null
                        ));
                    }
                }
            } elseif ($normalizedChannel === 'mail') {
                if ($martingalian->admin_user_email) {
                    $userName = 'System Administrator';

                    $sent = self::sendDirectToEmail(
                        email: $martingalian->admin_user_email,
                        message: $message,
                        title: $title, // No hostname prefix for email (goes in footer)
                        severity: $severity,
                        additionalParameters: $params,
                        userName: $userName,
                        exchange: $exchange,
                        serverIp: $serverIp
                    );

                    // Fire NotificationSent event so NotificationLogListener logs it
                    if ($sent) {
                        event(new NotificationSent(
                            notifiable: $adminNotifiable,
                            notification: $notification,
                            channel: 'mail',
                            response: null
                        ));
                    }
                }
            }
        }

        return true;
    }

    /**
     * Send a notification to the admin using a canonical notification template.
     * Looks up the notification template from NotificationMessageBuilder and interpolates context variables.
     *
     * This method does NOT pass exchange or serverIp parameters to prevent them from appearing
     * in email subjects. All server context should be included in the message content via templates.
     *
     * @param  string  $canonical  Notification canonical identifier (e.g., 'binance_websocket_error')
     * @param  array<string, mixed>  $context  Context variables for template interpolation (e.g., ['exchange' => 'binance', 'exception' => '...'])
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators)
     * @return bool True if notification was sent, false otherwise
     */
    public static function sendToAdminByCanonical(
        string $canonical,
        array $context = [],
        ?string $deliveryGroup = 'default'
    ): bool {
        // Get notification message data from builder
        $messageData = NotificationMessageBuilder::getMessage($canonical, $context);

        if (! $messageData) {
            return false;
        }

        // Send to admin WITHOUT exchange or serverIp parameters
        // This ensures email subjects don't include server context
        return self::sendToAdmin(
            message: $messageData['emailMessage'],
            title: $messageData['title'],
            canonical: $canonical,
            deliveryGroup: $deliveryGroup,
            severity: $messageData['severity'],
            pushoverMessage: $messageData['pushoverMessage'],
            actionUrl: $messageData['actionUrl'],
            actionLabel: $messageData['actionLabel']
        );
    }

    /**
     * Send notifications directly to admin (Pushover + Email) without requiring a User model.
     * Used for admin notifications when there's no database User record (virtual admin).
     *
     * @param  string  $pushoverKey  The Pushover user/group key to send to
     * @param  string|null  $email  The admin email address to send to
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title (with hostname prefix)
     * @param  string|null  $deliveryGroup  Delivery group name for priority lookup
     * @param  array<string, mixed>  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     *
     * @phpstan-ignore-next-line method.unused
     */
    private static function sendDirectToAdmin(
        string $pushoverKey,
        ?string $email,
        string $message,
        string $title,
        ?string $deliveryGroup = null,
        array $additionalParameters = []
    ): void {
        // Send Pushover notification
        self::sendDirectToPushover(
            pushoverKey: $pushoverKey,
            message: $message,
            title: $title,
            deliveryGroup: $deliveryGroup,
            additionalParameters: $additionalParameters
        );

        // Send email notification if email is configured
        if ($email) {
            self::sendDirectToEmail(
                email: $email,
                message: $message,
                title: $title,
                additionalParameters: $additionalParameters
            );
        }
    }

    /**
     * Send a Pushover notification directly without requiring a User model.
     *
     * @param  string  $pushoverKey  The Pushover user/group key to send to
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title
     * @param  string|null  $deliveryGroup  Delivery group name for priority lookup
     * @param  array<string, mixed>  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @return bool True if notification was sent successfully
     */
    private static function sendDirectToPushover(
        string $pushoverKey,
        string $message,
        string $title,
        ?string $deliveryGroup = null,
        array $additionalParameters = []
    ): bool {
        // Get Pushover application token from Martingalian model
        $martingalian = Martingalian::find(1);

        if (! $martingalian || ! $martingalian->admin_pushover_application_key) {
            return false;
        }

        $appToken = $martingalian->admin_pushover_application_key;

        // Determine priority from delivery group or additionalParameters
        $priority = 0;
        if ($deliveryGroup) {
            $groupConfigRaw = config("martingalian.api.pushover.delivery_groups.{$deliveryGroup}");
            $groupConfig = is_array($groupConfigRaw) ? $groupConfigRaw : [];
            $priorityValue = $groupConfig['priority'] ?? 0;
            $priority = is_int($priorityValue) ? $priorityValue : 0;
        } elseif (isset($additionalParameters['priority'])) {
            $priorityValue = $additionalParameters['priority'];
            $priority = is_int($priorityValue) ? $priorityValue : 0;
        }

        // Build Pushover API payload
        $payload = [
            'token' => $appToken,
            'user' => $pushoverKey,
            'message' => $message,
            'title' => $title,
            'priority' => $priority,
        ];

        // For emergency priority (2), add retry and expire
        if ($priority === 2) {
            $payload['retry'] = $additionalParameters['retry'] ?? 30;
            $payload['expire'] = $additionalParameters['expire'] ?? 3600;
            $payload['sound'] = $additionalParameters['sound'] ?? 'siren';
        } elseif (isset($additionalParameters['sound'])) {
            $payload['sound'] = $additionalParameters['sound'];
        }

        // Add URL if provided
        if (isset($additionalParameters['url'])) {
            $payload['url'] = $additionalParameters['url'];
            $payload['url_title'] = $additionalParameters['url_title'] ?? 'View Details';
        }

        // Send to Pushover API
        $response = Http::asForm()->post('https://api.pushover.net/1/messages.json', $payload);

        return $response->successful();
    }

    /**
     * Send an email notification directly without requiring a User model.
     *
     * @param  string  $email  The email address to send to
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title
     * @param  NotificationSeverity|null  $severity  Severity level for visual styling
     * @param  array<string, mixed>  $additionalParameters  Extra parameters (url, url_title, etc.)
     * @param  string|null  $userName  User name for personalization
     * @param  string|null  $exchange  Exchange name for email subject
     * @param  string|null  $serverIp  Server IP address for email subject
     * @return bool True if notification was sent successfully
     */
    private static function sendDirectToEmail(
        string $email,
        string $message,
        string $title,
        ?NotificationSeverity $severity = null,
        array $additionalParameters = [],
        ?string $userName = null,
        ?string $exchange = null,
        ?string $serverIp = null
    ): bool {
        $htmlMessage = view('martingalian::emails.notification', [
            'notificationTitle' => $title,
            'notificationMessage' => $message,
            'severity' => $severity,
            'actionUrl' => $additionalParameters['url'] ?? null,
            'actionLabel' => $additionalParameters['url_title'] ?? null,
            'details' => $additionalParameters['details'] ?? null,
            'hostname' => gethostname(),
            'userName' => $userName,
        ])->render();

        // Build subject with optional server IP and exchange context
        // Only add server context if exchange or serverIp is explicitly provided
        $subject = $title;
        if ($serverIp || $exchange) {
            $hostname = gethostname();
            if ($serverIp && $exchange) {
                $subject .= ' - Server '.$serverIp.' on '.ucfirst($exchange);
            } elseif ($serverIp) {
                $subject .= ' - Server '.$serverIp;
            } else {
                // At this point: $serverIp is false, so $exchange must be true (from outer condition)
                // Legacy fallback: use hostname if IP not provided but exchange is specified
                if ($hostname) {
                    $subject .= ' - Server '.$hostname.' on '.ucfirst($exchange);
                }
            }
        }

        // Send email if email is provided
        if ($email) {
            Mail::html($htmlMessage, function (\Illuminate\Mail\Message $mail) use ($email, $subject): void {
                $mail->to($email)
                    ->subject($subject);
            });

            return true;
        }

        return false;
    }
}
