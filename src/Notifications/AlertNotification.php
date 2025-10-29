<?php

declare(strict_types=1);

namespace Martingalian\Core\Notifications;

use App\Mail\AlertMail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;

/**
 * AlertNotification
 *
 * Unified notification class for alerts.
 * Supports multiple channels: Pushover, Email (more to come: SMS)
 * Respects user's notification_channels preference.
 * Can be sent to individual users or admin delivery groups.
 */
final class AlertNotification extends Notification
{
    // Removed Queueable - notifications sent immediately so routing has access to notification object

    /**
     * Create a new notification instance.
     *
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators) or null for individual user
     * @param  array  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @param  \App\Enums\NotificationSeverity|null  $severity  Severity level for visual styling
     * @param  string|null  $pushoverMessage  Override message for Pushover (defaults to $message)
     */
    public function __construct(
        public string $message,
        public string $title = 'Alert',
        public ?string $deliveryGroup = null,
        public array $additionalParameters = [],
        public ?\App\Enums\NotificationSeverity $severity = null,
        public ?string $pushoverMessage = null
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * Respects each user's individual notification_channels preference.
     * If a user has no channels configured, defaults to Pushover.
     * Only sends to active users (is_active = true).
     *
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        // Don't send notifications to inactive users
        if (! $notifiable->is_active) {
            return [];
        }

        return $notifiable->notification_channels ?? [PushoverChannel::class];
    }

    /**
     * Get the Pushover representation of the notification.
     *
     * The application token and routing (group key vs user key) is determined by
     * User::routeNotificationForPushover() based on this notification's deliveryGroup property.
     *
     * @param  mixed  $notifiable
     */
    public function toPushover($notifiable): PushoverMessage
    {
        // Use pushoverMessage if provided, otherwise use the main message
        $pushoverText = $this->pushoverMessage ?? $this->message;

        // Add hostname prefix for Pushover notifications only
        $pushoverTitle = '['.gethostname().'] '.$this->title;

        $message = PushoverMessage::create($pushoverText)
            ->title($pushoverTitle);

        // Get priority from delivery group config, or use additionalParameters
        $priority = $this->getDeliveryGroupPriority() ?? $this->additionalParameters['priority'] ?? 0;

        // Apply priority
        match ($priority) {
            -2 => $message->lowestPriority(),
            -1 => $message->lowPriority(),
            0 => $message->normalPriority(),
            1 => $message->highPriority(),
            2 => $message->emergencyPriority(
                $this->additionalParameters['retry'] ?? 30,
                $this->additionalParameters['expire'] ?? 3600
            ),
            default => $message->normalPriority()
        };

        // For emergency priority (2), use siren sound unless overridden
        if ($priority === 2) {
            $message->sound($this->additionalParameters['sound'] ?? 'siren');
        } elseif (isset($this->additionalParameters['sound'])) {
            // Apply custom sound if provided for non-emergency
            $message->sound($this->additionalParameters['sound']);
        }

        // Apply URL if provided
        if (isset($this->additionalParameters['url'])) {
            $message->url(
                $this->additionalParameters['url'],
                $this->additionalParameters['url_title'] ?? 'View Details'
            );
        }

        return $message;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): Mailable
    {
        return (new AlertMail(
            notificationTitle: $this->title,
            notificationMessage: $this->message,
            severity: $this->severity,
            actionUrl: $this->additionalParameters['url'] ?? null,
            actionLabel: $this->additionalParameters['url_title'] ?? null,
            details: $this->additionalParameters['details'] ?? null,
            hostname: gethostname(),
            userName: $notifiable->name ?? null
        ))->to($notifiable->email);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'message' => $this->message,
            'title' => $this->title,
            'delivery_group' => $this->deliveryGroup,
        ];
    }

    /**
     * Get the configured priority for this notification's delivery group.
     *
     * Retrieves priority setting from delivery group configuration.
     * Returns null if no priority is configured (will use additionalParameters priority).
     *
     * @return int|null Priority: -2 (lowest), -1 (low), 0 (normal), 1 (high), 2 (emergency)
     */
    public function getDeliveryGroupPriority(): ?int
    {
        // If no delivery group, use additionalParameters priority
        if (! $this->deliveryGroup) {
            return null;
        }

        // Get the delivery group configuration
        $groupConfig = config("martingalian.api.pushover.delivery_groups.{$this->deliveryGroup}");

        // Return the configured priority, or null if not set
        return $groupConfig['priority'] ?? null;
    }
}
