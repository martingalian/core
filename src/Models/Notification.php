<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Concerns\Notification\HasGetters;
use Martingalian\Core\Concerns\Notification\HasScopes;
use Martingalian\Core\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification
 *
 * Registry of notification message templates available in the system.
 * These are base canonicals (e.g., 'ip_not_whitelisted') used by NotificationMessageBuilder.
 *
 * Completely separate from throttle_rules - this controls WHAT to say, not HOW OFTEN to say it.
 *
 * @property int $id
 * @property string $canonical
 * @property string $title
 * @property string|null $description
 * @property NotificationSeverity|null $default_severity
 * @property bool $is_active
 * @property array<int, string> $user_types
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Notification extends Model
{
    use HasGetters;
    use HasScopes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'default_severity' => NotificationSeverity::class,
        'user_types' => 'array',
    ];
}
