<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NotificationThrottleRule
 *
 * Defines throttling rules for different notification types.
 * Each rule specifies a message_canonical (identifier) and throttle_seconds (minimum time between notifications).
 */
final class NotificationThrottleRule extends Model
{
    protected $fillable = [
        'message_canonical',
        'throttle_seconds',
        'description',
        'is_active',
    ];

    /**
     * Get the throttle rule for a given message canonical identifier.
     * Returns null if no active rule exists.
     */
    public static function findByCanonical(string $messageCanonical): ?self
    {
        return self::query()
            ->where('message_canonical', $messageCanonical)
            ->where('is_active', true)
            ->first();
    }

    protected function casts(): array
    {
        return [
            'throttle_seconds' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
