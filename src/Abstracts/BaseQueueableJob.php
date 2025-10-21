<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Support\Str;
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

    public int $startMicrotime = 0;

    public ?BaseExceptionHandler $exceptionHandler;

    // Must be implemented by subclasses to define the compute logic.
    abstract protected function compute();

    final public function handle(): void
    {
        try {
            $this->prepareJobExecution();

            if ($this->isInConfirmationMode()) {
                $this->handleConfirmationMode();

                return;
            }

            if ($this->shouldExitEarly()) {
                return;
            }

            $this->executeJobLogic();

            if ($this->needsVerification()) {
                return;
            }

            $this->finalizeJobExecution();
        } catch (Throwable $e) {
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
