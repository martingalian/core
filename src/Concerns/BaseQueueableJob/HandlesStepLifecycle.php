<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Log;
use Martingalian\Core\Exceptions\MaxRetriesReachedException;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Skipped;
use Martingalian\Core\States\Stopped;

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
        if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
            $stepId = $this->step->id;
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', '→→→ STOP-JOB START ←←←');
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', 'BEFORE STOP:');
            Step::log($stepId, 'job', '  - Current state: '.$this->step->state);
            Step::log($stepId, 'job', '  - Current retries: '.$this->step->retries);
            Step::log($stepId, 'job', '  - Job class: '.class_basename($this));
            Step::log($stepId, 'job', '  - Duration so far: '.round((microtime(true) - $this->startMicrotime) * 1000, 2).'ms');
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Stopped::class);
        $this->stepStatusUpdated = true;

        if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
            $freshStep = $this->step->fresh();
            Step::log($this->step->id, 'job', 'AFTER STOP (refreshed from DB):');
            Step::log($this->step->id, 'job', '  - Fresh state: '.$freshStep->state);
            Step::log($this->step->id, 'job', '  - Fresh duration: '.$freshStep->duration.'ms');
            Step::log($this->step->id, 'job', '  - stepStatusUpdated set to: true');
            Step::log($this->step->id, 'job', '✓ Job stopped successfully');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
            Step::log($this->step->id, 'job', '→→→ STOP-JOB END ←←←');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
        }
    }

    public function skipJob(): void
    {
        if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
            $stepId = $this->step->id;
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', '→→→ SKIP-JOB START ←←←');
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', 'BEFORE SKIP:');
            Step::log($stepId, 'job', '  - Current state: '.$this->step->state);
            Step::log($stepId, 'job', '  - Current retries: '.$this->step->retries);
            Step::log($stepId, 'job', '  - Job class: '.class_basename($this));
            Step::log($stepId, 'job', '  - Duration so far: '.round((microtime(true) - $this->startMicrotime) * 1000, 2).'ms');
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Skipped::class);
        $this->stepStatusUpdated = true;

        if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
            $freshStep = $this->step->fresh();
            Step::log($this->step->id, 'job', 'AFTER SKIP (refreshed from DB):');
            Step::log($this->step->id, 'job', '  - Fresh state: '.$freshStep->state);
            Step::log($this->step->id, 'job', '  - Fresh duration: '.$freshStep->duration.'ms');
            Step::log($this->step->id, 'job', '  - stepStatusUpdated set to: true');
            Step::log($this->step->id, 'job', '✓ Job skipped successfully');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
            Step::log($this->step->id, 'job', '→→→ SKIP-JOB END ←←←');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
        }
    }

    public function retryJob(Carbon|CarbonImmutable|null $dispatchAfter = null): void
    {
        $stepId = $this->step->id;
        Step::log($stepId, 'job', "═══════════════════════════════════");
        Step::log($stepId, 'job', "→→→ RETRY-JOB START ←←←");
        Step::log($stepId, 'job', "═══════════════════════════════════");

        $dispatchTime = $dispatchAfter ?? now()->addSeconds($this->jobBackoffSeconds);

        Step::log($stepId, 'job', "BEFORE RETRY:");
        Step::log($stepId, 'job', "  - Current state: {$this->step->state}");
        Step::log($stepId, 'job', "  - Current retries: {$this->step->retries}");
        Step::log($stepId, 'job', "  - Backoff seconds: {$this->jobBackoffSeconds}");
        Step::log($stepId, 'job', "  - Dispatch after: {$dispatchTime->format('Y-m-d H:i:s')}");
        Step::log($stepId, 'job', "  - Priority: {$this->step->priority}");
        Step::log($stepId, 'job', "  - Job class: ".class_basename($this));

        // Check if step should be escalated to high priority
        if (method_exists($this, 'shouldChangeToHighPriority') && $this->shouldChangeToHighPriority() === true) {
            Step::log($stepId, 'job', "⬆️ ESCALATING to high priority");
            $this->step->update(['priority' => 'high']);
            Step::log($stepId, 'job', "  - Priority updated to: high");
        } else {
            Step::log($stepId, 'job', "  - No priority escalation needed");
        }

        Step::log($stepId, 'job', "UPDATING dispatch_after AND CLEARING THROTTLE FLAG:");
        Step::log($stepId, 'job', "  - Setting dispatch_after to: {$dispatchTime}");
        Step::log($stepId, 'job', "  - Setting is_throttled = false (this is a REAL retry, not a throttle)");
        $this->step->update([
            'dispatch_after' => $dispatchTime,
            'is_throttled' => false,  // Ensure transition WILL increment retries
        ]);
        Step::log($stepId, 'job', "  - dispatch_after and is_throttled updated successfully");

        Step::log($stepId, 'job', "CALLING transitionTo(Pending::class)...");
        Step::log($stepId, 'job', "  - This WILL increment retries via RunningToPending transition");
        Step::log($stepId, 'job', "  - is_throttled = false, so transition will increment retries");
        Step::log($stepId, 'job', "  - Current state: {$this->step->state} → Target state: Pending");
        $this->step->state->transitionTo(Pending::class);
        Step::log($stepId, 'job', "  - transitionTo() completed");

        $freshStep = $this->step->fresh();
        Step::log($stepId, 'job', "AFTER RETRY (refreshed from DB):");
        Step::log($stepId, 'job', "  - Fresh state: {$freshStep->state}");
        Step::log($stepId, 'job', "  - Fresh retries: {$freshStep->retries} ← SHOULD BE INCREMENTED");
        Step::log($stepId, 'job', "  - Fresh dispatch_after: {$freshStep->dispatch_after}");
        Step::log($stepId, 'job', "  - Fresh priority: {$freshStep->priority}");

        $this->stepStatusUpdated = true;
        Step::log($stepId, 'job', "  - stepStatusUpdated set to: true");
        Step::log($stepId, 'job', "═══════════════════════════════════");
        Step::log($stepId, 'job', "→→→ RETRY-JOB END ←←←");
        Step::log($stepId, 'job', "═══════════════════════════════════");
    }

    public function rescheduleWithoutRetry(Carbon|CarbonImmutable|null $dispatchAfter = null): void
    {
        $stepId = $this->step->id;
        Step::log($stepId, 'job', "═════════════════════════════════════════════");
        Step::log($stepId, 'job', "→→→ RESCHEDULE-WITHOUT-RETRY START ←←←");
        Step::log($stepId, 'job', "═════════════════════════════════════════════");

        $dispatchTime = $dispatchAfter ?? now()->addSeconds($this->jobBackoffSeconds);

        Step::log($stepId, 'job', "BEFORE RESCHEDULE:");
        Step::log($stepId, 'job', "  - Current state: {$this->step->state}");
        Step::log($stepId, 'job', "  - Current retries: {$this->step->retries}");
        Step::log($stepId, 'job', "  - Backoff seconds: {$this->jobBackoffSeconds}");
        Step::log($stepId, 'job', "  - Dispatch after: {$dispatchTime->format('Y-m-d H:i:s')}");
        Step::log($stepId, 'job', "  - Priority: {$this->step->priority}");
        Step::log($stepId, 'job', "  - Job class: ".class_basename($this));

        // Check if step should be escalated to high priority
        if (method_exists($this, 'shouldChangeToHighPriority') && $this->shouldChangeToHighPriority() === true) {
            Step::log($stepId, 'job', "⬆️ ESCALATING to high priority");
            $this->step->update(['priority' => 'high']);
            Step::log($stepId, 'job', "  - Priority updated to: high");
        } else {
            Step::log($stepId, 'job', "  - No priority escalation needed");
        }

        // Set dispatch_after and throttling flags BEFORE transition
        Step::log($stepId, 'job', "SETTING DISPATCH AND THROTTLE FLAGS:");
        Step::log($stepId, 'job', "  - Setting dispatch_after = {$dispatchTime}");
        $this->step->dispatch_after = $dispatchTime;
        Step::log($stepId, 'job', "  - Setting was_throttled = true (historical marker)");
        $this->step->was_throttled = true;  // Historical: step has been throttled at least once
        Step::log($stepId, 'job', "  - Setting is_throttled = true (currently throttled)");
        $this->step->is_throttled = true;   // Current: step is currently waiting due to throttling

        Step::log($stepId, 'job', "  - Saving flags to database before transition...");
        $this->step->save();
        Step::log($stepId, 'job', "  - Flags saved successfully");

        // Use proper transition! The is_throttled flag signals to NOT increment retries
        Step::log($stepId, 'job', "⚠️⚠️⚠️ USING PROPER TRANSITION: Running → Pending ⚠️⚠️⚠️");
        Step::log($stepId, 'job', "  - Calling state->transitionTo(Pending::class)");
        Step::log($stepId, 'job', "  - This will use RunningToPending transition");
        Step::log($stepId, 'job', "  - Transition will check is_throttled flag");
        Step::log($stepId, 'job', "  - Since is_throttled = true, retries will NOT increment");
        Step::log($stepId, 'job', "  - Old state: {$this->step->state}");

        $this->step->state->transitionTo(Pending::class);

        Step::log($stepId, 'job', "  - transitionTo() completed successfully");
        Step::log($stepId, 'job', "  - New state: {$this->step->state}");

        $freshStep = $this->step->fresh();
        Step::log($stepId, 'job', "AFTER RESCHEDULE (refreshed from DB):");
        Step::log($stepId, 'job', "  - Fresh state: {$freshStep->state}");
        Step::log($stepId, 'job', "  - Fresh retries: {$freshStep->retries} ← SHOULD BE UNCHANGED!");
        Step::log($stepId, 'job', "  - Fresh was_throttled: {$freshStep->was_throttled}");
        Step::log($stepId, 'job', "  - Fresh is_throttled: {$freshStep->is_throttled}");
        Step::log($stepId, 'job', "  - Fresh dispatch_after: {$freshStep->dispatch_after}");
        Step::log($stepId, 'job', "  - Fresh priority: {$freshStep->priority}");

        $this->stepStatusUpdated = true;
        Step::log($stepId, 'job', "  - stepStatusUpdated set to: true");
        Step::log($stepId, 'job', "═════════════════════════════════════════════");
        Step::log($stepId, 'job', "→→→ RESCHEDULE-WITHOUT-RETRY END ←←←");
        Step::log($stepId, 'job', "═════════════════════════════════════════════");
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
            $diagnostics = $this->getRetryDiagnostics();

            $message = "Max retries ({$this->step->retries}) reached for Step ID {$this->step->id}.";
            if (! empty($diagnostics)) {
                $message .= ' | Diagnostics: '.implode(', ', $diagnostics);
            }

            throw new MaxRetriesReachedException($message);
        }
    }

    /**
     * Get diagnostic information for retry failures.
     * Override in subclasses to provide domain-specific diagnostics.
     */
    protected function getRetryDiagnostics(): array
    {
        return [];
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
            if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
                Step::log($this->step->id, 'job', '═══════════════════════════════════');
                Step::log($this->step->id, 'job', '→→→ COMPLETE-IF-NOT-HANDLED (SKIPPED) ←←←');
                Step::log($this->step->id, 'job', '═══════════════════════════════════');
                Step::log($this->step->id, 'job', '⚠️ stepStatusUpdated is already TRUE - skipping completion');
                Step::log($this->step->id, 'job', '  - Current state: '.$this->step->state);
                Step::log($this->step->id, 'job', '  - This means the step was already transitioned by another method');
                Step::log($this->step->id, 'job', '═══════════════════════════════════');
            }

            return;
        }

        if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
            $stepId = $this->step->id;
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', '→→→ COMPLETE-IF-NOT-HANDLED START ←←←');
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', 'BEFORE COMPLETE:');
            Step::log($stepId, 'job', '  - Current state: '.$this->step->state);
            Step::log($stepId, 'job', '  - Current retries: '.$this->step->retries);
            Step::log($stepId, 'job', '  - Job class: '.class_basename($this));
            Step::log($stepId, 'job', '  - Duration so far: '.round((microtime(true) - $this->startMicrotime) * 1000, 2).'ms');
            Step::log($stepId, 'job', '  - stepStatusUpdated: false (will be set to true)');
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
        $this->stepStatusUpdated = true;

        if (config('martingalian.logging.lifecycle_logging_enabled', true)) {
            $freshStep = $this->step->fresh();
            Step::log($this->step->id, 'job', 'AFTER COMPLETE (refreshed from DB):');
            Step::log($this->step->id, 'job', '  - Fresh state: '.$freshStep->state);
            Step::log($this->step->id, 'job', '  - Fresh duration: '.$freshStep->duration.'ms');
            Step::log($this->step->id, 'job', '  - stepStatusUpdated set to: true');
            Step::log($this->step->id, 'job', '✓ Job completed successfully');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
            Step::log($this->step->id, 'job', '→→→ COMPLETE-IF-NOT-HANDLED END ←←←');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
        }
    }

    // ========================================================================
    // COMPUTE & RESULT STORAGE
    // ========================================================================

    protected function computeAndStoreResult(): void
    {
        if (config('martingalian.logging.compute_logging_enabled', true)) {
            $stepId = $this->step->id;
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', '→→→ COMPUTE START ←←←');
            Step::log($stepId, 'job', '═══════════════════════════════════');
            Step::log($stepId, 'job', 'Calling compute() method...');
            Step::log($stepId, 'job', '  - Job class: '.class_basename($this));
        }

        $computeStartTime = microtime(true);
        $result = $this->compute();
        $computeDuration = round((microtime(true) - $computeStartTime) * 1000, 2);

        if (config('martingalian.logging.compute_logging_enabled', true)) {
            Step::log($this->step->id, 'job', '✓ compute() completed successfully');
            Step::log($this->step->id, 'job', '  - Compute duration: '.$computeDuration.'ms');
            Step::log($this->step->id, 'job', '  - Result type: '.(is_object($result) ? get_class($result) : gettype($result)));
            Step::log($this->step->id, 'job', '  - Result is null: '.(is_null($result) ? 'true' : 'false'));
            Step::log($this->step->id, 'job', '  - step->response is null: '.(is_null($this->step->response) ? 'true' : 'false'));
        }

        if (! $result || ! is_null($this->step->response)) {
            if (config('martingalian.logging.compute_logging_enabled', true)) {
                Step::log($this->step->id, 'job', '⚠️ Not storing result:');
                if (! $result) {
                    Step::log($this->step->id, 'job', '  - Reason: compute() returned null/false');
                }
                if (! is_null($this->step->response)) {
                    Step::log($this->step->id, 'job', '  - Reason: step->response already exists');
                }
                Step::log($this->step->id, 'job', '═══════════════════════════════════');
                Step::log($this->step->id, 'job', '→→→ COMPUTE END (not stored) ←←←');
                Step::log($this->step->id, 'job', '═══════════════════════════════════');
            }

            return;
        }

        if (config('martingalian.logging.compute_logging_enabled', true)) {
            Step::log($this->step->id, 'job', 'Formatting result for storage...');
        }

        $formattedResult = $this->formatResultForStorage($result);

        if (config('martingalian.logging.compute_logging_enabled', true)) {
            Step::log($this->step->id, 'job', '✓ Result formatted successfully');
            Step::log($this->step->id, 'job', 'Updating step->response in database...');
        }

        $this->step->update([
            'response' => $formattedResult,
        ]);

        if (config('martingalian.logging.compute_logging_enabled', true)) {
            Step::log($this->step->id, 'job', '✓ step->response updated successfully');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
            Step::log($this->step->id, 'job', '→→→ COMPUTE END (stored) ←←←');
            Step::log($this->step->id, 'job', '═══════════════════════════════════');
        }
    }
}
