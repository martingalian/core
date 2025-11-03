<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverReceiver;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $pushover_key
 * @property array<int, string> $notification_channels
 * @property \Illuminate\Support\Carbon|null $last_logged_in_at
 * @property \Illuminate\Support\Carbon|null $previous_logged_in_at
 * @property bool $can_trade
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property string|null $_temp_delivery_group
 */
final class User extends Authenticatable
{
    use HasFactory;
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

        'password' => 'hashed',
        'pushover_key' => 'encrypted',
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

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function throttleLogs()
    {
        return $this->morphMany(\Martingalian\Core\Models\ThrottleLog::class, 'contextable');
    }

    public function notificationLogs()
    {
        return $this->morphMany(\Martingalian\Core\Models\NotificationLog::class, 'relatable');
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
     * Route notifications for the Mail channel.
     *
     * Returns the user's email address for mail notifications.
     */
    public function routeNotificationForMail($notification): ?string
    {
        return $this->email;
    }

    /**
     * Route notifications for the Pushover channel.
     *
     * If notification has a deliveryGroup set, routes to that group.
     * Otherwise, routes to the user's individual pushover_key.
     */
    public function routeNotificationForPushover($notification): ?PushoverReceiver
    {
        // Get application token from Martingalian model
        $martingalian = Martingalian::find(1);

        if (! $martingalian || ! $martingalian->admin_pushover_application_key) {
            return null;
        }

        $appToken = $martingalian->admin_pushover_application_key;

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
            return [];
        }

        return array_map(function ($channel) {
            return match ($channel) {
                'pushover' => PushoverChannel::class,
                'mail' => 'mail',
                default => $channel
            };
        }, $channels);
    }

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\UserFactory::new();
    }
}
