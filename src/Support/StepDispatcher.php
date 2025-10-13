<?php

namespace Martingalian\Core\Support;

use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\StepsDispatcher;
use Martingalian\Core\States\Cancelled;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Failed;
use Martingalian\Core\States\NotRunnable;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;
use Martingalian\Core\States\Skipped;
use Martingalian\Core\States\Stopped;
use Martingalian\Core\Transitions\PendingToDispatched;

class StepDispatcher
{
    use DispatchesJobs;

    public static function dispatch(): void
    {
        // Acquire the DB lock authoritatively; bail if already running.
        if (! StepsDispatcher::startDispatch()) {
            return;
        }

        $progress = 0;

        try {
            info_if('-= TICK STARTED =-');

            // Marks as skipped all children steps on a skipped step.
            if (static::skipAllChildStepsOnParentAndChildSingleStep()) {
                info_if('-= TICK ENDED (skipAllChildStepsOnParentAndChildSingleStep = true) =-');

                return;
            }

            $progress = 1;

            // Perform cascading cancellation for failed steps and return early if needed
            if (static::cascadeCancelledSteps()) {
                info_if('-= TICK ENDED (cascadeCancelledSteps = true) =-');

                return;
            }

            $progress = 2;

            if (static::promoteResolveExceptionSteps()) {
                info_if('-= TICK ENDED (promoteResolveExceptionSteps = true) =-');

                return;
            }

            $progress = 3;

            // Check if we need to transition parent steps to Failed first, but only if no cancellations occurred
            if (static::transitionParentsToFailed()) {
                info_if('-= TICK ENDED (transitionParentsToFailed = true) =-');

                return;
            }

            $progress = 4;

            if (static::cascadeFailureToChildren()) {
                info_if('-= TICK ENDED (cascadeFailureToChildren = true) =-');

                return;
            }

            $progress = 5;

            // Check if we need to transition parent steps to Completed
            if (static::transitionParentsToComplete()) {
                info_if('-= TICK ENDED (transitionParentsToComplete = true) =-');

                return;
            }

            $progress = 6;

            // Distribute the steps to be dispatched (will only occur if no cancellations or failures happened)
            $dispatchedSteps = collect();

            Step::pending()
                ->where(function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<', now());
                })
                ->get()
                ->each(static function (Step $step) use ($dispatchedSteps) {
                    info_if("[StepDispatcher.dispatch] Evaluating Step ID {$step->id} with index {$step->index} in block {$step->block_uuid}");
                    /*
                    $step->logApplicationEvent(
                        "Evaluating Step ID {$step->id} with index {$step->index} in block {$step->block_uuid}",
                        self::class,
                        __FUNCTION__
                    );
                    */

                    $transition = new PendingToDispatched($step);

                    if ($transition->canTransition()) {
                        info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} CAN transition to DISPATCHED");
                        /*
                        $step->logApplicationEvent(
                            "Step ID {$step->id} CAN transition to DISPATCHED",
                            self::class,
                            __FUNCTION__
                        );
                        */

                        $transition->apply();
                        $dispatchedSteps->push($step->fresh());
                    } else {
                        info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} cannot transition to DISPATCHED");
                        /*
                        $step->logApplicationEvent(
                            "Step ID {$step->id} CANNOT transition.",
                            self::class,
                            __FUNCTION__
                        );
                        */
                    }
                });

            // Dispatch all steps that are ready
            $dispatchedSteps->each(fn ($step) => (new static)->dispatchSingleStep($step));

            info_if('Total steps dispatched: '.$dispatchedSteps->count());
            info_if('-= TICK ENDED (full cycle) =-');

            $progress = 7;
        } finally {
            StepsDispatcher::endDispatch($progress);
        }
    }

    public static function transitionParentsToComplete(): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->whereNotNull('child_block_uuid')
            ->orderBy('index')
            ->orderBy('id')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = static::collectAllNestedChildBlocks($runningParents);

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->get()
            ->groupBy('block_uuid');

        $changed = false;

        foreach ($runningParents as $step) {
            if ($step->childStepsAreConcludedFromMap($childStepsByBlock)) {
                info_if("[StepDispatcher.transitionParentsToComplete] Parent Step ID {$step->id} transition to Completed.");
                /*
                $step->logApplicationEvent(
                    "Parent Step ID {$step->id} transition to Completed.",
                    self::class,
                    __FUNCTION__
                );
                */

                $step->state->transitionTo(Completed::class);
                $changed = true;
            }
        }

        return $changed;
    }

    public static function skipAllChildStepsOnParentAndChildSingleStep(): bool
    {
        $skippedParents = Step::where('state', Skipped::class)
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($skippedParents->isEmpty()) {
            return false;
        }

        $allChildBlocks = static::collectAllNestedChildBlocks($skippedParents);

        if (empty($allChildBlocks)) {
            return true;
        }

        $descendants = Step::whereIn('block_uuid', $allChildBlocks)->get();

        foreach ($descendants as $child) {
            info_if("[StepDispatcher.skipAllChildStepsOnParentAndChildSingleStep] - Transitioning Step ID {$child->id} from {$child->state->value()} to SKIPPED");

            /*
            $child->logApplicationEvent(
                "Transitioning Step from {$child->state->value()} to SKIPPED",
                self::class,
                __FUNCTION__
            );
            */

            $child->state->transitionTo(Skipped::class);
        }

        return true;
    }

    public static function transitionParentsToFailed(): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            $failedChildSteps = $childSteps->filter(
                fn ($step) => in_array(get_class($step->state), Step::failedStepStates())
            );

            if ($failedChildSteps->isNotEmpty()) {
                info_if("[StepDispatcher.transitionParentsToFailed] Parent Step ID {$parentStep->id} transitioning to Failed due to child failure.");

                /*
                $parentStep->logApplicationEvent(
                    "Parent Step ID {$parentStep->id} transitioning to FAILED due to child failure.",
                    self::class,
                    __FUNCTION__
                );
                */

                $parentStep->state->transitionTo(Failed::class);

                return true;
            }
        }

        return false;
    }

    public static function cascadeCancelledSteps(): bool
    {
        $cancellationsOccurred = false;

        // Find all failed/stopped steps that should trigger downstream cancellation.
        $failedSteps = Step::whereIn('state', Step::failedStepStates())
            ->whereNotNull('index')
            ->get();

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->where('index', '>', $failedStep->index)
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->where('type', 'default')
                ->get();

            info_if("[StepDispatcher.cascadeCancelledSteps] Total Steps to cancel: {$stepsToCancel->count()}");

            foreach ($stepsToCancel as $step) {
                // Double guard: never attempt to transition terminal states.
                if (in_array(get_class($step->state), Step::terminalStepStates(), true)) {
                    continue;
                }

                info_if("[StepDispatcher.cascadeCancelledSteps] Cancelling Step ID {$step->id} in block {$blockUuid} due to failure of Step ID {$failedStep->id}");

                /*
                $step->logApplicationEvent(
                    "Cancelling Step ID {$step->id} in block {$blockUuid} due to failure of Step ID {$failedStep->id}",
                    self::class,
                    __FUNCTION__
                );
                */

                $step->state->transitionTo(Cancelled::class);
                $cancellationsOccurred = true;

                // If this is a parent step, cancel all steps in its child block.
                if ($step->isParent()) {
                    static::cancelChildBlockSteps($step);
                }
            }
        }

        return $cancellationsOccurred;
    }

    public static function cancelChildBlockSteps(Step $parentStep): void
    {
        $childBlockUuid = $parentStep->child_block_uuid;

        $childSteps = Step::where('block_uuid', $childBlockUuid)->get();

        foreach ($childSteps as $childStep) {
            if ($childStep->state instanceof Pending) {
                info_if("[StepDispatcher.cancelChildBlockSteps] Cancelling Step ID {$childStep->id} in child block {$childBlockUuid} due to parent failure.");

                /*
                $childStep->logApplicationEvent(
                    "Cancelling Step ID {$childStep->id} in child block {$childBlockUuid} due to parent failure.",
                    self::class,
                    __FUNCTION__
                );
                */

                $childStep->state->transitionTo(Cancelled::class);
            }
        }
    }

    public static function promoteResolveExceptionSteps(): bool
    {
        $candidateBlocks = Step::where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        if ($candidateBlocks->isEmpty()) {
            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->where('type', '<>', 'resolve-exception')
            ->whereIn('state', Step::failedStepStates())
            ->pluck('block_uuid')
            ->unique()
            ->values();

        if ($failingBlocks->isEmpty()) {
            return false;
        }

        $block = $failingBlocks->first();

        $steps = Step::where('block_uuid', $block)
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->get();

        info_if("[StepDispatcher.promoteResolveExceptionSteps] Promoting {$steps->count()} resolve-exception steps in block {$block}");

        foreach ($steps as $step) {
            /*
            $step->logApplicationEvent(
                "Promoting STEP ID {$step->id} from NOT-RUNNABLE to PENDING",
                self::class,
                __FUNCTION__
            );
            */

            $step->state->transitionTo(Pending::class);
        }

        return true;
    }

    public static function cascadeFailureToChildren(): bool
    {
        $failedParents = Step::whereIn('state', [Failed::class, Stopped::class])
            ->whereNotNull('child_block_uuid')
            ->get();

        foreach ($failedParents as $parent) {
            $childBlock = $parent->child_block_uuid;

            $childSteps = Step::where('block_uuid', $childBlock)->get();

            $nonTerminalChildren = $childSteps->filter(
                fn ($step) => ! in_array(get_class($step->state), Step::terminalStepStates())
            );

            if ($nonTerminalChildren->isEmpty()) {
                continue;
            }

            foreach ($nonTerminalChildren as $childStep) {
                $childStep->state->transitionTo(Failed::class);

                /*
                $childStep->logApplicationEvent(
                    "Step ID {$childStep->id} failed due to parent Step ID {$parent->id} failure.",
                    self::class,
                    __FUNCTION__
                );
                */
            }

            return true; // end tick after failing one block
        }

        return false;
    }

    protected static function collectAllNestedChildBlocks($parents): array
    {
        $all = collect();
        $queue = $parents->pluck('child_block_uuid')->filter()->unique()->values();

        while ($queue->isNotEmpty()) {
            $next = $queue->shift();

            if ($all->contains($next)) {
                continue;
            }

            $all->push($next);

            $children = Step::where('block_uuid', $next)
                ->whereNotNull('child_block_uuid')
                ->pluck('child_block_uuid');

            $queue = $queue->merge($children->filter()->unique());
        }

        return $all->unique()->values()->all();
    }
}
