<?php

namespace Martingalian\Core\Abstracts;

use Martingalian\Core\Concerns\BaseQueueableJob\FormatsStepResult;
use Martingalian\Core\Concerns\BaseQueueableJob\HandlesStepExceptions;
use Martingalian\Core\Concerns\BaseQueueableJob\HandlesStepLifecycle;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\Running;
use Illuminate\Support\Str;
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

    public int $startMicrotime = 0;

    public ?BaseExceptionHandler $exceptionHandler;

    public function handle()
    {
        try {
            $this->step->state->transitionTo(Running::class);

            $this->startDuration();

            $this->attachRelatable();

            $this->checkMaxRetries();

            /**
             * Are we in confirmation mode? The confirmation mode uses the
             * retries to retry the job again.
             */
            if ($this->shouldRunConfirmingCompletionMode()) {
                $this->confirmCompletionOrRetry();

                return;
            }

            if (! $this->shouldStartOrStop()) {
                $this->stopJob();

                return;
            }

            if (! $this->shouldStartOrFail()) {
                throw new \NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
            }

            if (! $this->shouldStartOrSkip()) {
                $this->skipJob();

                return;
            }

            if (! $this->shouldStartOrRetry()) {
                $this->retryJob();

                return;
            }

            // Never computed before double check or there is no double check?
            if ($this->step->double_check == 0) {
                $this->computeAndStoreResult();
            }

            if ($this->shouldDoubleCheck()) {
                // Job will enter confirming mode in the next run.
                return;
            }

            if (! $this->shouldConfirmOrRetry()) {
                /*
                 * If confirmation failed, reschedule for re-confirmation.
                 */
                $this->retryForConfirmation();

                return;
            }

            /**
             * Last job method that will run($this->complete()) in case
             * all the other methods were successfully. It's the last
             * method and doesn't return antyhing.
             */
            if ($this->shouldComplete()) {
                $this->complete();
            }

            /*
             * Final step completion if all previous phases are satisfied.
             */
            $this->completeIfNotHandled();
        } catch (Throwable $e) {
            /*
             * Capture and log any unexpected exception.
             */
            $this->handleException($e);
        }
    }

    public function failed(Throwable $e): void
    {
        /*
         * Last-resort handler if the Laravel queue system catches an unhandled error.
         */
        $this->step->update(['response' => ['exception' => $e->getMessage()]]);
        $this->step->state->transitionTo(Failed::class);
    }

    public function startDuration()
    {
        // Record microtime for calculation
        $this->startMicrotime = microtime(true);
    }

    public function finalizeDuration()
    {
        // Calculate duration in milliseconds
        $duration = abs(intval((microtime(true) - $this->startMicrotime) * 1000));

        // Update the database with the calculated duration
        $this->step->update(['duration' => $duration]);
    }

    /**
     * Helper method that will generate a new uuid, or, in case this step
     * has a child_block_uuid, then it will use that one (to generate a
     * children lifecycle).
     */
    public function uuid()
    {
        return $this->step->child_block_uuid ?? Str::uuid()->toString();
    }

    // Must be implemented by subclasses to define the compute logic.
    abstract protected function compute();
}
