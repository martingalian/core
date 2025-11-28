<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Illuminate\Support\Facades\DB;
use Martingalian\Core\Models\Martingalian;
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

final class StepDispatcher
{
    use DispatchesJobs;

    /**
     * Run a single "tick" of the dispatcher, optionally constrained to a group.
     *
     * @param  string|null  $group  If provided, ALL Step selections are filtered by this group.
     */
    public static function dispatch(?string $group = null): void
    {
        Step::log(null, 'dispatcher', '╔══════════════════════════════════════════════════════════════╗');
        Step::log(null, 'dispatcher', '║              DISPATCHER TICK START                           ║');
        Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
        Step::log(null, 'dispatcher', 'Group: '.($group ?? 'null (all groups)'));

        // Acquire the DB lock authoritatively; bail if already running.
        if (! StepsDispatcher::startDispatch($group)) {
            Step::log(null, 'dispatcher', '⚠️ [TICK SKIPPED] Already running');

            return;
        }

        $progress = 0;
        Step::log(null, 'dispatcher', 'Progress: 0 - Lock acquired, starting dispatcher cycle');

        try {
            // Marks as skipped all children steps on a skipped step.
            Step::log(null, 'dispatcher', '→ Calling skipAllChildStepsOnParentAndChildSingleStep()');
            $result = self::skipAllChildStepsOnParentAndChildSingleStep($group);
            Step::log(null, 'dispatcher', '← skipAllChildStepsOnParentAndChildSingleStep() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (skipAllChildStepsOnParentAndChildSingleStep = true) =-');
                Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                Step::log(null, 'dispatcher', '  TICK ENDED EARLY at progress 0 (skip children)');

                return;
            }

            $progress = 1;
            Step::log(null, 'dispatcher', 'Progress: 1 - Skip children check complete');

            // Perform cascading cancellation for failed steps and return early if needed
            Step::log(null, 'dispatcher', '→ Calling cascadeCancelledSteps()');
            $result = self::cascadeCancelledSteps($group);
            Step::log(null, 'dispatcher', '← cascadeCancelledSteps() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (cascadeCancelledSteps = true) =-');
                Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                Step::log(null, 'dispatcher', '  TICK ENDED EARLY at progress 1 (cascade cancelled)');

                return;
            }

            $progress = 2;
            Step::log(null, 'dispatcher', 'Progress: 2 - Cascade cancelled check complete');

            Step::log(null, 'dispatcher', '→ Calling promoteResolveExceptionSteps()');
            $result = self::promoteResolveExceptionSteps($group);
            Step::log(null, 'dispatcher', '← promoteResolveExceptionSteps() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (promoteResolveExceptionSteps = true) =-');
                Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                Step::log(null, 'dispatcher', '  TICK ENDED EARLY at progress 2 (promote resolve-exception)');

