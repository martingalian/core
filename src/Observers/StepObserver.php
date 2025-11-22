<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\NotRunnable;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Skipped;

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

        // Intelligent group assignment:
        // 1) If no group is set, try to inherit from parent step (where parent.child_block_uuid = this.block_uuid)
        // 2) If no parent, try to inherit from sibling steps with same block_uuid
        // 3) If still no group, assign via round-robin
        if (empty($step->group)) {
            if (! empty($step->block_uuid)) {
                // First, check if there's a parent step that spawned this child block
                $parentStep = Step::query()
                    ->where('child_block_uuid', $step->block_uuid)
                    ->whereNotNull('group')
                    ->first();

                if ($parentStep) {
                    $step->group = $parentStep->group;
                }

                // If no parent, look for siblings in the same block
                if (empty($step->group)) {
                    $siblingStep = Step::query()
                        ->where('block_uuid', $step->block_uuid)
                        ->whereNotNull('group')
                        ->first();

                    if ($siblingStep) {
                        $step->group = $siblingStep->group;
                    }
                }
            }

            // If still no group (no parent/sibling found or first step in chain), assign via round-robin
            if (empty($step->group)) {
                $step->group = Step::getDispatchGroup();
            }
        }
    }

    public function saving(Step $step): void
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

        // Ensure group is never null on updates
        if (empty($step->group)) {
            if (! empty($step->block_uuid)) {
                // First, check if there's a parent step that spawned this child block
                $parentStep = Step::query()
                    ->where('child_block_uuid', $step->block_uuid)
                    ->whereNotNull('group')
                    ->first();

                if ($parentStep) {
                    $step->group = $parentStep->group;
                }

                // If no parent, look for siblings in the same block
                if (empty($step->group)) {
                    $siblingStep = Step::query()
                        ->where('block_uuid', $step->block_uuid)
                        ->whereNotNull('group')
                        ->first();

                    if ($siblingStep) {
                        $step->group = $siblingStep->group;
                    }
                }
            }

            // If still no group (no parent/sibling found or first step in chain), assign via round-robin
            if (empty($step->group)) {
                $step->group = Step::getDispatchGroup();
            }
        }
    }

    public function created(Step $step): void
    {
        // info_if("[StepObserver.created] Step created with id={$step->id}, block_uuid={$step->block_uuid}, state={$step->state}, index={$step->index}, class={$step->class}, queue={$step->queue}, arguments=".json_encode($step->arguments).", child_block_uuid={$step->child_block_uuid}, created_at={$step->created_at}, updated_at={$step->updated_at}");
    }

    public function updated(Step $step): void
    {
        // Circuit breaker: When ANY step fails, skip ALL other non-completed steps
        // This allows calm analysis of failures without StepDispatcher continuing to process
        if ($step->isDirty('state') && $step->state instanceof Failed) {
            log_step($step->id, '🚨🚨🚨 CIRCUIT BREAKER TRIGGERED 🚨🚨🚨');
            log_step($step->id, "Step #{$step->id} transitioned to FAILED state");
            log_step($step->id, 'Skipping ALL non-completed steps to halt processing...');

            // Get all steps that are NOT in concluded states (Completed, Failed, Stopped, Skipped)
            $concludedStates = [
                \Martingalian\Core\States\Completed::class,
                Failed::class,
                \Martingalian\Core\States\Stopped::class,
                Skipped::class,
            ];

            $nonCompletedStepIds = Step::query()
                ->whereNotIn('state', $concludedStates)
                ->where('id', '!=', $step->id) // Don't update the step that just failed
                ->pluck('id')
                ->all();

            if (! empty($nonCompletedStepIds)) {
                log_step($step->id, 'Found '.count($nonCompletedStepIds).' non-completed steps to skip');
                log_step($step->id, 'Step IDs to skip: ['.implode(', ', $nonCompletedStepIds).']');

                // Bulk update all non-completed steps to Skipped state
                DB::table('steps')
                    ->whereIn('id', $nonCompletedStepIds)
                    ->update([
                        'state' => Skipped::class,
                        'updated_at' => now(),
                    ]);

                log_step($step->id, '✓ Successfully skipped '.count($nonCompletedStepIds).' steps');
                log_step($step->id, '═══════════════════════════════════════════════════════════');
                log_step($step->id, '→→→ CIRCUIT BREAKER: ALL PROCESSING HALTED ←←←');
                log_step($step->id, '═══════════════════════════════════════════════════════════');
            } else {
                log_step($step->id, 'No non-completed steps found - nothing to skip');
            }
        }
    }
}
