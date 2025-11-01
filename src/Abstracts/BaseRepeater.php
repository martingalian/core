<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Carbon\Carbon;
use Martingalian\Core\Models\Repeater;

abstract class BaseRepeater
{
    protected Repeater $repeater;

    public function __construct(...$parameters) {}

    /**
     * Set the repeater instance (called by ProcessRepeatersJob)
     */
    public function setRepeater(Repeater $repeater): void
    {
        $this->repeater = $repeater;
    }

    /**
     * Execute the repeater logic
     * Return true on success, false on failure
     */
    abstract public function __invoke(): bool;

    /**
     * Called when repeater execution succeeds
     * Executes child hook then deletes record
     * FINAL - cannot be overridden by child classes
     */
    final public function passed(): void
    {
        $this->onPassed();
    }

    /**
     * Called when repeater execution fails
     * Executes child hook then updates next_run_at
     * FINAL - cannot be overridden by child classes
     */
    final public function failed(): void
    {
        $this->onFailed();
    }

    /**
     * Called when max attempts reached
     * Executes child hook then deletes record
     * FINAL - cannot be overridden by child classes
     */
    final public function maxAttemptsReached(): void
    {
        $this->onMaxAttemptsReached();
    }

    /**
     * Hook: Called when repeater execution succeeds
     * Override in child class to add custom success logic
     */
    protected function onPassed(): void
    {
        // Override in child class if needed
    }

    /**
     * Hook: Called when repeater execution fails
     * Override in child class to add custom failure logic
     */
    protected function onFailed(): void
    {
        // Override in child class if needed
    }

    /**
     * Hook: Called when max attempts reached
     * Override in child class to add custom max attempts logic
     */
    protected function onMaxAttemptsReached(): void
    {
        // Override in child class if needed
    }

    /**
     * Calculate next retry interval based on strategy
     * Can be overridden for custom strategies
     */
    public function calculateNextRetryAt(): Carbon
    {
        $baseInterval = $this->repeater->retry_interval_minutes;
        $attempts = $this->repeater->attempts;

        $minutes = match ($this->repeater->retry_strategy) {
            'exponential' => $baseInterval * pow(2, $attempts - 1),
            'proportional' => $baseInterval,
            default => $baseInterval,
        };

        return now()->addMinutes((int) $minutes);
    }
}
