<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\User;

use Martingalian\Core\Models\User;
use Martingalian\Core\Notifications\PushoverNotification;

trait NotifiesViaPushover
{
    /**
     * Send a Pushover notification to all admin users.
     */
    public static function notifyAdminsViaPushover(
        string $message,
        string $title = 'Admin Alert',
        ?string $applicationKey = 'nidavellir',
        array $additionalParameters = []
    ): void {

        if (config('martingalian.send_pushover_notifications') === false) {
            return;
        }

        static::admin()->get()->each(function ($admin) use ($message, $title, $applicationKey, $additionalParameters) {
            $admin->pushover(
                message: $message,
                title: '['.gethostname().'] '.$title,
                applicationKey: $applicationKey,
                additionalParameters: $additionalParameters
            );
        });
    }

    /**
     * Send a Pushover notification to this user.
     */
    public function pushover(
        string $message,
        string $title = 'Nidavellir message',
        ?string $applicationKey = 'nidavellir',
        array $additionalParameters = []
    ): bool|string {
        if (! $this->pushover_key) {
            return 'User does not have a Pushover key.';
        }

        $notification = new PushoverNotification($message, $applicationKey, $title, $additionalParameters);
        $notification->send($this);

        return true;
    }

    /**
     * Send a Pushover notification to all admin users.
     */
    public function notifyViaPushover(
        string $message,
        string $title = '',
        ?string $applicationKey = 'nidavellir',
        array $additionalParameters = []
    ): void {
        if (config('martingalian.send_pushover_notifications') === false) {
            return;
        }

        $this->pushover(
            message: $message,
            title: '['.gethostname().'] '.$title,
            applicationKey: $applicationKey,
            additionalParameters: $additionalParameters
        );
    }
}
