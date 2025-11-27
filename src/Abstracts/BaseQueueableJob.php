<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Support\Str;
use Log;
use Martingalian\Core\Concerns\BaseQueueableJob\FormatsStepResult;
use Martingalian\Core\Concerns\BaseQueueableJob\HandlesStepExceptions;
use Martingalian\Core\Concerns\BaseQueueableJob\HandlesStepLifecycle;
use Martingalian\Core\Exceptions\NonNotifiableException;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\Running;
use Throwable;

/*
 * BaseQueueableJob
 *
 * • Core abstract class for all step-based jobs in the GraphEngine system.
 * • Manages lifecycle transitions, status guards, retries, skips, and failures.
 * • Integrates with `Step` model and uses `HandlesStepLifecycle` for flow control.
 * • Supports optional "confirming-completion" mode via `confirmCompletionOrRetry()`.
 * • Executes compute logic and handles result formatting/storage.
 * • Provides structured logging and exception capture for robust debugging.
 */
abstract class BaseQueueableJob extends BaseJob
{
    use FormatsStepResult;
    use HandlesStepExceptions;
    use HandlesStepLifecycle;

    public Step $step;

    public int $jobBackoffSeconds = 10;

    public bool $stepStatusUpdated = false;

    public float $startMicrotime = 0.0;

    public ?BaseDatabaseExceptionHandler $databaseExceptionHandler = null;

    // Must be implemented by subclasses to define the compute logic.
    abstract protected function compute();

