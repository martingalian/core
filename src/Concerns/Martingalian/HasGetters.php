<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Martingalian;

use Martingalian\Core\Models\User;

trait HasGetters
{
    /**
     * Get a virtual admin User for notifications.
     *
     * Returns a non-persisted User instance with admin notification credentials.
     * This virtual user can be used with Laravel's notification system while
     * preventing accidental persistence to the database.
     *
     * @return User Virtual user instance (exists = false, is_virtual = true)
     */
    public static function admin(): User
    {
        return once(function () {
            $martingalian = self::findOrFail(1);

            return tap(new User, function (User $user) use ($martingalian) {
                $user->exists = false;
                $user->is_virtual = true;
                $user->setAttribute('name', 'System Administrator');
                $user->setAttribute('email', $martingalian->email);
                $user->setAttribute('pushover_key', $martingalian->admin_pushover_user_key);
                $user->setAttribute('notification_channels', $martingalian->notification_channels ?? ['pushover']);
                $user->setAttribute('is_active', true);
            });
        });
    }
}
