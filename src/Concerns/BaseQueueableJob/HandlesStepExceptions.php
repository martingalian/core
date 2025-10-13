<?php

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Exceptions\JustEndException;
use Martingalian\Core\Exceptions\JustResolveException;
use Martingalian\Core\Exceptions\MaxRetriesReachedException;
use Martingalian\Core\Exceptions\NonNotifiableException;
use Martingalian\Core\Models\User;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Failed;
use Throwable;

/*
 * Trait HandlesStepExceptions
 *
 * Purpose:
 * - Centralizes exception handling logic for Step-based jobs.
 * - Supports retrying, ignoring, or escalating exceptions.
 * - Applies step status transitions depending on exception outcome.
 *
 * Key behaviors:
 * - Recognizes and handles shortcut exceptions (JustEnd, JustResolve, MaxRetries).
 * - Retries job if allowed by job or handler logic (e.g. throttling cases).
 * - Marks step as completed or failed depending on exception type and result.
 * - Delegates resolution logic to job or external handler if available.
 */
trait HandlesStepExceptions
{
    protected function handleException(Throwable $e): void
    {
        if ($this->handleKnownShortcuts($e)) {
            return;
        }

        if ($this->shouldRetryException($e)) {
            /*
             * Set future dispatch delay and retry the job
             * using BaseQueueableJob logic.
             */
            $this->step->update([
                'dispatch_after' => now()->addSeconds($this->jobBackoffSeconds),
            ]);

            $this->retryJob();

            return;
        }

        if ($this->shouldIgnoreException($e)) {
            /*
             * Exception deemed ignorable,
             * transition step to completed.
             */
            $this->finalizeDuration();
            $this->step->state->transitionTo(Completed::class);
            $this->stepStatusUpdated = true;

            return;
        }

        /**
         * If we have a relatable relationship, lets try to call the applicationLogs
         * on the child eloquent model and register the error.
         */
        if ($this->step->relatable) {
            if (method_exists($this->step->relatable, 'logApplicationEvent')) {
                $this->step->relatable->logApplicationEvent(
                    "[{$this->step->id}] Step failed. Error message: ".ExceptionParser::with($e)->friendlyMessage(),
                    self::class,
                    __FUNCTION__
                );
            }
        }

        // Lets record already on the step the error message / stack trace.
        if (is_null($this->step->error_message)) {
            $this->step->updateSaving(['error_message' => ExceptionParser::with($e)->friendlyMessage()]);
        }

        if (is_null($this->step->error_stack_trace)) {
            $this->step->updateSaving(['error_stack_trace' => ExceptionParser::with($e)->stackTrace()]);
        }

        /*
         * Attempt to resolve the exception using
         * job or handler logic if provided.
         */

        $this->resolveExceptionIfPossible($e);

        if (! $this->stepStatusUpdated) {
            $this->reportAndFail($e);
        }
    }

    protected function handleKnownShortcuts(Throwable $e): bool
    {
        /*
         * Handles known shortcut exceptions that should skip
         * standard retry or failure logic, such as:
         * - MaxRetriesReachedException
         * - JustResolveException
         * - JustEndException
         */
        if ($e instanceof MaxRetriesReachedException
            || $e instanceof JustResolveException
            || $e instanceof JustEndException
        ) {
            $this->resolveExceptionIfPossible($e);

            if (! $this->stepStatusUpdated) {
                $this->reportAndFail($e);
            }

            return true;
        }

        return false;
    }

    protected function shouldRetryException(\Throwable $e): bool
    {
        /*
         * Determines if the current exception qualifies
         * for a retry via job or handler logic.
         */
        $handlerHasMethod = isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'retryException');

        return (
            method_exists($this, 'retryException')
            && $this->retryException($e)
        ) || (
            $handlerHasMethod
            && $this->exceptionHandler->retryException($e)
        );
    }

    protected function shouldIgnoreException(\Throwable $e): bool
    {
        /*
         * Determines if the exception should be ignored
         * and the step transitioned to completed.
         */
        $handlerHasMethod = isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'ignoreException');

        return (
            method_exists($this, 'ignoreException')
            && $this->ignoreException($e)
        ) || (
            $handlerHasMethod
            && $this->exceptionHandler->ignoreException($e)
        );
    }

    protected function resolveExceptionIfPossible(Throwable $e): void
    {
        /*
         * Attempts to resolve an exception by calling
         * resolveException() on job and/or handler.
         */
        $handlerHasMethod = isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'resolveException');

        if (method_exists($this, 'resolveException')) {
            $this->resolveException($e);
        }

        if ($handlerHasMethod) {
            $this->exceptionHandler->resolveException($e);
        }
    }

    public function reportAndFail(\Throwable $e)
    {
        $parser = ExceptionParser::with($e);

        /**
         * Lets print the error message in the step (if still empty), and also
         * add a new line on the application logs on the relatable model (if exists).
         */
        if (is_null($this->step->error_message)) {
            $this->step->update([
                'error_message' => $parser->friendlyMessage(),
                'error_stack_trace' => $parser->stackTrace(),
            ]);
        }

        if (! $e instanceof NonNotifiableException) {
            User::notifyAdminsViaPushover(
                'Step error - '.$parser->friendlyMessage(),
                "[S:{$this->step->id} ".class_basename(static::class).'] - Error',
                'nidavellir_errors'
            );
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Failed::class);
    }
}
