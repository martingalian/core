<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Martingalian\Core\Support\StepDispatcher;

/**
 * Processes one tick for a specific group.
 * Dispatched by the coordinator (DispatchStepsCommand) for parallel group processing.
 *
 * No retries - if this job fails, the next coordinator tick will fire again.
 */
final class ProcessGroupTickJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     * Set to 1 because the coordinator will dispatch again on next tick.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    public function __construct(
        public string $group
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        StepDispatcher::dispatch($this->group);
    }
}
