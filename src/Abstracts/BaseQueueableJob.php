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

        log_step($stepId, '╔═══════════════════════════════════════════════════════════╗');
        log_step($stepId, '║       BASE-QUEUEABLE-JOB: handle() START                 ║');
        log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
        log_step($stepId, 'Job class: '.$jobClass);
        log_step($stepId, 'Step ID: '.$stepId);

        try {
            log_step($stepId, 'Calling prepareJobExecution()...');
            if (! $this->prepareJobExecution()) {
                log_step($stepId, '✓ handle() completed (duplicate execution guard)');
                log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');

                return;
            }
            log_step($stepId, 'prepareJobExecution() completed');

            log_step($stepId, 'Checking if in confirmation mode...');
            if ($this->isInConfirmationMode()) {
                log_step($stepId, '✓ In confirmation mode - calling handleConfirmationMode()');
                $this->handleConfirmationMode();
                log_step($stepId, '✓ handle() completed (confirmation mode)');
                log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
                return;
            }
            log_step($stepId, '✗ Not in confirmation mode');

            log_step($stepId, 'Checking if should exit early...');
            if ($this->shouldExitEarly()) {
                log_step($stepId, '✓ Should exit early - returning');
                log_step($stepId, '✓ handle() completed (early exit)');
                log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
                return;
            }
            log_step($stepId, '✗ Not exiting early');

            log_step($stepId, 'Calling executeJobLogic()...');
            $this->executeJobLogic();
            log_step($stepId, 'executeJobLogic() completed');

            log_step($stepId, 'Checking if needs verification...');
            if ($this->needsVerification()) {
                log_step($stepId, '✓ Needs verification - returning');
                log_step($stepId, '✓ handle() completed (needs verification)');
                log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
                return;
            }
            log_step($stepId, '✗ Does not need verification');

            log_step($stepId, 'Calling finalizeJobExecution()...');
            $this->finalizeJobExecution();
            log_step($stepId, 'finalizeJobExecution() completed');

            log_step($stepId, '✓ handle() completed successfully');
            log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
        } catch (Throwable $e) {
            $errorTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('jobs')->error("[JOB ERROR] Step #{$stepId} | {$jobClass} | After {$errorTime}ms | Error: ".$e->getMessage());
            log_step($stepId, '⚠️⚠️⚠️ EXCEPTION CAUGHT IN handle() ⚠️⚠️⚠️');
            log_step($stepId, 'Exception details:');
            log_step($stepId, '  - Exception class: '.get_class($e));
            log_step($stepId, '  - Exception message: '.$e->getMessage());
            log_step($stepId, '  - Exception file: '.$e->getFile().':'.$e->getLine());
            log_step($stepId, '  - After: '.$errorTime.'ms');
            log_step($stepId, 'Calling handleException()...');

            $this->handleException($e);

            log_step($stepId, 'handleException() completed');
            log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
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
            log_step('unknown', '⚠️⚠️⚠️ JOB FAILED - STEP NOT INITIALIZED ⚠️⚠️⚠️');
            log_step('unknown', 'Exception: '.$e->getMessage());

            return;
        }

        $stepId = $this->step->id;
        log_step($stepId, '╔═══════════════════════════════════════════════════════════╗');
        log_step($stepId, '║   BASE-QUEUEABLE-JOB: failed() - LARAVEL FALLBACK       ║');
        log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
        log_step($stepId, '⚠️⚠️⚠️ LARAVEL CAUGHT UNHANDLED EXCEPTION ⚠️⚠️⚠️');
        log_step($stepId, 'This is called when Horizon kills a job due to timeout or crash');
        log_step($stepId, 'Exception details:');
        log_step($stepId, '  - Exception class: '.get_class($e));
        log_step($stepId, '  - Exception message: '.$e->getMessage());
        log_step($stepId, '  - Exception file: '.$e->getFile().':'.$e->getLine());

        // Parse exception for friendly message and stack trace
        log_step($stepId, 'Parsing exception with ExceptionParser...');
        $parser = \Martingalian\Core\Exceptions\ExceptionParser::with($e);

        // Update error_message, error_stack_trace, and response
        log_step($stepId, 'Updating step with error information...');
        $this->step->update([
            'error_message' => $parser->friendlyMessage(),
            'error_stack_trace' => $parser->stackTrace(),
            'response' => ['exception' => $e->getMessage()],
        ]);
        log_step($stepId, 'Step updated with error information');

        // Finalize duration
        log_step($stepId, 'Calling finalizeDuration()...');
        $this->finalizeDuration();
        log_step($stepId, 'Duration finalized');

        // Transition to Failed state (only if not already in a terminal state)
        log_step($stepId, 'Transitioning to Failed state...');
        log_step($stepId, 'Current state: '.$this->step->state);
        if (! $this->step->state instanceof Failed) {
            $this->step->state->transitionTo(Failed::class);
            log_step($stepId, 'Transitioned to Failed state');
        } else {
            log_step($stepId, 'Step already in Failed state - skipping transition');
        }
        log_step($stepId, '✓ failed() method completed');
        log_step($stepId, '╚═══════════════════════════════════════════════════════════╝');
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

    protected function prepareJobExecution(): bool
    {
        // Refresh step from database to get latest state (it should be Dispatched)
        $this->step->refresh();

        // Guard against duplicate execution - if step is already Running,
        // this is a retry from Horizon after a timeout/crash.
        // Log warning and exit gracefully - the step may have been stuck or is being
        // processed by another worker. Let it fail naturally after exhausting retries.
        if ($this->step->state instanceof Running) {
            log_step($this->step->id, '⚠️ Step already in Running state - this is a duplicate execution from Horizon retry');
            log_step($this->step->id, '⚠️ Exiting gracefully to avoid duplicate work');

            return false;
        }

        $this->step->state->transitionTo(Running::class);
        $this->startDuration();
        $this->attachRelatable();

        // Initialize database exception handler for all jobs
        $this->databaseExceptionHandler = BaseDatabaseExceptionHandler::make('mysql');

        // Note: checkMaxRetries() moved to shouldExitEarly() to occur AFTER throttle check
        return true;
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
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
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
