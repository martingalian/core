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
        $contextableType = $contextable ? $contextable::class : null;
        $contextableId = $contextable ? $contextable->getKey() : null;

        // Query for existing record with NULL-safe comparison
        $query = self::query()
            ->where('canonical', $canonical);

        if ($contextableType !== null) {
            $query->where('contextable_type', $contextableType)
                ->where('contextable_id', $contextableId);
        } else {
            $query->whereNull('contextable_type')
                ->whereNull('contextable_id');
        }

        $existing = $query->first();

        if ($existing) {
            // Update existing record
            $existing->update([
                'last_executed_at' => now(),
            ]);
        } else {
            // Create new record
            self::create([
                'canonical' => $canonical,
                'contextable_type' => $contextableType,
                'contextable_id' => $contextableId,
                'last_executed_at' => now(),
            ]);
        }
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
