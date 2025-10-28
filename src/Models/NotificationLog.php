<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NotificationLog
 *
 * Tracks when each user last received each notification type.
 * Used for per-user throttling to prevent notification spam.
 */
final class NotificationLog extends Model
{
    protected $fillable = [
        'user_id',
        'message_canonical',
        'last_sent_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if enough time has passed since the last notification of this type to this user.
     */
    public function canSendAgain(int $throttleSeconds): bool
    {
        return $this->last_sent_at->diffInSeconds(now()) >= $throttleSeconds;
    }

    /**
     * Update the last_sent_at timestamp to now.
     */
    public function markAsSent(): void
    {
        $this->update(['last_sent_at' => now()]);
    }

    public function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
        ];
    }
}
