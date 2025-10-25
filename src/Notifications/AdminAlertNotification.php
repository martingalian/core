<?php

declare(strict_types=1);

namespace Martingalian\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;

/**
 * AdminAlertNotification
 *
 * Unified notification class for admin alerts.
 * Supports multiple channels: Pushover, Email (more to come: SMS)
 * Respects user's notification_channels preference.
 */
final class AdminAlertNotification extends Notification
{
    // Removed Queueable - notifications sent immediately so routing has access to notification object

    /**
     * Create a new notification instance.
     *
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title
     * @param  string  $applicationKey  Application key for routing (e.g., 'errors', 'indicators')
     * @param  array  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     */
    public function __construct(
        public string $message,
        public string $title = 'Admin Alert',
        public string $applicationKey = 'default',
        public array $additionalParameters = []
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
     * Determine if this notification should use critical/emergency settings.
     *
     * Maps application key to delivery group and checks its critical_notification flag.
     */
    protected function shouldBeCritical(): bool
    {
        // Map application key to delivery group
        $deliveryGroupKey = match ($this->applicationKey) {
            'errors', 'exceptions' => 'critical',
            'indicators' => 'indicators',
            default => 'default',
        };

        // Get the delivery group configuration
        $groupConfig = config("martingalian.api.pushover.admin_delivery_groups.{$deliveryGroupKey}");

        // Check if this delivery group has critical_notification enabled
        return $groupConfig['critical_notification'] ?? false;
    }

    /**
     * Get the Pushover representation of the notification.
     *
     * The application token is determined by User::routeNotificationForPushover()
     * based on this notification's applicationKey parameter.
     *
     * @param  mixed  $notifiable
     */
    public function toPushover($notifiable): PushoverMessage
    {
        $message = PushoverMessage::create($this->message)
            ->title($this->title);

        // Determine if this notification should be critical based on delivery group
        $isCritical = $this->shouldBeCritical();

        // Apply priority and sound based on whether it's critical
        if ($isCritical) {
            $message->emergencyPriority(
                $this->additionalParameters['retry'] ?? 30,
                $this->additionalParameters['expire'] ?? 3600
            );
            // Use urgent sound for critical notifications (overrides any custom sound)
            $message->sound($this->additionalParameters['sound'] ?? 'siren');
        } else {
            // Apply custom priority if provided
            if (isset($this->additionalParameters['priority'])) {
                $priority = (int) $this->additionalParameters['priority'];
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
            }

            // Apply custom sound if provided
            if (isset($this->additionalParameters['sound'])) {
                $message->sound($this->additionalParameters['sound']);
            }
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
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->view('martingalian::notifications.admin-alert', [
                'alertTitle' => $this->title,
                'alertMessage' => $this->message,
                'hostname' => gethostname(),
                'url' => $this->additionalParameters['url'] ?? null,
                'url_title' => $this->additionalParameters['url_title'] ?? 'View Details',
            ]);
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
            'application_key' => $this->applicationKey,
        ];
    }
}
