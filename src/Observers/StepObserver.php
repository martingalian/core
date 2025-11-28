<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\StepsDispatcher;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\NotRunnable;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;

final class StepObserver
{
    public function creating(Step $step): void
    {
        // Automatically route high priority steps to the priority queue
        if ($step->priority === 'high') {
            $step->queue = 'priority';
        }

        // Queue validation: fallback to 'default' if queue is not valid or empty
        // Valid queues: 'default', 'priority', and hostname-based queue (lowercase)
        $validQueues = ['default', 'priority', mb_strtolower(gethostname())];
        if (empty($step->queue) || ! in_array($step->queue, $validQueues, true)) {
            $step->queue = 'default';
        }

        if (empty($step->block_uuid)) {
            $step->block_uuid = Str::uuid()->toString();
        }

        if (empty($step->state) && $step->type === 'default') {
            $step->state = new Pending($step);
        }

        if ($step->type === 'resolve-exception') {
            $step->state = new NotRunnable($step);
        }

        // Default index to 1 if not provided (null) or set to 0
        // This allows parallel execution: all steps with index=1 can run simultaneously
        if ($step->index === null || $step->index === 0) {
            $step->index = 1;
        }

        // Group assignment for workflow-level parallelism:
        // 1) If parent exists (where parent.child_block_uuid = my block_uuid) → inherit parent's group
        // 2) No parent → I'm a root step → select group via round-robin from steps_dispatcher
        // This ensures all steps in a workflow share the same group for coherent processing
        if (empty($step->group)) {
            // Only look for parent if block_uuid is set (otherwise NULL = NULL matches everything)
            $parentStep = null;
            if (! empty($step->block_uuid)) {
                $parentStep = Step::query()
                    ->where('child_block_uuid', $step->block_uuid)
                    ->whereNotNull('group')
                    ->first();
            }

            if ($parentStep) {
                // I'm a child → inherit parent's group
                $step->group = $parentStep->group;
            } else {
                // I'm a root step → select group via round-robin from steps_dispatcher
                $step->group = StepsDispatcher::getNextGroup();
            }
        }
    }

    public function saving(Step $step): void
    {
        // Clear hostname when step transitions to Pending state (e.g., throttled jobs, retries)
        // This ensures the step can be picked up by any worker server, not tied to a specific host
        if ($step->state instanceof Pending || get_class($step->state) === Pending::class) {
            $step->hostname = null;
        }

        // Automatically route high priority steps to the priority queue
        if ($step->priority === 'high') {
            $step->queue = 'priority';
        }

        // Queue validation: fallback to 'default' if queue is not valid or empty
        // Valid queues: 'default', 'priority', and hostname-based queue (lowercase)
        $validQueues = ['default', 'priority', mb_strtolower(gethostname())];
        if (empty($step->queue) || ! in_array($step->queue, $validQueues, true)) {
            $step->queue = 'default';
        }

        // Set started_at when transitioning TO Running state (if not already set)
        // This covers transitions like PendingToRunning that don't set started_at
        // Only applies to updates (transitions), not initial creates
        $isNowRunning = $step->state instanceof Running || get_class($step->state) === Running::class;

        // Fix: getOriginal('state') returns a State object (or null for new models), not a string class name
        // Must use instanceof or get_class() for proper comparison
        $originalState = $step->getOriginal('state');
        $wasRunningBefore = $originalState instanceof Running
            || (is_object($originalState) && get_class($originalState) === Running::class);

        // Only apply transition logic if this is an UPDATE (step already exists in DB)
        // Check $step->exists to ensure we're not in a create() call
        $isTransition = $step->exists && $originalState !== null;

        if ($isTransition && $isNowRunning && ! $wasRunningBefore && $step->started_at === null) {
            $step->started_at = now();
        }

        // Also set hostname when transitioning TO Running if not already set
        // Defense in depth: ensures hostname is always set when job starts
        if ($isTransition && $isNowRunning && ! $wasRunningBefore && $step->hostname === null) {
            $step->hostname = gethostname();
        }

        // Clear is_throttled when transitioning TO Running
        // Defense in depth: ensures throttle flag is cleared when job actually starts
        if ($isTransition && $isNowRunning && ! $wasRunningBefore) {
            $step->is_throttled = false;
        }

        // Clear is_throttled when step transitions to Completed state
        // This ensures throttled steps that complete have their flag cleared
        $isNowCompleted = $step->state instanceof Completed || get_class($step->state) === Completed::class;

        if ($isNowCompleted) {
            $step->is_throttled = false;
        }

        // Ensure group is never null on updates (same logic as creating)
        if (empty($step->group)) {
            // Only look for parent if block_uuid is set (otherwise NULL = NULL matches everything)
            $parentStep = null;
            if (! empty($step->block_uuid)) {
                $parentStep = Step::query()
                    ->where('child_block_uuid', $step->block_uuid)
                    ->whereNotNull('group')
                    ->first();
            }

            if ($parentStep) {
                $step->group = $parentStep->group;
            } else {
                // Root step → select group via round-robin from steps_dispatcher
                $step->group = StepsDispatcher::getNextGroup();
            }
        }
    }

    public function created(Step $step): void
    {
        // info_if("[StepObserver.created] Step created with id={$step->id}, block_uuid={$step->block_uuid}, state={$step->state}, index={$step->index}, class={$step->class}, queue={$step->queue}, arguments=".json_encode($step->arguments).", child_block_uuid={$step->child_block_uuid}, created_at={$step->created_at}, updated_at={$step->updated_at}");
    }

    public function updated(Step $step): void
    {
        // Observer hook - add custom logic here if needed
    }
}
