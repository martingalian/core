<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Log;
use Martingalian\Core\Exceptions\MaxRetriesReachedException;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Skipped;
use Martingalian\Core\States\Stopped;
use Throwable;

/**
 * Trait HandlesStepLifecycle
 *
 * Manages the full lifecycle of a Step-based job execution.
 * Provides hooks for custom logic at each phase: preparation, guards,
 * execution, verification, and completion.
 */
trait HandlesStepLifecycle
{
    // ========================================================================
    // STATE TRANSITION HELPERS
    // ========================================================================

    public function stopJob(): void
    {
        $this->finalizeDuration();
        $this->step->state->transitionTo(Stopped::class);
        $this->stepStatusUpdated = true;
    }

    public function skipJob(): void
    {
        $this->finalizeDuration();
        $this->step->state->transitionTo(Skipped::class);
        $this->stepStatusUpdated = true;
    }

    public function retryJob(Carbon|CarbonImmutable|null $dispatchAfter = null): void
    {
        $this->step->update([
            'dispatch_after' => $dispatchAfter ?? now()->addSeconds($this->jobBackoffSeconds),
        ]);

        $this->step->state->transitionTo(Pending::class);
        $this->stepStatusUpdated = true;
    }

    public function retryForConfirmation(): void
    {
        $this->step->update(['execution_mode' => 'confirming-completion']);
        $this->step->state->transitionTo(Pending::class);
        $this->stepStatusUpdated = true;
    }
    // ========================================================================
    // PREPARATION PHASE
    // ========================================================================

    protected function attachRelatable(): void
    {
        if (! method_exists($this, 'relatable')) {
            return;
        }

        $relatable = $this->relatable();

        if ($relatable && method_exists($this->step, 'relatable')) {
            $this->step->relatable()->associate($relatable);
            $this->step->save();
        }
    }

    protected function checkMaxRetries(): void
    {
        if ($this->step->retries >= $this->retries) {
            $diagnostics = [];

            // Add context about why retries might be happening
            if (method_exists($this, 'assignExceptionHandler') && method_exists($this, 'exceptionHandler')) {
                try {
                    if (! isset($this->exceptionHandler)) {
                        $this->assignExceptionHandler();
                    }

                    $hostname = \Martingalian\Core\Models\Martingalian::ip();
                    $isForbidden = \Martingalian\Core\Models\ForbiddenHostname::query()
                        ->where('account_id', $this->exceptionHandler->account->id)
                        ->where('ip_address', $hostname)
                        ->exists();

                    if ($isForbidden) {
                        $diagnostics[] = "Hostname {$hostname} is FORBIDDEN for account {$this->exceptionHandler->account->id}";
                    }
                } catch (Throwable $e) {
                    // Silently skip diagnostics if anything fails
                }
            }

            $message = "Max retries ({$this->step->retries}) reached for Step ID {$this->step->id}.";
            if (! empty($diagnostics)) {
                $message .= ' | Diagnostics: '.implode(', ', $diagnostics);
            }

            throw new MaxRetriesReachedException($message);
        }
    }

    // ========================================================================
    // LIFECYCLE GUARD HOOKS (Override in child job)
    // ========================================================================

    protected function shouldStartOrStop(): bool
    {
        return ! method_exists($this, 'startOrStop') || $this->startOrStop() !== false;
    }

    protected function shouldStartOrSkip(): bool
    {
        return ! method_exists($this, 'startOrSkip') || $this->startOrSkip() !== false;
    }

    protected function shouldStartOrFail(): bool
    {
        return ! method_exists($this, 'startOrFail') || $this->startOrFail() !== false;
    }

    protected function shouldStartOrRetry(): bool
    {
        return ! method_exists($this, 'startOrRetry') || $this->startOrRetry() !== false;
    }

    protected function shouldConfirmOrRetry(): bool
    {
        return ! method_exists($this, 'confirmOrRetry') || $this->confirmOrRetry() !== false;
    }

    protected function shouldComplete(): void
    {
        if (method_exists($this, 'complete')) {
            $this->complete();
        }
    }

    // ========================================================================
    // VERIFICATION & DOUBLE-CHECK LOGIC
    // ========================================================================

    protected function shouldDoubleCheck(): bool
    {
        if (! method_exists($this, 'doubleCheck') || $this->step->double_check >= 2) {
            return false;
        }

        $result = $this->doubleCheck();

        if ($result === false) {
            $this->step->increment('double_check');
            $this->retryJob();

            return true;
        }

        if ($result === true) {
            $this->step->update(['double_check' => 99]);

            return false;
        }

        return false;
    }

    protected function shouldRunConfirmingCompletionMode(): bool
    {
        return $this->step->execution_mode === 'confirming-completion';
    }

    protected function confirmCompletionOrRetry(): void
    {
        if (! method_exists($this, 'confirmOrRetry')) {
            return;
        }

        $result = $this->confirmOrRetry();

        if ($result === false) {
            $this->retryForConfirmation();

            return;
        }

        $this->completeIfNotHandled();
    }

    protected function completeIfNotHandled(): void
    {
        if ($this->stepStatusUpdated) {
            return;
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
        $this->stepStatusUpdated = true;
    }

    // ========================================================================
    // COMPUTE & RESULT STORAGE
    // ========================================================================

    protected function computeAndStoreResult(): void
    {
        $stepId = $this->step->id ?? 'unknown';
        $jobClass = class_basename($this);

        $computeStart = microtime(true);
        Log::channel('jobs')->info("[COMPUTE-START] Step #{$stepId} | {$jobClass} | Starting compute()...");

        $result = $this->compute();

        $computeTime = round((microtime(true) - $computeStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE-END] Step #{$stepId} | {$jobClass} | compute() completed: {$computeTime}ms");

        if (! $result || ! is_null($this->step->response)) {
            Log::channel('jobs')->info("[COMPUTE-SKIP] Step #{$stepId} | {$jobClass} | Skipping result storage (no result or response already set)");

            return;
        }

        $storeStart = microtime(true);
        $this->step->update([
            'response' => $this->formatResultForStorage($result),
        ]);
        $storeTime = round((microtime(true) - $storeStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE-STORE] Step #{$stepId} | {$jobClass} | Result stored: {$storeTime}ms");
    }
}
