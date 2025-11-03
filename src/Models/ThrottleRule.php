<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $canonical
 * @property int $throttle_seconds
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ThrottleRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'throttle_seconds' => 'integer',
    ];

    /**
     * Find a throttle rule by canonical identifier.
     */
    public static function findByCanonical(string $canonical): ?self
    {
        return self::where('canonical', $canonical)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get or create a throttle rule with default settings.
     * Auto-generates a description based on canonical and strategy class.
     *
     * @param  string  $canonical  The canonical identifier
     * @param  string|null  $strategyClass  The strategy class (e.g., NotificationService::class)
     */
    public static function getOrCreate(string $canonical, ?string $strategyClass = null): self
    {
        return self::firstOrCreate(
            ['canonical' => $canonical],
            [
                'description' => self::generateDescription($canonical, $strategyClass),
                'throttle_seconds' => config('martingalian.default_throttle_seconds', 300),
                'is_active' => true,
            ]
        );
    }

    /**
     * Generate a human-readable description from canonical and strategy class.
     *
     * @param  string  $canonical  The canonical identifier (e.g., 'symbol_synced')
     * @param  string|null  $strategyClass  The strategy class (e.g., 'App\Support\NotificationService')
     */
    private static function generateDescription(string $canonical, ?string $strategyClass): string
    {
        // Convert snake_case to Title Case (e.g., 'symbol_synced' -> 'Symbol Synced')
        $title = str_replace('_', ' ', $canonical);
        $title = ucwords($title);

        // Extract simple class name from FQCN (e.g., 'App\Support\NotificationService' -> 'NotificationService')
        $className = $strategyClass ? class_basename($strategyClass) : 'Unknown';

        return "{$title} in {$className}";
    }
}
