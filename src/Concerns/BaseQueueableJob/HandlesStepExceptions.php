<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Exceptions\JustEndException;
use Martingalian\Core\Exceptions\JustResolveException;
use Martingalian\Core\Exceptions\MaxRetriesReachedException;
use Martingalian\Core\Exceptions\NonNotifiableException;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Failed;
use Martingalian\Core\Support\NotificationService;
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
        $this->step->state->transitionTo(Failed::class);
    }
    // ========================================================================
    // MAIN EXCEPTION HANDLER
    // ========================================================================

    protected function handleException(Throwable $e): void
    {
        $stepId = $this->step->id ?? 'unknown';
        log_step($stepId, "╔═══════════════════════════════════════════════════════════╗");
        log_step($stepId, "║         EXCEPTION CAUGHT - STARTING HANDLING              ║");
        log_step($stepId, "╚═══════════════════════════════════════════════════════════╝");
        log_step($stepId, "EXCEPTION DETAILS:");
        log_step($stepId, "  - Exception class: ".get_class($e));
        log_step($stepId, "  - Exception message: ".$e->getMessage());
        log_step($stepId, "  - Exception code: ".$e->getCode());
        log_step($stepId, "  - Exception file: ".$e->getFile().":".$e->getLine());
        log_step($stepId, "  - Job class: ".class_basename($this));
        log_step($stepId, "  - Current step state: ".(string) $this->step->state);
        log_step($stepId, "  - Current step retries: ".$this->step->retries);

        log_step($stepId, "DECISION TREE - CHECKING EXCEPTION TYPE:");
        log_step($stepId, "  [1/5] Checking if isShortcutException()...");
        if ($this->isShortcutException($e)) {
            log_step($stepId, "  ✓ YES - This is a SHORTCUT exception (MaxRetries/JustResolve/JustEnd)");
            log_step($stepId, "  → Calling handleShortcutException()");
            $this->handleShortcutException($e);
            log_step($stepId, "  → handleShortcutException() completed");
            log_step($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return;
        }
        log_step($stepId, "  ✗ NO - Not a shortcut exception");

        // Check for permanent database errors (syntax, schema issues) - fail immediately
        log_step($stepId, "  [2/5] Checking if isPermanentDatabaseError()...");
        if ($this->isPermanentDatabaseError($e)) {
            log_step($stepId, "  ✓ YES - This is a PERMANENT database error");
            log_step($stepId, "  → Calling reportAndFail() - WILL FAIL IMMEDIATELY");
            $this->reportAndFail($e);
            log_step($stepId, "  → reportAndFail() completed");
            log_step($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return;
        }
        log_step($stepId, "  ✗ NO - Not a permanent database error");

        log_step($stepId, "  [3/5] Checking if shouldRetryException()...");
        if ($this->shouldRetryException($e)) {
            log_step($stepId, "  ✓ YES - Exception should be RETRIED");
            log_step($stepId, "  → Calling retryJobWithBackoff()");
            $this->retryJobWithBackoff($e);
            log_step($stepId, "  → retryJobWithBackoff() completed");
            log_step($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return;
        }
        log_step($stepId, "  ✗ NO - Exception should not be retried");

        log_step($stepId, "  [4/5] Checking if shouldIgnoreException()...");
        if ($this->shouldIgnoreException($e)) {
            log_step($stepId, "  ✓ YES - Exception should be IGNORED (will complete successfully)");
            log_step($stepId, "  → Calling completeAndIgnoreException()");
            $this->completeAndIgnoreException();
            log_step($stepId, "  → completeAndIgnoreException() completed");
            log_step($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return;
        }
        log_step($stepId, "  ✗ NO - Exception should not be ignored");

        log_step($stepId, "  [5/5] DEFAULT PATH - Log exception and attempt resolution");
        log_step($stepId, "  → Calling logExceptionToStep()");
        $this->logExceptionToStep($e);
        log_step($stepId, "  → logExceptionToStep() completed");

        log_step($stepId, "  → Calling logExceptionToRelatable()");
        $this->logExceptionToRelatable($e);
        log_step($stepId, "  → logExceptionToRelatable() completed");

        // Notifications are sent by ApiRequestLogObserver after log is persisted
        log_step($stepId, "  → Calling resolveExceptionIfPossible()");
        $this->resolveExceptionIfPossible($e);
        log_step($stepId, "  → resolveExceptionIfPossible() completed");

        log_step($stepId, "CHECKING stepStatusUpdated flag:");
        log_step($stepId, "  - stepStatusUpdated = ".($this->stepStatusUpdated ? 'true' : 'false'));
        if (! $this->stepStatusUpdated) {
            log_step($stepId, "  ⚠️ Step status NOT updated by resolver - calling reportAndFail()");
            $this->reportAndFail($e);
            log_step($stepId, "  → reportAndFail() completed");
        } else {
            log_step($stepId, "  ✓ Step status was updated by resolver - NOT calling reportAndFail()");
        }
        log_step($stepId, "╚═══════════════════════════════════════════════════════════╝");
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

        // Create ApplicationLog entry on the relatable model
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
