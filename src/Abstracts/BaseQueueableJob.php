<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Support\Str;
use Log;
use Martingalian\Core\Concerns\BaseQueueableJob\FormatsStepResult;
use Martingalian\Core\Concerns\BaseQueueableJob\HandlesStepExceptions;
use Martingalian\Core\Concerns\BaseQueueableJob\HandlesStepLifecycle;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\Running;
use NonNotifiableException;
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

    public ?BaseExceptionHandler $exceptionHandler;

    // Must be implemented by subclasses to define the compute logic.
    abstract protected function compute();

    final public function handle(): void
    {
        $startTime = microtime(true);
        $stepId = $this->step->id ?? 'unknown';
        $jobClass = class_basename($this);

        Log::channel('jobs')->info("[JOB START] Step #{$stepId} | {$jobClass} | Starting...");

        try {
            $prepareStart = microtime(true);
            $this->prepareJobExecution();
            $prepareTime = round((microtime(true) - $prepareStart) * 1000, 2);
            Log::channel('jobs')->info("[JOB] Step #{$stepId} | {$jobClass} | prepareJobExecution: {$prepareTime}ms");

            if ($this->isInConfirmationMode()) {
                Log::channel('jobs')->info("[JOB] Step #{$stepId} | {$jobClass} | In confirmation mode, handling...");
                $this->handleConfirmationMode();
                Log::channel('jobs')->info("[JOB END] Step #{$stepId} | {$jobClass} | Completed (confirmation mode)");

                return;
            }

            if ($this->shouldExitEarly()) {
                Log::channel('jobs')->info("[JOB END] Step #{$stepId} | {$jobClass} | Exited early");

                return;
            }

            $executeStart = microtime(true);
            $this->executeJobLogic();
            $executeTime = round((microtime(true) - $executeStart) * 1000, 2);
            Log::channel('jobs')->info("[JOB] Step #{$stepId} | {$jobClass} | executeJobLogic: {$executeTime}ms");

            if ($this->needsVerification()) {
                Log::channel('jobs')->info("[JOB END] Step #{$stepId} | {$jobClass} | Needs verification, returning");

                return;
            }

            $finalizeStart = microtime(true);
            $this->finalizeJobExecution();
            $finalizeTime = round((microtime(true) - $finalizeStart) * 1000, 2);
            Log::channel('jobs')->info("[JOB] Step #{$stepId} | {$jobClass} | finalizeJobExecution: {$finalizeTime}ms");

            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('jobs')->info("[JOB END] Step #{$stepId} | {$jobClass} | TOTAL: {$totalTime}ms");
        } catch (Throwable $e) {
            $errorTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('jobs')->error("[JOB ERROR] Step #{$stepId} | {$jobClass} | After {$errorTime}ms | Error: ".$e->getMessage());
            $this->handleException($e);
        }
    }

    final public function failed(Throwable $e): void
    {
        /*
         * Last-resort handler if the Laravel queue system catches an unhandled error.
         */
        $this->step->update(['response' => ['exception' => $e->getMessage()]]);
        $this->step->state->transitionTo(Failed::class);
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

    protected function prepareJobExecution(): void
    {
        // Refresh step from database to get latest state (it should be Dispatched)
        $this->step->refresh();

        $this->step->state->transitionTo(Running::class);
        $this->startDuration();
        $this->attachRelatable();
        $this->checkMaxRetries();
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

        if (! $this->shouldStartOrThrottle()) {
            $this->retryJob();

            return true;
        }

        return false;
    }

    /**
     * Hook for automatic API throttling.
     * Override in BaseApiableJob for automatic rate limiting.
     * Default: no throttling (non-API jobs).
     */
    protected function shouldStartOrThrottle(): bool
    {
        return true;
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
