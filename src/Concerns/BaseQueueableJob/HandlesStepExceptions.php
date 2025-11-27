<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Exceptions\JustEndException;
use Martingalian\Core\Exceptions\JustResolveException;
use Martingalian\Core\Exceptions\MaxRetriesReachedException;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Failed;
use Throwable;

/**
 * Trait HandlesStepExceptions
 *
 * Centralizes exception handling for Step-based jobs.
 * Supports retrying, ignoring, or resolving exceptions based on
 * custom job logic or delegated exception handlers.
 */
trait HandlesStepExceptions
{
    public function reportAndFail(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $parser = ExceptionParser::with($e);

        if (is_null($this->step->error_message)) {
            $this->step->update([
                'error_message' => $parser->friendlyMessage(),
                'error_stack_trace' => $parser->stackTrace(),
            ]);
        }

        $this->finalizeDuration();

        // Guard against transitioning from terminal states (Completed, Skipped, Cancelled, Failed, Stopped)
        // Only Pending, Dispatched, and Running can transition to Failed
        $this->step->refresh();
        $currentState = get_class($this->step->state);
        if (in_array($currentState, Step::terminalStepStates(), true)) {
            return;
        }

        $this->step->state->transitionTo(Failed::class);
    }
    // ========================================================================
    // MAIN EXCEPTION HANDLER
    // ========================================================================

    protected function handleException(Throwable $e): void
    {
        $stepId = $this->step->id ?? 'unknown';
        Step::log($stepId, 'job', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($stepId, 'job', '║         EXCEPTION CAUGHT - STARTING HANDLING              ║');
        Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
        Step::log($stepId, 'job', 'EXCEPTION DETAILS:');
        Step::log($stepId, 'job', '  - Exception class: '.get_class($e));
        Step::log($stepId, 'job', '  - Exception message: '.$e->getMessage());
        Step::log($stepId, 'job', '  - Exception code: '.$e->getCode());
        Step::log($stepId, 'job', '  - Exception file: '.$e->getFile().':'.$e->getLine());
        Step::log($stepId, 'job', '  - Job class: '.class_basename($this));
        Step::log($stepId, 'job', '  - Current step state: '.(string) $this->step->state);
        Step::log($stepId, 'job', '  - Current step retries: '.$this->step->retries);

        Step::log($stepId, 'job', 'DECISION TREE - CHECKING EXCEPTION TYPE:');
        Step::log($stepId, 'job', '  [1/5] Checking if isShortcutException()...');
        if ($this->isShortcutException($e)) {
            Step::log($stepId, 'job', '  ✓ YES - This is a SHORTCUT exception (MaxRetries/JustResolve/JustEnd)');
            Step::log($stepId, 'job', '  → Calling handleShortcutException()');
            $this->handleShortcutException($e);
            Step::log($stepId, 'job', '  → handleShortcutException() completed');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }
        Step::log($stepId, 'job', '  ✗ NO - Not a shortcut exception');

        // Check for permanent database errors (syntax, schema issues) - fail immediately
        Step::log($stepId, 'job', '  [2/5] Checking if isPermanentDatabaseError()...');
        if ($this->isPermanentDatabaseError($e)) {
            Step::log($stepId, 'job', '  ✓ YES - This is a PERMANENT database error');
            Step::log($stepId, 'job', '  → Calling reportAndFail() - WILL FAIL IMMEDIATELY');
            $this->reportAndFail($e);
            Step::log($stepId, 'job', '  → reportAndFail() completed');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }
        Step::log($stepId, 'job', '  ✗ NO - Not a permanent database error');

        Step::log($stepId, 'job', '  [3/5] Checking if shouldRetryException()...');
        if ($this->shouldRetryException($e)) {
            Step::log($stepId, 'job', '  ✓ YES - Exception should be RETRIED');
            Step::log($stepId, 'job', '  → Calling retryJobWithBackoff()');
            $this->retryJobWithBackoff($e);
            Step::log($stepId, 'job', '  → retryJobWithBackoff() completed');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }
        Step::log($stepId, 'job', '  ✗ NO - Exception should not be retried');

        Step::log($stepId, 'job', '  [4/5] Checking if shouldIgnoreException()...');
        if ($this->shouldIgnoreException($e)) {
            Step::log($stepId, 'job', '  ✓ YES - Exception should be IGNORED (will complete successfully)');
            Step::log($stepId, 'job', '  → Calling completeAndIgnoreException()');
            $this->completeAndIgnoreException();
            Step::log($stepId, 'job', '  → completeAndIgnoreException() completed');
            Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');

            return;
        }
        Step::log($stepId, 'job', '  ✗ NO - Exception should not be ignored');

        Step::log($stepId, 'job', '  [5/5] DEFAULT PATH - Log exception and attempt resolution');
        Step::log($stepId, 'job', '  → Calling logExceptionToStep()');
        $this->logExceptionToStep($e);
        Step::log($stepId, 'job', '  → logExceptionToStep() completed');

        Step::log($stepId, 'job', '  → Calling logExceptionToRelatable()');
        $this->logExceptionToRelatable($e);
        Step::log($stepId, 'job', '  → logExceptionToRelatable() completed');

        // Notifications are sent by ApiRequestLogObserver after log is persisted
        Step::log($stepId, 'job', '  → Calling resolveExceptionIfPossible()');
        $this->resolveExceptionIfPossible($e);
        Step::log($stepId, 'job', '  → resolveExceptionIfPossible() completed');

        Step::log($stepId, 'job', 'CHECKING stepStatusUpdated flag:');
        Step::log($stepId, 'job', '  - stepStatusUpdated = '.($this->stepStatusUpdated ? 'true' : 'false'));
        if (! $this->stepStatusUpdated) {
            Step::log($stepId, 'job', '  ⚠️ Step status NOT updated by resolver - calling reportAndFail()');
            $this->reportAndFail($e);
            Step::log($stepId, 'job', '  → reportAndFail() completed');
        } else {
            Step::log($stepId, 'job', '  ✓ Step status was updated by resolver - NOT calling reportAndFail()');
        }
        Step::log($stepId, 'job', '╚═══════════════════════════════════════════════════════════╝');
    }

    // ========================================================================
    // EXCEPTION CLASSIFICATION
    // ========================================================================

    protected function isShortcutException(Throwable $e): bool
    {
        return $e instanceof MaxRetriesReachedException
            || $e instanceof JustResolveException
            || $e instanceof JustEndException;
    }

    protected function isPermanentDatabaseError(Throwable $e): bool
    {
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->isPermanentError($e)
        ) {
            return true;
        }

        return false;
    }

