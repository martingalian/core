<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $loggable_type
 * @property int $loggable_id
 * @property string|null $relatable_type
 * @property int|null $relatable_id
 * @property string $event_type
 * @property string|null $attribute_name
 * @property string|null $message
 * @property array|null $previous_value
 * @property array|null $new_value
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ApplicationLog extends BaseModel
{
    /**
     * Global flag to enable/disable all application logging.
     * Set to false to disable logging (useful during seeding/migrations).
     */
    protected static bool $enabled = true;

    protected array $skipsLogging = ['created_at', 'updated_at'];

    /**
     * Disable all application logging globally.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Enable all application logging globally.
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Check if application logging is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    protected function casts(): array
    {
        return [
            'previous_value' => 'array',
            'new_value' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * The model that was changed.
     */
    public function loggable()
    {
        return $this->morphTo();
    }

    /**
     * The model that triggered this change (optional).
     */
    public function relatable()
    {
        return $this->morphTo();
    }
}
