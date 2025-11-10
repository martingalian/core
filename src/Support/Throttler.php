<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Martingalian\Core\Models\ThrottleLog;
use Martingalian\Core\Models\ThrottleRule;

/**
 * Throttler
 *
 * Unified throttling system for any action (notifications, supervisor restarts, API calls, database queries).
 * Uses database-driven throttle rules and execution logs.
 *
 * Usage (Fluent API):
 *   Throttler::using(NotificationService::class)
 *       ->withCanonical('critical_alert')
 *       ->for($user)
 *       ->throttleFor(300)
 *       ->execute(function () use ($user) {
 *           NotificationService::send($user, 'Alert message');
 *       });
 *
 *   Throttler::using(BinanceThrottler::class)
 *       ->withCanonical('fetch_balance')
 *       ->for($account)
 *       ->executeNow(function () use ($account) {
 *           return $account->binanceApi()->getBalance();
 *       });
 */
final class Throttler
{
    private string $strategyClass;

    private string $canonical;

    private ?int $throttleSecondsOverride = null;

    private ?Model $contextable = null;

    /**
     * Start building a throttler with a specific strategy class.
     *
     * @param  string  $strategyClass  The throttler strategy class (e.g., NotificationService::class, BinanceThrottler::class)
     */
    public static function using(string $strategyClass): self
    {
        $instance = new self;
        $instance->strategyClass = $strategyClass;

        return $instance;
    }

    /**
     * Set the canonical identifier for this throttle rule.
     *
     * @param  string  $canonical  Unique identifier for this throttle rule
     */
    public function withCanonical(string $canonical): self
    {
        $this->canonical = $canonical;

        return $this;
    }

    /**
     * Set the contextable model this throttle applies to (User, Account, etc).
     *
     * @param  Model|null  $contextable  The model this throttle applies to
     */
    public function for(?Model $contextable): self
    {
        $this->contextable = $contextable;

        return $this;
    }

    /**
     * Override the throttle seconds for this execution.
     *
     * @param  int  $seconds  Number of seconds to throttle
     */
    public function throttleFor(int $seconds): self
    {
        $this->throttleSecondsOverride = $seconds;

        return $this;
    }

    /**
     * Execute the given callback if throttling allows it.
     *
     * Uses INSERT IGNORE + UPDATE pattern to prevent deadlocks when multiple
     * processes try to execute the same throttled action simultaneously.
     *
     * @param  Closure  $callback  The action to execute if not throttled
     * @return bool True if throttled (callback NOT executed), false if executed
     */
    public function execute(Closure $callback): bool
    {
        // Get active throttle rule
        $throttleRule = ThrottleRule::findByCanonical($this->canonical);

        // If no rule exists, check auto-create config
        if (! $throttleRule) {
            $autoCreate = config('martingalian.auto_create_missing_throttle_rules', true);

            if (! $autoCreate) {
                // No rule and auto-create disabled = throttled (do not execute)
                return true;
            }

            // Auto-create the rule with auto-generated description
            $throttleRule = ThrottleRule::getOrCreate($this->canonical, $this->strategyClass);
        }

        // Use override or rule's throttle seconds
        $throttleSeconds = $this->throttleSecondsOverride ?? $throttleRule->throttle_seconds;

        // If throttle is 0 seconds, execute immediately without any throttle logic or log creation
        if ($throttleSeconds === 0) {
            $callback();

            return false; // Not throttled, executed
        }

        // Use a short-lived transaction ONLY for the throttle check
        // The callback is executed OUTSIDE the transaction to prevent deadlocks
        $shouldExecute = \Illuminate\Support\Facades\DB::transaction(function () use ($throttleSeconds) {
            // Build query with lock
            $query = ThrottleLog::where('canonical', $this->canonical);

            if ($this->contextable) {
                $query->where('contextable_type', $this->contextable::class)
                    ->where('contextable_id', $this->contextable->getKey());
            } else {
                $query->whereNull('contextable_type')
                    ->whereNull('contextable_id');
            }

            // Lock the row - other processes will wait here
            $log = $query->lockForUpdate()->first();

            // Never executed before for this contextable
            if (! $log) {
                // Create the log entry inside transaction to prevent duplicates
                ThrottleLog::create([
                    'canonical' => $this->canonical,
                    'contextable_type' => $this->contextable ? $this->contextable::class : null,
                    'contextable_id' => $this->contextable ? $this->contextable->getKey() : null,
                    'last_executed_at' => now(),
                ]);

                return true; // Should execute
            }

            // Check if enough time has passed
            if ($log->canExecuteAgain($throttleSeconds)) {
                // Update the timestamp inside transaction
                $log->update(['last_executed_at' => now()]);

                return true; // Should execute
            }

            // Throttled - do NOT execute
            return false;
        });

        // Execute callback OUTSIDE transaction to prevent deadlocks
        if ($shouldExecute) {
            $callback();

            return false; // Not throttled, executed
        }

        return true; // Throttled, not executed
    }

    /**
     * Execute the callback immediately without throttle checks, but still record execution.
     *
     * @param  Closure  $callback  The action to execute
     * @return mixed The result of the callback
     */
    public function executeNow(Closure $callback): mixed
    {
        $result = $callback();

        // Still record execution in throttle log
        ThrottleLog::recordExecution($this->canonical, $this->contextable);

        return $result;
    }
}
