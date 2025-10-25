<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\User;

use Martingalian\Core\Notifications\AdminAlertNotification;

trait NotifiesViaPushover
{
    /**
     * Send a notification to all admin users using Laravel's notification system.
     *
     * Uses admin delivery groups instead of individual user notifications.
     * Sends once to the appropriate delivery group (critical or default).
     */
    public static function notifyAdminsViaPushover(
        string $message,
        string $title = 'Admin Alert',
        ?string $applicationKey = 'default',
        array $additionalParameters = []
    ): void {
        // Get any active admin to use as the notifiable
        // (just for routing to the delivery group, not sending to individual user)
        $admin = static::admin()->where('is_active', true)->first();

        if (! $admin) {
            return;
        }

        // Store application key on the user instance for routing
        $admin->_pushover_application_key = $applicationKey;

        $notification = new AdminAlertNotification(
            message: $message,
            title: '['.gethostname().'] '.$title,
            applicationKey: $applicationKey,
            additionalParameters: $additionalParameters
        );

        // Send once to the delivery group (not to each admin individually)
        $admin->notify($notification);

        // Clean up temporary property
        unset($admin->_pushover_application_key);
    }

    /**
     * Send a notification to this user using Laravel's notification system.
     *
     * Respects user's notification_channels preference (Pushover, Email, etc.)
     * The notification channels are determined by AdminAlertNotification::via()
     */
    public function notifyViaPushover(
        string $message,
        string $title = '',
        ?string $applicationKey = 'default',
        array $additionalParameters = []
    ): void {
        // Store application key on the user instance for routing
        $this->_pushover_application_key = $applicationKey;

        $notification = new AdminAlertNotification(
            message: $message,
            title: '['.gethostname().'] '.$title,
            applicationKey: $applicationKey,
            additionalParameters: $additionalParameters
        );

        $this->notify($notification);

        // Clean up temporary property
        unset($this->_pushover_application_key);
    }
}
