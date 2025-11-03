<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $canonical
 * @property string|null $contextable_type
 * @property int|null $contextable_id
 * @property Carbon $last_executed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ThrottleLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_executed_at' => 'datetime',
    ];

    /**
     * Record that an action was executed now.
     *
     * @param  string  $canonical  Throttle rule canonical identifier
     * @param  Model|null  $contextable  The model this throttle applies to (User, Account, etc)
     */
    public static function recordExecution(string $canonical, ?Model $contextable = null): void
    {
        $attributes = ['canonical' => $canonical];

        if ($contextable) {
            $attributes['contextable_type'] = $contextable::class;
            $attributes['contextable_id'] = $contextable->getKey();
        } else {
            $attributes['contextable_type'] = null;
            $attributes['contextable_id'] = null;
        }

        self::updateOrCreate(
            $attributes,
            ['last_executed_at' => now()]
        );
    }

    /**
     * Get the contextable entity (User, Account, etc).
     *
     * @return MorphTo<Model, $this>
     */
    public function contextable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if enough time has passed since last execution.
     */
    public function canExecuteAgain(int $throttleSeconds): bool
    {
        return $this->last_executed_at->addSeconds($throttleSeconds)->isPast();
    }
}
