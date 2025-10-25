<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\User\NotifiesViaPushover;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverReceiver;

final class User extends Authenticatable
{
    use HasDebuggable;
    use HasLoggable;
    use Notifiable;
    use NotifiesViaPushover;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_logged_in_at' => 'datetime',
        'previous_logged_in_at' => 'datetime',

        'can_trade' => 'boolean',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',

        'password' => 'hashed',
        'notification_channels' => 'array',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function positions()
    {
        return $this->hasManyThrough(Position::class, Account::class);
    }

    public function scopeAdmin(Builder $query)
    {
        $query->where('users.is_admin', true);
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    /**
     * Route notifications for the Pushover channel.
     *
     * For admin users: Uses admin delivery groups
     * For regular users: Uses individual user's pushover_key
     */
    public function routeNotificationForPushover($notification): ?PushoverReceiver
    {
        // For admin users, route to admin delivery groups
        if ($this->is_admin) {
            // Get application key from temporary property set by notifyViaPushover()
            $applicationKey = $this->_pushover_application_key ?? 'default';

            // Map application keys to delivery groups
            $groupType = match ($applicationKey) {
                'errors', 'exceptions' => 'critical',
                'indicators' => 'indicators',
                default => 'default',
            };

            // Get the delivery group configuration
            $groupConfig = config("martingalian.api.pushover.admin_delivery_groups.{$groupType}");

            if (! $groupConfig || ! isset($groupConfig['group_key'])) {
                return null;
            }

            // Get the admin application key
            $appToken = config('martingalian.api.pushover.admin_application_key');

            return PushoverReceiver::withUserKey($groupConfig['group_key'])
                ->withApplicationToken($appToken);
        }

        // For non-admin users, use their individual pushover_key
        if (! $this->pushover_key) {
            return null;
        }

        // Use the same admin application key (it's the Martingalian app for all notifications)
        $appToken = config('martingalian.api.pushover.admin_application_key');

        return PushoverReceiver::withUserKey($this->pushover_key)
            ->withApplicationToken($appToken);
    }

    /**
     * Get the notification channels with proper class mapping.
     *
     * @return array<int, string>
     */
    public function getNotificationChannelsAttribute($value): array
    {
        $channels = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($channels) || empty($channels)) {
            return [PushoverChannel::class];
        }

        return array_map(function ($channel) {
            return match ($channel) {
                'pushover' => PushoverChannel::class,
                'mail' => 'mail',
                default => $channel
            };
        }, $channels);
    }
}
