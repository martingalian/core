<?php

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Martingalian\Core\Exceptions\MaxRetriesReachedException;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Skipped;
use Martingalian\Core\States\Stopped;
use Illuminate\Support\Carbon;

/*
 * Trait HandlesStepLifecycle
 *
 * Purpose:
 * - Manages lifecycle behaviors of a Step within a job.
 * - Offers hooks for lifecycle branching: retry, skip, stop, confirm.
 * - Supports custom job logic for controlling execution flow.
 *
 * Key behaviors:
 * - Executes preparation step and relates external model if defined.
 * - Detects max retries and throws exception when limit reached.
 * - Uses conditional methods to control whether to start or skip/fail.
 * - Implements double-check flow with auto-retry and result reset.
 * - Supports confirmation loop for async jobs (e.g. confirming-completion).
 * - Provides helpers to mark step as skipped, stopped, completed, or retry.
 */
trait HandlesStepLifecycle
{
    protected function attachRelatable(): void
    {
        if (method_exists($this, 'relatable')) {
            $relatable = $this->relatable();

            if ($relatable && method_exists($this->step, 'relatable')) {
                $this->step->relatable()->associate($relatable);
                $this->step->save();
            }
        }
    }

    protected function checkMaxRetries(): void
    {
        if ($this->step->retries == $this->retries) {
            throw new MaxRetriesReachedException("Max retries (retries: {$this->step->retries}) reached for job ID {$this->step->id}.");
        }
    }

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

    protected function shouldDoubleCheck(): bool
    {
        if (method_exists($this, 'doubleCheck') && $this->step->double_check < 2) {
            /**
             * Lets run the double check. If it already ran then we need
             * to continue the process.
             */
            $result = $this->doubleCheck();

            if ($result === false) {
                // Retry 2x then it's over.
                $this->step->increment('double_check');
                $this->retryJob();

                return true;
            }

            if ($result === true) {
                /**
                 * Means it was double checked, and it went well.
                 * No need to retry at all.
                 */
                $this->step->update(['double_check' => 99]);

                return false;
            }
        }

        return false;
    }

    protected function shouldComplete(): void
    {
        if (method_exists($this, 'complete')) {
            $this->complete();
        }
    }

    protected function confirmCompletionOrRetry(): void
    {
        /*
         * Runs confirmOrRetry() and retries or completes accordingly.
         * Used for confirming-completion mode jobs.
         */
        if (method_exists($this, 'confirmOrRetry')) {
            $result = $this->confirmOrRetry();

            if ($result === false) {
                $this->retryForConfirmation();

                return;
            }

            $this->completeIfNotHandled();
        }
    }

    public function stopJob(): void
    {
        /*
         * Transition step to Stopped state.
         * Used when job is manually or logically stopped.
         */
        $this->finalizeDuration();
        $this->step->state->transitionTo(Stopped::class);
        $this->stepStatusUpdated = true;
    }

    public function skipJob(): void
    {
        /*
         * Transition step to Skipped state.
         * Used for optional or bypassed jobs.
         */
        $this->finalizeDuration();
        $this->step->state->transitionTo(Skipped::class);
        $this->stepStatusUpdated = true;
    }

    public function retryJob(?Carbon $dispatchAfter = null): void
    {
        $this->step->update([
            'dispatch_after' => $dispatchAfter ?? now()->addSeconds($this->jobBackoffSeconds),
        ]);

        $this->step->state->transitionTo(Pending::class);
        $this->stepStatusUpdated = true;
    }

    public function retryForConfirmation(): void
    {
        /*
         * Set execution_mode to confirming-completion
         * and mark step as pending for retry.
         */
        $this->step->update(['execution_mode' => 'confirming-completion']);
        $this->step->state->transitionTo(Pending::class);
        $this->stepStatusUpdated = true;
    }

    protected function completeIfNotHandled(): void
    {
        if (! $this->stepStatusUpdated) {
            $this->finalizeDuration();
            $this->step->state->transitionTo(Completed::class);
            $this->stepStatusUpdated = true;
        }
    }

    protected function computeAndStoreResult(): void
    {
        /*
         * Executes compute() and optionally stores the result
         * if none is already present in the step.
         */
        $result = $this->compute();

        $updateData = [];

        if ($result && is_null($this->step->response)) {
            $updateData['response'] = $this->formatResultForStorage($result);
        }

        if (! empty($updateData)) {
            $this->step->update($updateData);
        }
    }

    protected function shouldRunConfirmingCompletionMode(): bool
    {
        /*
         * Returns true if step is in confirming-completion mode.
         */
        return $this->step->execution_mode == 'confirming-completion';
    }
}
