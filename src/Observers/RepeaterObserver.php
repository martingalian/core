<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\Repeater;

final class RepeaterObserver
{
    /**
     * Handle the Repeater "creating" event.
     */
    public function creating(Repeater $repeater): void
    {
        // Set defaults if not provided
        if (! $repeater->retry_strategy) {
            $repeater->retry_strategy = 'proportional';
        }

        if (! $repeater->retry_interval_minutes) {
            $repeater->retry_interval_minutes = 1;
        }

        if (! $repeater->max_attempts) {
            $repeater->max_attempts = 15;
        }

        // If queue is not set, default to 'repeaters'
        if (! $repeater->queue) {
            $repeater->queue = 'repeaters';
        }

        // If next_run_at is not set, calculate it from retry_interval_minutes
        if (! $repeater->next_run_at) {
            $repeater->next_run_at = now()->addMinutes($repeater->retry_interval_minutes);
        }
    }
}
