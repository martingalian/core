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
use Martingalian\Core\Support\Martingalian;
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
        $parser = ExceptionParser::with($e);

        if (is_null($this->step->error_message)) {
            $this->step->update([
                'error_message' => $parser->friendlyMessage(),
                'error_stack_trace' => $parser->stackTrace(),
            ]);
        }

        if (! $e instanceof NonNotifiableException) {
            Martingalian::notifyAdmins(
                message: 'Step error - '.$parser->friendlyMessage(),
                title: "[S:{$this->step->id} ".class_basename(static::class).'] - Error',
                deliveryGroup: 'exceptions'
            );
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Failed::class);
    }
    // ========================================================================
    // MAIN EXCEPTION HANDLER
    // ========================================================================

    protected function handleException(Throwable $e): void
    {
        if ($this->isShortcutException($e)) {
            $this->handleShortcutException($e);

            return;
        }

        if ($this->shouldRetryException($e)) {
            $this->retryJobWithBackoff();

            return;
        }

        if ($this->shouldIgnoreException($e)) {
            $this->completeAndIgnoreException();

            return;
        }

        $this->logExceptionToStep($e);
        $this->logExceptionToRelatable($e);
        $this->resolveExceptionIfPossible($e);

        if (! $this->stepStatusUpdated) {
            $this->reportAndFail($e);
        }
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

    protected function shouldRetryException(Throwable $e): bool
    {
        if (method_exists($this, 'retryException') && $this->retryException($e)) {
            return true;
        }

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
        if (method_exists($this, 'ignoreException') && $this->ignoreException($e)) {
            return true;
        }

        if (isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'ignoreException')
            && $this->exceptionHandler->ignoreException($e)
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

    protected function retryJobWithBackoff(): void
    {
        $this->step->update([
            'dispatch_after' => now()->addSeconds($this->jobBackoffSeconds),
        ]);

        $this->retryJob();
    }

    protected function completeAndIgnoreException(): void
    {
        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
        $this->stepStatusUpdated = true;
    }

    // ========================================================================
    // EXCEPTION LOGGING
    // ========================================================================

    protected function logExceptionToStep(Throwable $e): void
    {
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
        if (! $this->step->relatable) {
            return;
        }

        if (! method_exists($this->step->relatable, 'logApplicationEvent')) {
            return;
        }

        $this->step->relatable->logApplicationEvent(
            "[{$this->step->id}] Step failed. Error: ".ExceptionParser::with($e)->friendlyMessage(),
            self::class,
            __FUNCTION__
        );
    }
}
