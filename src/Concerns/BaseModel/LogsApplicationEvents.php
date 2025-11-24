<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\BaseModel;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Models\ApplicationLog;
use Martingalian\Core\Observers\ApplicationLogObserver;

/**
 * Trait for application logging functionality on BaseModel.
 *
 * This trait provides:
 * - Automatic observer registration for ApplicationLogObserver
 * - Default blacklist for timestamp columns
 * - skipLogging() method for conditional filtering
 * - appLog() method for manual logging
 */
trait LogsApplicationEvents
{
    /**
     * Boot the LogsApplicationEvents trait for a model.
     * Registers ApplicationLogObserver for automatic change tracking.
     */
    protected static function bootLogsApplicationEvents(): void
    {
        static::observe(ApplicationLogObserver::class);
    }
    /**
     * Default blacklist - skip timestamp columns by default for application logging.
     */
    protected array $skipsLogging = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Determine if logging should be skipped for a specific attribute change.
     * Override this method in child models for custom logic.
     *
     * Works for BOTH created() and updated() events.
     *
     * @return bool True = skip logging, False = log it
     */
    public function skipLogging(string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        return false; // By default, don't skip (will be logged)
    }

    /**
     * Manually log an application event for this model.
     *
     * @param  string  $eventType  The type of event (e.g., 'job_failed', 'order_filled')
     * @param  array  $metadata  Additional data to store with the log
     * @param  BaseModel|null  $relatable  The model that triggered this event (optional)
     * @param  string|null  $message  Optional human-readable message
     */
    public function appLog(
        string $eventType,
        array $metadata = [],
        ?BaseModel $relatable = null,
        ?string $message = null
    ): ?ApplicationLog {
        // Skip if logging is globally disabled
        if (! ApplicationLog::isEnabled()) {
            return null;
        }

        return ApplicationLog::create([
            'loggable_type' => static::class,
            'loggable_id' => $this->getKey(),
            'relatable_type' => $relatable ? get_class($relatable) : null,
            'relatable_id' => $relatable?->getKey(),
            'event_type' => $eventType,
            'metadata' => $metadata,
            'message' => $message,
        ]);
    }
}