                return;
            }

            $progress = 3;
            Step::log(null, 'dispatcher', 'Progress: 3 - Promote resolve-exception check complete');

            // Check if we need to transition parent steps to Failed first, but only if no cancellations occurred
            Step::log(null, 'dispatcher', '→ Calling transitionParentsToFailed()');
            $result = self::transitionParentsToFailed($group);
            Step::log(null, 'dispatcher', '← transitionParentsToFailed() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToFailed = true) =-');
                Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                Step::log(null, 'dispatcher', '  TICK ENDED EARLY at progress 3 (transition parents to failed)');

                return;
            }

            $progress = 4;
            Step::log(null, 'dispatcher', 'Progress: 4 - Transition parents to failed check complete');

            Step::log(null, 'dispatcher', '→ Calling cascadeFailureToChildren()');
            $result = self::cascadeFailureToChildren($group);
            Step::log(null, 'dispatcher', '← cascadeFailureToChildren() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (cascadeFailureToChildren = true) =-');
                Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                Step::log(null, 'dispatcher', '  TICK ENDED EARLY at progress 4 (cascade failure to children)');

                return;
            }

            $progress = 5;
            Step::log(null, 'dispatcher', 'Progress: 5 - Cascade failure to children check complete');

            // Check if we need to transition parent steps to Completed
            Step::log(null, 'dispatcher', '→ Calling transitionParentsToComplete()');
            $result = self::transitionParentsToComplete($group);
            Step::log(null, 'dispatcher', '← transitionParentsToComplete() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToComplete = true) =-');
                Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                Step::log(null, 'dispatcher', '  TICK ENDED EARLY at progress 5 (transition parents to complete)');

                return;
            }

            $progress = 6;
            Step::log(null, 'dispatcher', 'Progress: 6 - Transition parents to complete check complete');

            // Distribute the steps to be dispatched (only if no cancellations or failures happened)
            Step::log(null, 'dispatcher', '→ Starting pending step evaluation and dispatch');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            Step::log(null, 'dispatcher', '→→→ PENDING STEP SELECTION DIAGNOSTICS ←←←');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            $dispatchedSteps = collect();

            // Check cooling down status
            $isCoolingDown = Martingalian::first()?->is_cooling_down ?? false;
            Step::log(null, 'dispatcher', '❄️ Cooling down status: '.($isCoolingDown ? 'YES (only non-coolable steps)' : 'NO (all steps)'));

            $pendingQuery = Step::pending()
                ->when($group !== null, static fn ($q) => $q->where('group', $group), static fn ($q) => $q->whereNull('group'))
                ->where(function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<=', now());
                })
                ->when($isCoolingDown, static fn ($q) => $q->where('can_cool_down', false));

            Step::log(null, 'dispatcher', '🔍 DIAGNOSTIC: Step::pending() scope ONLY selects state = Pending::class');
            Step::log(null, 'dispatcher', '🔍 DIAGNOSTIC: Query filters: group = '.($group ?? 'NULL').', dispatch_after <= '.now().($isCoolingDown ? ', can_cool_down = false' : ''));

            $pendingSteps = $pendingQuery->get();

            // Defense-in-depth: Ensure no duplicate step IDs in collection
            $beforeCount = $pendingSteps->count();
            $pendingSteps = $pendingSteps->unique('id')->values();
            $afterCount = $pendingSteps->count();

            if ($beforeCount !== $afterCount) {
                $duplicateCount = $beforeCount - $afterCount;
                Step::log(null, 'dispatcher', '⚠️ DUPLICATE STEPS DETECTED: Removed '.$duplicateCount.' duplicate(s) from pending collection');
                Step::log(null, 'dispatcher', '   Before deduplication: '.$beforeCount.' steps');
                Step::log(null, 'dispatcher', '   After deduplication: '.$afterCount.' steps');
            }

            Step::log(null, 'dispatcher', 'Found '.$pendingSteps->count().' PENDING steps ready for evaluation');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

            // Priority Queue System: If any high-priority steps exist, filter to only those
            if ($pendingSteps->contains('priority', 'high')) {
                $totalSteps = $pendingSteps->count();
                $pendingSteps = $pendingSteps->where('priority', 'high')->values();
                info_if('[StepDispatcher] High-priority steps detected - filtering to '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total pending steps)');
                Step::log(null, 'dispatcher', '⬆️ HIGH-PRIORITY FILTERING: '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total)');
            }

            $canTransitionCount = 0;
            $cannotTransitionCount = 0;

            $pendingSteps->each(static function (Step $step) use ($dispatchedSteps, &$canTransitionCount, &$cannotTransitionCount) {
                info_if("[StepDispatcher.dispatch] Evaluating Step ID {$step->id} with index {$step->index} in block {$step->block_uuid}");
                Step::log($step->id, 'job', '╔═══════════════════════════════════════════════════════════╗');
                Step::log($step->id, 'job', '║   DISPATCHER: EVALUATING FOR DISPATCH                     ║');
                Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');
                Step::log($step->id, 'job', 'Step details:');
                Step::log($step->id, 'job', '  - Step ID: '.$step->id);
                Step::log($step->id, 'job', '  - Class: '.$step->class);
                Step::log($step->id, 'job', '  - Index: '.$step->index);
                Step::log($step->id, 'job', '  - Block UUID: '.$step->block_uuid);
                Step::log($step->id, 'job', '  - Priority: '.$step->priority);
                Step::log($step->id, 'job', '  - State: '.$step->state);
                Step::log($step->id, 'job', '  - Retries: '.$step->retries);

                Step::log($step->id, 'job', 'Creating PendingToDispatched transition...');
                $transition = new PendingToDispatched($step);
                Step::log($step->id, 'job', 'Calling canTransition()...');

                if ($transition->canTransition()) {
                    info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} CAN transition to DISPATCHED");
                    Step::log($step->id, 'job', '✓ canTransition() returned TRUE');
                    Step::log($step->id, 'job', 'Calling transition->apply()...');
                    $transition->apply();
                    Step::log($step->id, 'job', 'transition->apply() completed');
                    $dispatchedSteps->push($step->fresh());
                    $canTransitionCount++;
                    Step::log($step->id, 'job', '→ Step WILL BE DISPATCHED (added to dispatchedSteps collection)');
                } else {
                    info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} cannot transition to DISPATCHED");
                    Step::log($step->id, 'job', '✗ canTransition() returned FALSE');
                    Step::log($step->id, 'job', '→ Step SKIPPED (not ready for dispatch)');
                    $cannotTransitionCount++;
                }
                Step::log($step->id, 'job', '╚═══════════════════════════════════════════════════════════╝');
            });

            Step::log(null, 'dispatcher', 'Pending step evaluation complete:');
            Step::log(null, 'dispatcher', '  - Can transition: '.$canTransitionCount);
            Step::log(null, 'dispatcher', '  - Cannot transition: '.$cannotTransitionCount);
            Step::log(null, 'dispatcher', '  - Total evaluated: '.($canTransitionCount + $cannotTransitionCount));

            // Dispatch all steps that are ready
            Step::log(null, 'dispatcher', 'Dispatching '.$dispatchedSteps->count().' steps to their jobs...');
            $dispatchedSteps->each(function ($step) {
                Step::log($step->id, 'job', 'DISPATCHER: Calling dispatchSingleStep() to dispatch job to queue');
                (new self)->dispatchSingleStep($step);
                Step::log($step->id, 'job', 'DISPATCHER: dispatchSingleStep() completed - job queued');
            });

            info_if('Total steps dispatched: '.$dispatchedSteps->count().($group ? " [group={$group}]" : ''));
            info_if('-= TICK ENDED (full cycle) =-');
            Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
            Step::log(null, 'dispatcher', '  TICK ENDED NORMALLY at progress 6 (full cycle complete)');
            Step::log(null, 'dispatcher', '  Total steps dispatched: '.$dispatchedSteps->count());

            $progress = 7;
        } finally {
            StepsDispatcher::endDispatch($progress, $group);
        }
    }

    /**
     * Transition running parents to Completed if all their (nested) children concluded.
     */
    public static function transitionParentsToComplete(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->orderBy('index')
            ->orderBy('id')
            ->get();

        Step::log(null, 'dispatcher', "[StepDispatcher.transitionParentsToComplete] Found " . $runningParents->count() . " running parents");

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = self::collectAllNestedChildBlocks($runningParents, $group);
        Step::log(null, 'dispatcher', "[StepDispatcher.transitionParentsToComplete] Collected " . count($childBlockUuids) . " nested child block UUIDs");

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        Step::log(null, 'dispatcher', "[StepDispatcher.transitionParentsToComplete] Loaded child steps for " . $childStepsByBlock->count() . " blocks");

        $changed = false;

        foreach ($runningParents as $step) {
            Step::log($step->id, 'job', "[StepDispatcher.transitionParentsToComplete] Checking Parent Step #{$step->id} | CLASS: {$step->class} | child_block_uuid: {$step->child_block_uuid}");

            try {
                $areConcluded = $step->childStepsAreConcludedFromMap($childStepsByBlock);
                Step::log($step->id, 'job', "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | childStepsAreConcludedFromMap returned: " . ($areConcluded ? 'TRUE' : 'FALSE'));

                if ($areConcluded) {
                    Step::log($step->id, 'job', "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: TRANSITION TO COMPLETED");
                    $step->state->transitionTo(Completed::class);
                    $changed = true;
                    Step::log($step->id, 'job', "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | SUCCESS - Transitioned to Completed");
                } else {
                    Step::log($step->id, 'job', "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: SKIP - Children not concluded yet");
                }
            } catch (\Exception $e) {
                Step::log($step->id, 'job', "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | EXCEPTION during transition: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine());
            }
        }

        return $changed;
    }

    /**
     * If a parent was skipped, mark all its descendants as skipped.
     */
    public static function skipAllChildStepsOnParentAndChildSingleStep(?string $group = null): bool
    {
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ skipAllChildStepsOnParentAndChildSingleStep START ←←←');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        $skippedParents = Step::where('state', Skipped::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        Step::log(null, 'dispatcher', 'Found '.$skippedParents->count().' skipped parent steps with children');

        if ($skippedParents->isEmpty()) {
            Step::log(null, 'dispatcher', 'No skipped parents found - returning false');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        foreach ($skippedParents as $parent) {
            Step::log($parent->id, 'job', 'SKIPPED PARENT: Step #'.$parent->id.' | child_block_uuid: '.$parent->child_block_uuid);
        }

        $allChildBlocks = self::collectAllNestedChildBlocks($skippedParents, $group);
        Step::log(null, 'dispatcher', 'Collected '.count($allChildBlocks).' nested child block UUIDs');

        if (empty($allChildBlocks)) {
            Step::log(null, 'dispatcher', 'No child blocks found - returning true (early completion)');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            return true;
        }

        $descendantIds = Step::whereIn('block_uuid', $allChildBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->pluck('id')
            ->all();

        Step::log(null, 'dispatcher', 'Found '.count($descendantIds).' descendant steps to skip');

        if (! empty($descendantIds)) {
            info_if('[StepDispatcher.skipAllChildStepsOnParentAndChildSingleStep] - Batch transitioning '.count($descendantIds).' steps to SKIPPED');
            Step::log(null, 'dispatcher', 'Calling batchTransitionSteps() to skip '.count($descendantIds).' steps');

            foreach ($descendantIds as $stepId) {
                Step::log($stepId, 'job', '⚠️ BATCH SKIPPED: Step #'.$stepId.' is being skipped (parent was skipped)');
            }

            self::batchTransitionSteps($descendantIds, Skipped::class);
            Step::log(null, 'dispatcher', 'batchTransitionSteps() completed - all descendants now SKIPPED');
        }

        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ skipAllChildStepsOnParentAndChildSingleStep END ←←←');
        Step::log(null, 'dispatcher', 'Returning true (children were skipped)');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        return true;
    }

    /**
     * Transition running parents to Failed if any child in their block failed.
     *
     * IMPORTANT: Waits for child block's resolve-exception steps to complete before
     * transitioning the parent to Failed. This allows error handlers to run first.
     */
    public static function transitionParentsToFailed(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Checking Parent Step #{$parentStep->id} | CLASS: {$parentStep->class} | child_block_uuid: {$parentStep->child_block_uuid} | Children count: " . $childSteps->count());

            if ($childSteps->isEmpty()) {
                Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | WARNING: No children found for child_block_uuid");
                continue;
            }

            $allChildStates = $childSteps->map(fn($s) => "ID:{$s->id}=" . class_basename($s->state))->join(', ');
            Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | All child states: [{$allChildStates}]");

            $failedChildSteps = $childSteps->filter(
                fn ($step) => in_array(get_class($step->state), Step::failedStepStates())
            );

            if ($failedChildSteps->isNotEmpty()) {
                $failedIds = $failedChildSteps->pluck('id')->join(', ');
                $failedStates = $failedChildSteps->map(fn($s) => class_basename($s->state))->join(', ');
                Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | Failed children detected: [{$failedIds}] | States: [{$failedStates}]");

                // Check if there are any non-terminal resolve-exception steps in the child block.
                // If so, wait for them to complete before failing the parent.
                $nonTerminalResolveExceptions = $childSteps->filter(
                    fn ($step) => $step->type === 'resolve-exception'
                        && ! in_array(get_class($step->state), Step::terminalStepStates(), true)
                );

                if ($nonTerminalResolveExceptions->isNotEmpty()) {
                    $resolveIds = $nonTerminalResolveExceptions->pluck('id')->join(', ');
                    $resolveStates = $nonTerminalResolveExceptions->map(fn($s) => "ID:{$s->id}=" . class_basename($s->state))->join(', ');
                    Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: WAIT - Child block has non-terminal resolve-exception steps: [{$resolveStates}]");
                    Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | Waiting for resolve-exceptions [{$resolveIds}] to complete before failing parent");
                    continue;
                }

                Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: TRANSITION TO FAILED | No pending resolve-exceptions in child block");
                $parentStep->state->transitionTo(Failed::class);

                return true;
            }

            Step::log($parentStep->id, 'job', "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: SKIP - No failed children");
        }

        return false;
    }

    /**
     * Cancel downstream runnable steps after a failure/stop.
     */
    public static function cascadeCancelledSteps(?string $group = null): bool
    {
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ cascadeCancelledSteps START ←←←');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        $cancellationsOccurred = false;

        // Find all failed/stopped steps that should trigger downstream cancellation.
        $failedSteps = Step::whereIn('state', Step::failedStepStates())
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('index')
            ->get();

        Step::log(null, 'dispatcher', 'Found '.$failedSteps->count().' failed/stopped steps that may trigger cancellations');

        if ($failedSteps->isEmpty()) {
            Step::log(null, 'dispatcher', 'No failed steps found - returning false');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;
            Step::log($failedStep->id, 'job', 'CASCADE SOURCE: Step #'.$failedStep->id.' | index: '.$failedStep->index.' | block: '.$blockUuid.' | state: '.$failedStep->state);
            Step::log(null, 'dispatcher', 'Checking for steps to cancel after Step #'.$failedStep->id.' (index: '.$failedStep->index.')');

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->where('index', '>', $failedStep->index)
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->where('type', 'default')
                ->get();

            Step::log(null, 'dispatcher', 'Found '.$stepsToCancel->count().' steps with higher index in block '.$blockUuid);

            if ($stepsToCancel->isEmpty()) {
                Step::log(null, 'dispatcher', 'No steps to cancel for failed Step #'.$failedStep->id);
                continue;
            }

            info_if("[StepDispatcher.cascadeCancelledSteps] Total Steps to cancel: {$stepsToCancel->count()}");

            $stepIdsToCancel = [];
            $parentSteps = [];

            foreach ($stepsToCancel as $step) {
                Step::log($step->id, 'job', 'CANCELLATION CANDIDATE: Step #'.$step->id.' | index: '.$step->index.' | state: '.$step->state.' | isParent: '.($step->isParent() ? 'yes' : 'no'));

                // Double guard: never attempt to transition terminal states.
                if (in_array(get_class($step->state), Step::terminalStepStates(), true)) {
                    Step::log($step->id, 'job', '⚠️ SKIP: Step #'.$step->id.' is in terminal state - will not cancel');
                    continue;
                }

                $stepIdsToCancel[] = $step->id;
                Step::log($step->id, 'job', '✓ WILL CANCEL: Step #'.$step->id.' added to cancellation batch');

                // Track parent steps to cancel their children
                if ($step->isParent()) {
                    $parentSteps[] = $step;
                    Step::log($step->id, 'job', '→ Parent step - child blocks will also be cancelled');
                }
            }

            if (! empty($stepIdsToCancel)) {
                info_if('[StepDispatcher.cascadeCancelledSteps] Batch cancelling '.count($stepIdsToCancel)." steps in block {$blockUuid}");
                Step::log(null, 'dispatcher', 'Batch cancelling '.count($stepIdsToCancel).' steps in block '.$blockUuid);

                foreach ($stepIdsToCancel as $stepId) {
                    Step::log($stepId, 'job', '⚠️ BATCH CANCELLED: Step #'.$stepId.' is being cancelled (downstream of failure)');
                }

                self::batchTransitionSteps($stepIdsToCancel, Cancelled::class);
                Step::log(null, 'dispatcher', 'batchTransitionSteps() completed - steps now CANCELLED');
                $cancellationsOccurred = true;

                // Cancel child blocks
                Step::log(null, 'dispatcher', 'Checking '.count($parentSteps).' parent steps for child block cancellation');
                foreach ($parentSteps as $parentStep) {
                    Step::log($parentStep->id, 'job', 'Calling cancelChildBlockSteps() for parent Step #'.$parentStep->id);
                    self::cancelChildBlockSteps($parentStep, $group);
                    Step::log($parentStep->id, 'job', 'cancelChildBlockSteps() completed for parent Step #'.$parentStep->id);
                }
            }
        }

        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ cascadeCancelledSteps END ←←←');
        Step::log(null, 'dispatcher', 'Returning: '.($cancellationsOccurred ? 'true (cancellations occurred)' : 'false (no cancellations)'));
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        return $cancellationsOccurred;
    }

    /**
     * Cancel all pending children in a child block.
     */
    public static function cancelChildBlockSteps(Step $parentStep, ?string $group = null): void
    {
        $childBlockUuid = $parentStep->child_block_uuid;

        $childStepIds = Step::where('block_uuid', $childBlockUuid)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', Pending::class)
            ->pluck('id')
            ->all();

        if (! empty($childStepIds)) {
            info_if('[StepDispatcher.cancelChildBlockSteps] Batch cancelling '.count($childStepIds)." steps in child block {$childBlockUuid}");
            self::batchTransitionSteps($childStepIds, Cancelled::class);
        }
    }

    /**
     * Promote resolve-exception steps in blocks that have failures.
     */
    public static function promoteResolveExceptionSteps(?string $group = null): bool
    {
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ promoteResolveExceptionSteps START ←←←');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        $candidateBlocks = Step::where('type', 'resolve-exception')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        Step::log(null, 'dispatcher', 'Found '.count($candidateBlocks).' blocks with resolve-exception steps in NotRunnable state');

        if ($candidateBlocks->isEmpty()) {
            Step::log(null, 'dispatcher', 'No candidate blocks found - returning false');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', '<>', 'resolve-exception')
            ->whereIn('state', Step::failedStepStates())
            ->pluck('block_uuid')
            ->unique()
            ->values();

        Step::log(null, 'dispatcher', 'Found '.count($failingBlocks).' blocks that have failures (and resolve-exception steps)');

        if ($failingBlocks->isEmpty()) {
            Step::log(null, 'dispatcher', 'No failing blocks with resolve-exception steps - returning false');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        $block = $failingBlocks->first();
        Step::log(null, 'dispatcher', 'Promoting resolve-exception steps in first failing block: '.$block);

        $stepIds = Step::where('block_uuid', $block)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('id')
            ->all();

        Step::log(null, 'dispatcher', 'Found '.count($stepIds).' resolve-exception steps to promote in block '.$block);

        if (! empty($stepIds)) {
            info_if('[StepDispatcher.promoteResolveExceptionSteps] Batch promoting '.count($stepIds)." resolve-exception steps in block {$block}");

            foreach ($stepIds as $stepId) {
                Step::log($stepId, 'job', '⬆️ PROMOTED: Step #'.$stepId.' (resolve-exception) is being promoted to Pending (failure detected in block)');
            }

            self::batchTransitionSteps($stepIds, Pending::class);
            Step::log(null, 'dispatcher', 'batchTransitionSteps() completed - resolve-exception steps now PENDING');
        }

        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ promoteResolveExceptionSteps END ←←←');
        Step::log(null, 'dispatcher', 'Returning true (resolve-exception steps promoted)');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        return true;
    }

    /**
     * If a parent failed/stopped, fail all its non-terminal children.
     */
    public static function cascadeFailureToChildren(?string $group = null): bool
    {
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ cascadeFailureToChildren START ←←←');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        $failedParents = Step::whereIn('state', [Failed::class, Stopped::class])
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        Step::log(null, 'dispatcher', 'Found '.$failedParents->count().' failed/stopped parent steps with children');

        if ($failedParents->isEmpty()) {
            Step::log(null, 'dispatcher', 'No failed parents found - returning false');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        foreach ($failedParents as $parent) {
            $childBlock = $parent->child_block_uuid;
            Step::log($parent->id, 'job', 'FAILED PARENT: Step #'.$parent->id.' | state: '.$parent->state.' | child_block_uuid: '.$childBlock);
            Step::log(null, 'dispatcher', 'Checking child block '.$childBlock.' for parent Step #'.$parent->id);

            $nonTerminalChildIds = Step::where('block_uuid', $childBlock)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->whereNotIn('state', Step::terminalStepStates())
                ->pluck('id')
                ->all();

            Step::log(null, 'dispatcher', 'Found '.count($nonTerminalChildIds).' non-terminal children in block '.$childBlock);

            if (empty($nonTerminalChildIds)) {
                Step::log(null, 'dispatcher', 'No non-terminal children to fail for parent Step #'.$parent->id);
                continue;
            }

            info_if('[StepDispatcher.cascadeFailureToChildren] Batch failing '.count($nonTerminalChildIds)." children in block {$childBlock}");
            Step::log(null, 'dispatcher', 'Batch failing '.count($nonTerminalChildIds).' children in block '.$childBlock);

            foreach ($nonTerminalChildIds as $childId) {
                Step::log($childId, 'job', '⚠️ BATCH FAILED: Step #'.$childId.' is being failed (parent failed/stopped)');
            }

            self::batchTransitionSteps($nonTerminalChildIds, Failed::class);
            Step::log(null, 'dispatcher', 'batchTransitionSteps() completed - children now FAILED');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            Step::log(null, 'dispatcher', '→→→ cascadeFailureToChildren END ←←←');
            Step::log(null, 'dispatcher', 'Returning true (ended tick after failing one child block)');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

            return true; // end tick after failing one block
        }

        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '→→→ cascadeFailureToChildren END ←←←');
        Step::log(null, 'dispatcher', 'Returning false (no children to fail)');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

        return false;
    }

    /**
     * Collect all nested child block UUIDs reachable from the given parent steps.
     * Optimized with recursive CTE query for better performance.
     */
    public static function collectAllNestedChildBlocks($parents, ?string $group = null): array
    {
        $rootBlocks = $parents->pluck('child_block_uuid')->filter()->unique()->values();

        if ($rootBlocks->isEmpty()) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, $rootBlocks->count(), '?'));

        $sql = "
            WITH RECURSIVE descendants AS (
                SELECT child_block_uuid AS block_uuid
                FROM steps
                WHERE child_block_uuid IN ({$placeholders})
                  AND child_block_uuid IS NOT NULL
                  ".($group !== null ? 'AND `group` = ?' : '').'

                UNION ALL

                SELECT s.child_block_uuid
                FROM steps s
                INNER JOIN descendants d ON s.block_uuid = d.block_uuid
                WHERE s.child_block_uuid IS NOT NULL
                  '.($group !== null ? 'AND s.`group` = ?' : '').'
            )
            SELECT DISTINCT block_uuid FROM descendants
        ';

        $bindings = $rootBlocks->values()->all();
        if ($group !== null) {
            $bindings[] = $group;
            $bindings[] = $group;
        }

        $results = DB::select($sql, $bindings);

        return collect($results)->pluck('block_uuid')->unique()->values()->all();
    }

    /**
     * Batch transition steps to a new state using proper state machine transitions.
     *
     * CRITICAL: Uses $step->state->transitionTo() to ensure:
     * - Transition classes execute (handle() methods with business logic)
     * - Observers fire (StepObserver::updated())
     * - State machine guards enforced (canTransition() checks)
     * - Additional fields set (completed_at, is_throttled, etc.)
     *
     * Previous implementation used DB::table()->update() which bypassed ALL of this.
     */
    public static function batchTransitionSteps(array $stepIds, string $toState): void
    {
        if (empty($stepIds)) {
            Step::log(null, 'dispatcher', '[batchTransitionSteps] Empty step IDs array - skipping');
            return;
        }

        $stepIdsStr = implode(', ', $stepIds);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($backtrace[1]) ? ($backtrace[1]['class'] ?? 'unknown') . '::' . ($backtrace[1]['function'] ?? 'unknown') : 'unknown';

        Step::log(null, 'dispatcher', '╔═══════════════════════════════════════════════════════════╗');
        Step::log(null, 'dispatcher', '║         BATCH TRANSITION STEPS                            ║');
        Step::log(null, 'dispatcher', '╚═══════════════════════════════════════════════════════════╝');
        Step::log(null, 'dispatcher', 'CALLED BY: '.$caller);
        Step::log(null, 'dispatcher', 'Target State: '.$toState);
        Step::log(null, 'dispatcher', 'Step Count: '.count($stepIds));
        Step::log(null, 'dispatcher', 'Step IDs: ['.$stepIdsStr.']');

        Step::log(null, 'dispatcher', 'Loading steps and transitioning via state machine...');
        $steps = Step::whereIn('id', $stepIds)->get();
        Step::log(null, 'dispatcher', 'Loaded '.count($steps).' steps');

        $successCount = 0;
        $failureCount = 0;

        foreach ($steps as $step) {
            Step::log($step->id, 'job', "Transitioning Step #{$step->id} from {$step->state} to {$toState} via state machine");

            try {
                // Use proper state transition - triggers transition class handle() and observers
                $step->state->transitionTo($toState);
                Step::log($step->id, 'job', "✓ Step #{$step->id} successfully transitioned to {$toState}");
                $successCount++;
            } catch (\Exception $e) {
                Step::log($step->id, 'job', "✗ Step #{$step->id} transition FAILED: {$e->getMessage()}");
                Step::log($step->id, 'job', "  └─ Current state: {$step->state} | Target state: {$toState}");
                $failureCount++;
                // Log but continue - don't fail entire batch due to one invalid transition
            }
        }

        Step::log(null, 'dispatcher', '✓ Batch transition complete:');
        Step::log(null, 'dispatcher', "  - Succeeded: {$successCount} steps");
        Step::log(null, 'dispatcher', "  - Failed: {$failureCount} steps");
        Step::log(null, 'dispatcher', '╚═══════════════════════════════════════════════════════════╝');
    }

    /**
     * Check if it's safe to restart Horizon/queues and deploy new code.
     *
     * Returns true only when ALL conditions are met:
     * 1. No critical non-parent steps are in Running state (actively executing work)
     * 2. No critical non-parent steps are in Dispatched state (about to execute)
     *
     * Parent steps (with child_block_uuid) are just waiting - they can be interrupted.
     * Only child steps (without child_block_uuid) are actively doing work.
     *
     * @param  string|null  $group  Optional group filter (null = all groups)
     * @return bool True if safe to restart, false otherwise
     */
    public static function canSafelyRestart(?string $group = null): bool
    {
        Step::log(null, 'dispatcher', '╔══════════════════════════════════════════════════════════════╗');
        Step::log(null, 'dispatcher', '║         CHECKING IF SAFE TO RESTART HORIZON              ║');
        Step::log(null, 'dispatcher', '╚══════════════════════════════════════════════════════════════╝');
        Step::log(null, 'dispatcher', 'Group filter: '.($group ?? 'null (all groups)'));
        Step::log(null, 'dispatcher', 'Only checking non-coolable non-parent steps (actual workers)');

        // 1. Check for non-coolable Running non-parent steps (actively executing critical work)
        $runningCount = Step::where('state', Running::class)
            ->where('can_cool_down', false)
            ->whereNull('child_block_uuid')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->count();

        Step::log(null, 'dispatcher', '');
        Step::log(null, 'dispatcher', '1️⃣ NON-COOLABLE RUNNING STEPS CHECK (non-parent only):');
        Step::log(null, 'dispatcher', '   Found '.$runningCount.' non-coolable Running steps');

        if ($runningCount > 0) {
            Step::log(null, 'dispatcher', '   ❌ UNSAFE: Still have '.$runningCount.' non-coolable steps actively running');
            Step::log(null, 'dispatcher', '   → Wait for these to complete before restarting');
            Step::log(null, 'dispatcher', '');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            Step::log(null, 'dispatcher', '🔴 RESULT: NOT SAFE TO RESTART ('.$runningCount.' non-coolable running)');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }
        Step::log(null, 'dispatcher', '   ✅ No non-coolable Running steps (good)');

        // 2. Check for non-coolable Dispatched non-parent steps (about to execute)
        $dispatchedCount = Step::where('state', 'Martingalian\\Core\\States\\Dispatched')
            ->where('can_cool_down', false)
            ->whereNull('child_block_uuid')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->count();

        Step::log(null, 'dispatcher', '');
        Step::log(null, 'dispatcher', '2️⃣ NON-COOLABLE DISPATCHED STEPS CHECK (non-parent only):');
        Step::log(null, 'dispatcher', '   Found '.$dispatchedCount.' non-coolable Dispatched steps');

        if ($dispatchedCount > 0) {
            Step::log(null, 'dispatcher', '   ⚠️  WARNING: '.$dispatchedCount.' non-coolable steps in Dispatched state');
            Step::log(null, 'dispatcher', '   → These are about to execute - wait for them to complete');
            Step::log(null, 'dispatcher', '');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
            Step::log(null, 'dispatcher', '🟡 RESULT: NOT SAFE TO RESTART ('.$dispatchedCount.' non-coolable dispatched)');
            Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }
        Step::log(null, 'dispatcher', '   ✅ No non-coolable Dispatched steps (good)');

        // All checks passed!
        Step::log(null, 'dispatcher', '');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '✅ ALL CHECKS PASSED - SAFE TO RESTART HORIZON!');
        Step::log(null, 'dispatcher', '═══════════════════════════════════════════════════════════');
        Step::log(null, 'dispatcher', '');
        Step::log(null, 'dispatcher', 'You can now safely:');
        Step::log(null, 'dispatcher', '  1. Stop Horizon/supervisors');
        Step::log(null, 'dispatcher', '  2. Deploy new code');
        Step::log(null, 'dispatcher', '  3. Restart Horizon/supervisors');
        Step::log(null, 'dispatcher', '');

        return true;
    }
}
