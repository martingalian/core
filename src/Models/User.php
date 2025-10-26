<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverReceiver;

final class User extends Authenticatable
{
    use HasDebuggable;
    use HasFactory;
    use HasLoggable;
    use Notifiable;

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

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\UserFactory::new();
    }

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
     * Send a notification with optional delivery group routing.
     *
     * This method handles the temporary property pattern required because
     * the Pushover package doesn't pass the notification object to routing methods.
     *
     * @param  \Illuminate\Notifications\Notification  $notification
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators) or null for individual user key
     */
    public function notifyWithGroup($notification, ?string $deliveryGroup = null): void
    {
        if ($deliveryGroup) {
            $this->_temp_delivery_group = $deliveryGroup;
        }

        $this->notify($notification);

        if ($deliveryGroup) {
            unset($this->_temp_delivery_group);
        }
    }

    /**
     * Route notifications for the Pushover channel.
     *
     * If notification has a deliveryGroup set, routes to that group.
     * Otherwise, routes to the user's individual pushover_key.
     */
    public function routeNotificationForPushover($notification): ?PushoverReceiver
    {
        $appToken = config('martingalian.api.pushover.application_key');

        // Determine delivery group from temp property (if set) or notification object
        // Note: Pushover package doesn't pass $notification, so it's often null
        $deliveryGroup = $this->_temp_delivery_group
            ?? ($notification && property_exists($notification, 'deliveryGroup') ? $notification->deliveryGroup : null);

        // If delivery group is specified, route to that group
        if ($deliveryGroup) {
            $groupConfig = config("martingalian.api.pushover.delivery_groups.{$deliveryGroup}");

            if (! $groupConfig || ! isset($groupConfig['group_key'])) {
                return null;
            }

            return PushoverReceiver::withUserKey($groupConfig['group_key'])
                ->withApplicationToken($appToken);
        }

        // Otherwise, route to user's individual pushover_key
        if (! $this->pushover_key) {
            return null;
        }

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