    protected function shouldRetryException(Throwable $e): bool
    {
        // PRIORITY 1: Database handler (transient DB errors - deadlocks, connection failures)
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldRetry($e)
        ) {
            return true;
        }

        // PRIORITY 2: Job-specific retry logic
        if (method_exists($this, 'retryException') && $this->retryException($e)) {
            return true;
        }

        // PRIORITY 3: API exception handler (rate limits, server errors)
        if (isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'retryException')
            && $this->exceptionHandler->retryException($e)
        ) {
            return true;
        }

        return false;
    }

    protected function shouldIgnoreException(Throwable $e): bool
    {
        // PRIORITY 1: Job-specific ignore logic
        if (method_exists($this, 'ignoreException') && $this->ignoreException($e)) {
            return true;
        }

        // PRIORITY 2: API exception handler
        if (isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'ignoreException')
            && $this->exceptionHandler->ignoreException($e)
        ) {
            return true;
        }

        // PRIORITY 3: Database handler (very rare - idempotent duplicate entries)
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldIgnore($e)
        ) {
            return true;
        }

        return false;
    }

    // ========================================================================
    // EXCEPTION RESOLUTION
    // ========================================================================

    protected function handleShortcutException(Throwable $e): void
    {
        $this->resolveExceptionIfPossible($e);

        if (! $this->stepStatusUpdated) {
            $this->reportAndFail($e);
        }
    }

    protected function resolveExceptionIfPossible(Throwable $e): void
    {
        if (method_exists($this, 'resolveException')) {
            $this->resolveException($e);
        }

        if (isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'resolveException')
        ) {
            $this->exceptionHandler->resolveException($e);
        }
    }

    // ========================================================================
    // EXCEPTION ACTIONS
    // ========================================================================

    protected function retryJobWithBackoff(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $backoffSeconds = $this->jobBackoffSeconds;

        // Use exponential backoff for database exceptions
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldRetry($e)
        ) {
            $backoffSeconds = $this->databaseExceptionHandler->getBackoffSeconds($this->step->retries);
        }

        $this->step->update([
            'dispatch_after' => now()->addSeconds($backoffSeconds),
        ]);

        $this->retryJob();
    }

    protected function completeAndIgnoreException(): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
        $this->stepStatusUpdated = true;
    }

    // ========================================================================
    // EXCEPTION LOGGING
    // ========================================================================

    protected function logExceptionToStep(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $parser = ExceptionParser::with($e);

        if (is_null($this->step->error_message)) {
            $this->step->updateSaving([
                'error_message' => $parser->friendlyMessage(),
            ]);
        }

        if (is_null($this->step->error_stack_trace)) {
            $this->step->updateSaving([
                'error_stack_trace' => $parser->stackTrace(),
            ]);
        }
    }

    protected function logExceptionToRelatable(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        // Get the relatable model from the step
        $relatable = $this->step->relatable;

        // Only log if relatable exists and has the appLog method
        if (! $relatable || ! method_exists($relatable, 'appLog')) {
            return;
        }

        $parser = ExceptionParser::with($e);

        // Create ModelLog entry on the relatable model
        $relatable->appLog(
            eventType: 'step_failed',
            metadata: [
                'exception_class' => get_class($e),
                'exception_message' => $parser->friendlyMessage(),
            ],
            relatable: $this->step,
            message: $parser->friendlyMessage()
        );
    }
}