    final public function handle(): void
    {
        $startTime = microtime(true);
        $stepId = $this->step->id ?? 'unknown';
        $jobClass = class_basename($this);

        Step::log($stepId, 'job', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($stepId, 'job', '║       BASE-QUEUEABLE-JOB: handle() START                 ║');
        Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
        Step::log($stepId, 'job', 'Job class: '.$jobClass);
        Step::log($stepId, 'job', 'Step ID: '.$stepId);

        try {
            Step::log($stepId, 'job', 'Calling prepareJobExecution()...');
            $this->prepareJobExecution();
            Step::log($stepId, 'job', 'prepareJobExecution() completed');

            Step::log($stepId, 'job', 'Checking if in confirmation mode...');
            if ($this->isInConfirmationMode()) {
                Step::log($stepId, 'job', '✓ In confirmation mode - calling handleConfirmationMode()');
                $this->handleConfirmationMode();
                Step::log($stepId, 'job', '✓ handle() completed (confirmation mode)');
                Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

                return;
            }
            Step::log($stepId, 'job', '✗ Not in confirmation mode');

            Step::log($stepId, 'job', 'Checking if should exit early...');
            if ($this->shouldExitEarly()) {
                Step::log($stepId, 'job', '✓ Should exit early - returning');
                Step::log($stepId, 'job', '✓ handle() completed (early exit)');
                Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

                return;
            }
            Step::log($stepId, 'job', '✗ Not exiting early');

            Step::log($stepId, 'job', 'Calling executeJobLogic()...');
            $this->executeJobLogic();
            Step::log($stepId, 'job', 'executeJobLogic() completed');

            Step::log($stepId, 'job', 'Checking if needs verification...');
            if ($this->needsVerification()) {
                Step::log($stepId, 'job', '✓ Needs verification - returning');
                Step::log($stepId, 'job', '✓ handle() completed (needs verification)');
                Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

                return;
            }
            Step::log($stepId, 'job', '✗ Does not need verification');

            Step::log($stepId, 'job', 'Calling finalizeJobExecution()...');
            $this->finalizeJobExecution();
            Step::log($stepId, 'job', 'finalizeJobExecution() completed');

            Step::log($stepId, 'job', '✓ handle() completed successfully');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
        } catch (Throwable $e) {
            $errorTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('jobs')->error("[JOB ERROR] Step #{$stepId} | {$jobClass} | After {$errorTime}ms | Error: ".$e->getMessage());
            Step::log($stepId, 'job', '⚠️⚠️⚠️ EXCEPTION CAUGHT IN handle() ⚠️⚠️⚠️');
            Step::log($stepId, 'job', 'Exception details:');
            Step::log($stepId, 'job', '  - Exception class: '.get_class($e));
            Step::log($stepId, 'job', '  - Exception message: '.$e->getMessage());
            Step::log($stepId, 'job', '  - Exception file: '.$e->getFile().':'.$e->getLine());
            Step::log($stepId, 'job', '  - After: '.$errorTime.'ms');
            Step::log($stepId, 'job', 'Calling handleException()...');

            $this->handleException($e);

            Step::log($stepId, 'job', 'handleException() completed');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
        }
    }

    final public function failed(Throwable $e): void
    {
        /*
         * Last-resort handler if the Laravel queue system catches an unhandled error.
         * This is called when Horizon kills a job due to timeout or other unhandled exceptions.
         * Update step error_message, error_stack_trace, and transition to Failed state.
         */

        // Check if step property is initialized before accessing it
        if (! isset($this->step)) {
            // Job failed before step was initialized - log and exit
            Log::channel('jobs')->error('[JOB FAILED] Job failed before step initialization: '.$e->getMessage());
            Step::log(null, 'job', '⚠️⚠️⚠️ JOB FAILED - STEP NOT INITIALIZED ⚠️⚠️⚠️');
            Step::log(null, 'job', 'Exception: '.$e->getMessage());

            return;
        }

        $stepId = $this->step->id;
        Step::log($stepId, 'job', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($stepId, 'job', '║   BASE-QUEUEABLE-JOB: failed() - LARAVEL FALLBACK       ║');
        Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
        Step::log($stepId, 'job', '⚠️⚠️⚠️ LARAVEL CAUGHT UNHANDLED EXCEPTION ⚠️⚠️⚠️');
        Step::log($stepId, 'job', 'This is called when Horizon kills a job due to timeout or crash');
        Step::log($stepId, 'job', 'Exception details:');
        Step::log($stepId, 'job', '  - Exception class: '.get_class($e));
        Step::log($stepId, 'job', '  - Exception message: '.$e->getMessage());
        Step::log($stepId, 'job', '  - Exception file: '.$e->getFile().':'.$e->getLine());

        // Parse exception for friendly message and stack trace
        Step::log($stepId, 'job', 'Parsing exception with ExceptionParser...');
        $parser = \Martingalian\Core\Exceptions\ExceptionParser::with($e);

        // Update error_message, error_stack_trace, and response
        Step::log($stepId, 'job', 'Updating step with error information...');
        $this->step->update([
            'error_message' => $parser->friendlyMessage(),
            'error_stack_trace' => $parser->stackTrace(),
            'response' => ['exception' => $e->getMessage()],
        ]);
        Step::log($stepId, 'job', 'Step updated with error information');

        // Finalize duration
        Step::log($stepId, 'job', 'Calling finalizeDuration()...');
        $this->finalizeDuration();
        Step::log($stepId, 'job', 'Duration finalized');

        // Guard against transitioning from terminal states (Completed, Skipped, Cancelled, Failed, Stopped)
        $this->step->refresh();
        $currentState = get_class($this->step->state);
        if (in_array($currentState, Step::terminalStepStates(), true)) {
            Step::log($stepId, 'job', "Step already in terminal state: {$currentState} - skipping Failed transition");
            Step::log($stepId, 'job', '✓ failed() method completed (already terminal)');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }

        // Transition to Failed state
        Step::log($stepId, 'job', 'Transitioning to Failed state...');
        Step::log($stepId, 'job', 'Current state: '.$this->step->state);
        $this->step->state->transitionTo(Failed::class);
        Step::log($stepId, 'job', 'Transitioned to Failed state');
        Step::log($stepId, 'job', '✓ failed() method completed');
        Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
    }

    final public function startDuration(): void
    {
        $this->startMicrotime = microtime(true);
    }

    final public function finalizeDuration(): void
    {
        $durationMs = abs((int) ((microtime(true) - $this->startMicrotime) * 1000));

        $this->step->update(['duration' => $durationMs]);
    }

    final public function uuid(): string
    {
        return $this->step->child_block_uuid ?? Str::uuid()->toString();
    }

    /**
     * Determine if this step should be escalated to high priority.
     * Default: escalate when step has reached 50% of max retries.
     * Override in child jobs for custom priority escalation logic.
     */
    protected function shouldChangeToHighPriority(): bool
    {
        return $this->step->retries >= ($this->retries / 2);
    }

    protected function prepareJobExecution(): void
    {
        // Refresh step from database to get latest state (it should be Dispatched)
        $this->step->refresh();

        // Guard: If step is already in a terminal state (Completed, Failed, Skipped, etc.),
        // this job is a duplicate execution (race condition). Exit gracefully.
        $currentState = get_class($this->step->state);
        if (in_array($currentState, Step::terminalStepStates(), true)) {
            Step::log($this->step->id, 'job', "[prepareJobExecution] Step already in terminal state: {$currentState} - aborting duplicate execution");
            throw new NonNotifiableException("Step #{$this->step->id} already in terminal state {$currentState} - duplicate job execution detected");
        }

        $this->step->state->transitionTo(Running::class);
        $this->startDuration();
        $this->attachRelatable();

        // Initialize database exception handler for all jobs
        $this->databaseExceptionHandler = BaseDatabaseExceptionHandler::make('mysql');

        // Note: checkMaxRetries() moved to shouldExitEarly() to occur AFTER throttle check
    }

    protected function isInConfirmationMode(): bool
    {
        return $this->shouldRunConfirmingCompletionMode();
    }

    protected function handleConfirmationMode(): void
    {
        $this->confirmCompletionOrRetry();
    }

    protected function shouldExitEarly(): bool
    {
        if (! $this->shouldStartOrStop()) {
            if (config('martingalian.logging.guard_logging_enabled', true)) {
                Step::log($this->step->id, 'job', '⚠️ GUARD TRIGGERED: shouldStartOrStop() returned FALSE');
                Step::log($this->step->id, 'job', '  → Calling stopJob()...');
            }
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            if (config('martingalian.logging.guard_logging_enabled', true)) {
                Step::log($this->step->id, 'job', '⚠️ GUARD TRIGGERED: shouldStartOrFail() returned FALSE');
                Step::log($this->step->id, 'job', '  → Throwing NonNotifiableException...');
            }
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            if (config('martingalian.logging.guard_logging_enabled', true)) {
                Step::log($this->step->id, 'job', '⚠️ GUARD TRIGGERED: shouldStartOrSkip() returned FALSE');
                Step::log($this->step->id, 'job', '  → Calling skipJob()...');
            }
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            if (config('martingalian.logging.guard_logging_enabled', true)) {
                Step::log($this->step->id, 'job', '⚠️ GUARD TRIGGERED: shouldStartOrRetry() returned FALSE');
                Step::log($this->step->id, 'job', '  → Calling retryJob()...');
            }
            $this->retryJob();

            return true;
        }

        // Check max retries after business logic checks
        $this->checkMaxRetries();

        return false;
    }

    protected function executeJobLogic(): void
    {
        if ($this->step->double_check === 0) {
            $this->computeAndStoreResult();
        }
    }

    protected function needsVerification(): bool
    {
        if ($this->shouldDoubleCheck()) {
            return true;
        }

        if (! $this->shouldConfirmOrRetry()) {
            $this->retryForConfirmation();

            return true;
        }

        return false;
    }

    protected function finalizeJobExecution(): void
    {
        if ($this->shouldComplete()) {
            $this->complete();
        }

        $this->completeIfNotHandled();
    }
}
