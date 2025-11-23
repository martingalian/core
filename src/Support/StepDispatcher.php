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
        log_step('dispatcher', '╔══════════════════════════════════════════════════════════════╗');
        log_step('dispatcher', '║              DISPATCHER TICK START                           ║');
        log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
        log_step('dispatcher', 'Group: '.($group ?? 'null (all groups)'));

        // Acquire the DB lock authoritatively; bail if already running.
        if (! StepsDispatcher::startDispatch($group)) {
            log_step('dispatcher', '⚠️ [TICK SKIPPED] Already running');

            return;
        }

        $progress = 0;
        log_step('dispatcher', 'Progress: 0 - Lock acquired, starting dispatcher cycle');

        try {
            // Marks as skipped all children steps on a skipped step.
            log_step('dispatcher', '→ Calling skipAllChildStepsOnParentAndChildSingleStep()');
            $result = self::skipAllChildStepsOnParentAndChildSingleStep($group);
            log_step('dispatcher', '← skipAllChildStepsOnParentAndChildSingleStep() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (skipAllChildStepsOnParentAndChildSingleStep = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 0 (skip children)');

                return;
            }

            $progress = 1;
            log_step('dispatcher', 'Progress: 1 - Skip children check complete');

            // Perform cascading cancellation for failed steps and return early if needed
            log_step('dispatcher', '→ Calling cascadeCancelledSteps()');
            $result = self::cascadeCancelledSteps($group);
            log_step('dispatcher', '← cascadeCancelledSteps() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (cascadeCancelledSteps = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 1 (cascade cancelled)');

                return;
            }

            $progress = 2;
            log_step('dispatcher', 'Progress: 2 - Cascade cancelled check complete');

            log_step('dispatcher', '→ Calling promoteResolveExceptionSteps()');
            $result = self::promoteResolveExceptionSteps($group);
            log_step('dispatcher', '← promoteResolveExceptionSteps() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (promoteResolveExceptionSteps = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 2 (promote resolve-exception)');

                return;
            }

            $progress = 3;
            log_step('dispatcher', 'Progress: 3 - Promote resolve-exception check complete');

            // Check if we need to transition parent steps to Failed first, but only if no cancellations occurred
            log_step('dispatcher', '→ Calling transitionParentsToFailed()');
            $result = self::transitionParentsToFailed($group);
            log_step('dispatcher', '← transitionParentsToFailed() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToFailed = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 3 (transition parents to failed)');

                return;
            }

            $progress = 4;
            log_step('dispatcher', 'Progress: 4 - Transition parents to failed check complete');

            log_step('dispatcher', '→ Calling cascadeFailureToChildren()');
            $result = self::cascadeFailureToChildren($group);
            log_step('dispatcher', '← cascadeFailureToChildren() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (cascadeFailureToChildren = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 4 (cascade failure to children)');

                return;
            }

            $progress = 5;
            log_step('dispatcher', 'Progress: 5 - Cascade failure to children check complete');

            // Check if we need to transition parent steps to Completed
            log_step('dispatcher', '→ Calling transitionParentsToComplete()');
            $result = self::transitionParentsToComplete($group);
            log_step('dispatcher', '← transitionParentsToComplete() returned: '.($result ? 'true' : 'false'));
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToComplete = true) =-');
                log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                log_step('dispatcher', '  TICK ENDED EARLY at progress 5 (transition parents to complete)');

                return;
            }

            $progress = 6;
            log_step('dispatcher', 'Progress: 6 - Transition parents to complete check complete');

            // Distribute the steps to be dispatched (only if no cancellations or failures happened)
            log_step('dispatcher', '→ Starting pending step evaluation and dispatch');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            log_step('dispatcher', '→→→ PENDING STEP SELECTION DIAGNOSTICS ←←←');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            $dispatchedSteps = collect();

            $pendingQuery = Step::pending()
                ->when($group !== null, static fn ($q) => $q->where('group', $group), static fn ($q) => $q->whereNull('group'))
                ->where(function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<=', now());
                });

            log_step('dispatcher', '🔍 DIAGNOSTIC: Step::pending() scope ONLY selects state = Pending::class');
            log_step('dispatcher', '🔍 DIAGNOSTIC: Query filters: group = '.($group ?? 'NULL').', dispatch_after <= '.now());

            $pendingSteps = $pendingQuery->get();
            log_step('dispatcher', 'Found '.$pendingSteps->count().' PENDING steps ready for evaluation');

            // CIRCUIT BREAKER: Check if step dispatching is globally enabled
            // When disabled, only dispatch in-flight work (throttled steps + children of Running parents)
            $martingalian = Martingalian::first();
            $circuitBreakerDisabled = ! $martingalian || ! $martingalian->can_dispatch_steps;

            if ($circuitBreakerDisabled) {
                $originalCount = $pendingSteps->count();
                log_step('dispatcher', '🔴 CIRCUIT BREAKER: Step dispatching is DISABLED globally (can_dispatch_steps = false)');
                log_step('dispatcher', '→ All state management phases completed successfully');
                log_step('dispatcher', '→ Filtering pending steps to in-flight work only (throttled + children of Running parents)');

                // Filter to only in-flight work
                $pendingSteps = $pendingSteps->filter(function (Step $step) {
                    // Allow throttled steps to complete
                    if ($step->is_throttled) {
                        log_step($step->id, '✅ CIRCUIT BREAKER FILTER: Step #'.$step->id.' allowed (is_throttled = true)');
                        return true;
                    }

                    // Allow children of Running parents to complete
                    if ($step->parentIsRunning()) {
                        log_step($step->id, '✅ CIRCUIT BREAKER FILTER: Step #'.$step->id.' allowed (parent is Running)');
                        return true;
                    }

                    // Block new top-level work
                    log_step($step->id, '❌ CIRCUIT BREAKER FILTER: Step #'.$step->id.' blocked (new work - not throttled, parent not Running)');
                    return false;
                });

                $filteredCount = $pendingSteps->count();
                $blockedCount = $originalCount - $filteredCount;

                log_step('dispatcher', '→ Filtered results:');
                log_step('dispatcher', '  - Original pending steps: '.$originalCount);
                log_step('dispatcher', '  - In-flight steps allowed: '.$filteredCount);
                log_step('dispatcher', '  - New work blocked: '.$blockedCount);

                if ($filteredCount === 0) {
                    log_step('dispatcher', '→ No in-flight steps to dispatch - ending tick');
                    info_if('-= TICK ENDED (circuit breaker - no in-flight work to dispatch) =-');
                    log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
                    log_step('dispatcher', '  TICK ENDED at progress 6 (circuit breaker active - no in-flight work)');

                    return;
                }

                log_step('dispatcher', '→ Continuing with '.$filteredCount.' in-flight steps');
            } else {
                log_step('dispatcher', '✓ Circuit breaker check passed - can_dispatch_steps = true (normal operation)');
            }

            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            // Priority Queue System: If any high-priority steps exist, filter to only those
            if ($pendingSteps->contains('priority', 'high')) {
                $totalSteps = $pendingSteps->count();
                $pendingSteps = $pendingSteps->where('priority', 'high')->values();
                info_if('[StepDispatcher] High-priority steps detected - filtering to '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total pending steps)');
                log_step('dispatcher', '⬆️ HIGH-PRIORITY FILTERING: '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total)');
            }

            $canTransitionCount = 0;
            $cannotTransitionCount = 0;

            $pendingSteps->each(static function (Step $step) use ($dispatchedSteps, &$canTransitionCount, &$cannotTransitionCount) {
                info_if("[StepDispatcher.dispatch] Evaluating Step ID {$step->id} with index {$step->index} in block {$step->block_uuid}");
                log_step($step->id, '╔═══════════════════════════════════════════════════════════╗');
                log_step($step->id, '║   DISPATCHER: EVALUATING FOR DISPATCH                     ║');
                log_step($step->id, '╚═══════════════════════════════════════════════════════════╝');
                log_step($step->id, 'Step details:');
                log_step($step->id, '  - Step ID: '.$step->id);
                log_step($step->id, '  - Class: '.$step->class);
                log_step($step->id, '  - Index: '.$step->index);
                log_step($step->id, '  - Block UUID: '.$step->block_uuid);
                log_step($step->id, '  - Priority: '.$step->priority);
                log_step($step->id, '  - State: '.$step->state);
                log_step($step->id, '  - Retries: '.$step->retries);

                log_step($step->id, 'Creating PendingToDispatched transition...');
                $transition = new PendingToDispatched($step);
                log_step($step->id, 'Calling canTransition()...');

                if ($transition->canTransition()) {
                    info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} CAN transition to DISPATCHED");
                    log_step($step->id, '✓ canTransition() returned TRUE');
                    log_step($step->id, 'Calling transition->apply()...');
                    $transition->apply();
                    log_step($step->id, 'transition->apply() completed');
                    $dispatchedSteps->push($step->fresh());
                    $canTransitionCount++;
                    log_step($step->id, '→ Step WILL BE DISPATCHED (added to dispatchedSteps collection)');
                } else {
                    info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} cannot transition to DISPATCHED");
                    log_step($step->id, '✗ canTransition() returned FALSE');
                    log_step($step->id, '→ Step SKIPPED (not ready for dispatch)');
                    $cannotTransitionCount++;
                }
                log_step($step->id, '╚═══════════════════════════════════════════════════════════╝');
            });

            log_step('dispatcher', 'Pending step evaluation complete:');
            log_step('dispatcher', '  - Can transition: '.$canTransitionCount);
            log_step('dispatcher', '  - Cannot transition: '.$cannotTransitionCount);
            log_step('dispatcher', '  - Total evaluated: '.($canTransitionCount + $cannotTransitionCount));

            // Dispatch all steps that are ready
            log_step('dispatcher', 'Dispatching '.$dispatchedSteps->count().' steps to their jobs...');
            $dispatchedSteps->each(function ($step) {
                log_step($step->id, 'DISPATCHER: Calling dispatchSingleStep() to dispatch job to queue');
                (new self)->dispatchSingleStep($step);
                log_step($step->id, 'DISPATCHER: dispatchSingleStep() completed - job queued');
            });

            info_if('Total steps dispatched: '.$dispatchedSteps->count().($group ? " [group={$group}]" : ''));
            info_if('-= TICK ENDED (full cycle) =-');
            log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
            log_step('dispatcher', '  TICK ENDED NORMALLY at progress 6 (full cycle complete)');
            log_step('dispatcher', '  Total steps dispatched: '.$dispatchedSteps->count());

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

        log_step('dispatcher', "[StepDispatcher.transitionParentsToComplete] Found " . $runningParents->count() . " running parents");

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = self::collectAllNestedChildBlocks($runningParents, $group);
        log_step('dispatcher', "[StepDispatcher.transitionParentsToComplete] Collected " . count($childBlockUuids) . " nested child block UUIDs");

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        log_step('dispatcher', "[StepDispatcher.transitionParentsToComplete] Loaded child steps for " . $childStepsByBlock->count() . " blocks");

        $changed = false;

        foreach ($runningParents as $step) {
            log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Checking Parent Step #{$step->id} | CLASS: {$step->class} | child_block_uuid: {$step->child_block_uuid}");

            try {
                $areConcluded = $step->childStepsAreConcludedFromMap($childStepsByBlock);
                log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | childStepsAreConcludedFromMap returned: " . ($areConcluded ? 'TRUE' : 'FALSE'));

                if ($areConcluded) {
                    log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: TRANSITION TO COMPLETED");
                    $step->state->transitionTo(Completed::class);
                    $changed = true;
                    log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | SUCCESS - Transitioned to Completed");
                } else {
                    log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: SKIP - Children not concluded yet");
                }
            } catch (\Exception $e) {
                log_step($step->id, "[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | EXCEPTION during transition: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine());
            }
        }

        return $changed;
    }

    /**
     * If a parent was skipped, mark all its descendants as skipped.
     */
    public static function skipAllChildStepsOnParentAndChildSingleStep(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ skipAllChildStepsOnParentAndChildSingleStep START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $skippedParents = Step::where('state', Skipped::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        log_step('dispatcher', 'Found '.$skippedParents->count().' skipped parent steps with children');

        if ($skippedParents->isEmpty()) {
            log_step('dispatcher', 'No skipped parents found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        foreach ($skippedParents as $parent) {
            log_step($parent->id, 'SKIPPED PARENT: Step #'.$parent->id.' | child_block_uuid: '.$parent->child_block_uuid);
        }

        $allChildBlocks = self::collectAllNestedChildBlocks($skippedParents, $group);
        log_step('dispatcher', 'Collected '.count($allChildBlocks).' nested child block UUIDs');

        if (empty($allChildBlocks)) {
            log_step('dispatcher', 'No child blocks found - returning true (early completion)');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            return true;
        }

        $descendantIds = Step::whereIn('block_uuid', $allChildBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->pluck('id')
            ->all();

        log_step('dispatcher', 'Found '.count($descendantIds).' descendant steps to skip');

        if (! empty($descendantIds)) {
            info_if('[StepDispatcher.skipAllChildStepsOnParentAndChildSingleStep] - Batch transitioning '.count($descendantIds).' steps to SKIPPED');
            log_step('dispatcher', 'Calling batchTransitionSteps() to skip '.count($descendantIds).' steps');

            foreach ($descendantIds as $stepId) {
                log_step($stepId, '⚠️ BATCH SKIPPED: Step #'.$stepId.' is being skipped (parent was skipped)');
            }

            self::batchTransitionSteps($descendantIds, Skipped::class);
            log_step('dispatcher', 'batchTransitionSteps() completed - all descendants now SKIPPED');
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ skipAllChildStepsOnParentAndChildSingleStep END ←←←');
        log_step('dispatcher', 'Returning true (children were skipped)');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        return true;
    }

    /**
     * Transition running parents to Failed if any child in their block failed.
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

            log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Checking Parent Step #{$parentStep->id} | CLASS: {$parentStep->class} | child_block_uuid: {$parentStep->child_block_uuid} | Children count: " . $childSteps->count());

            if ($childSteps->isEmpty()) {
                log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | WARNING: No children found for child_block_uuid");
                continue;
            }

            $allChildStates = $childSteps->map(fn($s) => "ID:{$s->id}=" . class_basename($s->state))->join(', ');
            log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | All child states: [{$allChildStates}]");

            $failedChildSteps = $childSteps->filter(
                fn ($step) => in_array(get_class($step->state), Step::failedStepStates())
            );

            if ($failedChildSteps->isNotEmpty()) {
                $failedIds = $failedChildSteps->pluck('id')->join(', ');
                $failedStates = $failedChildSteps->map(fn($s) => class_basename($s->state))->join(', ');
                log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: TRANSITION TO FAILED | Failed children: [{$failedIds}] | States: [{$failedStates}]");
                $parentStep->state->transitionTo(Failed::class);

                return true;
            }

            log_step($parentStep->id, "[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: SKIP - No failed children");
        }

        return false;
    }

    /**
     * Cancel downstream runnable steps after a failure/stop.
     */
    public static function cascadeCancelledSteps(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeCancelledSteps START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $cancellationsOccurred = false;

        // Find all failed/stopped steps that should trigger downstream cancellation.
        $failedSteps = Step::whereIn('state', Step::failedStepStates())
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('index')
            ->get();

        log_step('dispatcher', 'Found '.$failedSteps->count().' failed/stopped steps that may trigger cancellations');

        if ($failedSteps->isEmpty()) {
            log_step('dispatcher', 'No failed steps found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;
            log_step($failedStep->id, 'CASCADE SOURCE: Step #'.$failedStep->id.' | index: '.$failedStep->index.' | block: '.$blockUuid.' | state: '.$failedStep->state);
            log_step('dispatcher', 'Checking for steps to cancel after Step #'.$failedStep->id.' (index: '.$failedStep->index.')');

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->where('index', '>', $failedStep->index)
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->where('type', 'default')
                ->get();

            log_step('dispatcher', 'Found '.$stepsToCancel->count().' steps with higher index in block '.$blockUuid);

            if ($stepsToCancel->isEmpty()) {
                log_step('dispatcher', 'No steps to cancel for failed Step #'.$failedStep->id);
                continue;
            }

            info_if("[StepDispatcher.cascadeCancelledSteps] Total Steps to cancel: {$stepsToCancel->count()}");

            $stepIdsToCancel = [];
            $parentSteps = [];

            foreach ($stepsToCancel as $step) {
                log_step($step->id, 'CANCELLATION CANDIDATE: Step #'.$step->id.' | index: '.$step->index.' | state: '.$step->state.' | isParent: '.($step->isParent() ? 'yes' : 'no'));

                // Double guard: never attempt to transition terminal states.
                if (in_array(get_class($step->state), Step::terminalStepStates(), true)) {
                    log_step($step->id, '⚠️ SKIP: Step #'.$step->id.' is in terminal state - will not cancel');
                    continue;
                }

                $stepIdsToCancel[] = $step->id;
                log_step($step->id, '✓ WILL CANCEL: Step #'.$step->id.' added to cancellation batch');

                // Track parent steps to cancel their children
                if ($step->isParent()) {
                    $parentSteps[] = $step;
                    log_step($step->id, '→ Parent step - child blocks will also be cancelled');
                }
            }

            if (! empty($stepIdsToCancel)) {
                info_if('[StepDispatcher.cascadeCancelledSteps] Batch cancelling '.count($stepIdsToCancel)." steps in block {$blockUuid}");
                log_step('dispatcher', 'Batch cancelling '.count($stepIdsToCancel).' steps in block '.$blockUuid);

                foreach ($stepIdsToCancel as $stepId) {
                    log_step($stepId, '⚠️ BATCH CANCELLED: Step #'.$stepId.' is being cancelled (downstream of failure)');
                }

                self::batchTransitionSteps($stepIdsToCancel, Cancelled::class);
                log_step('dispatcher', 'batchTransitionSteps() completed - steps now CANCELLED');
                $cancellationsOccurred = true;

                // Cancel child blocks
                log_step('dispatcher', 'Checking '.count($parentSteps).' parent steps for child block cancellation');
                foreach ($parentSteps as $parentStep) {
                    log_step($parentStep->id, 'Calling cancelChildBlockSteps() for parent Step #'.$parentStep->id);
                    self::cancelChildBlockSteps($parentStep, $group);
                    log_step($parentStep->id, 'cancelChildBlockSteps() completed for parent Step #'.$parentStep->id);
                }
            }
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeCancelledSteps END ←←←');
        log_step('dispatcher', 'Returning: '.($cancellationsOccurred ? 'true (cancellations occurred)' : 'false (no cancellations)'));
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

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
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ promoteResolveExceptionSteps START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $candidateBlocks = Step::where('type', 'resolve-exception')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        log_step('dispatcher', 'Found '.count($candidateBlocks).' blocks with resolve-exception steps in NotRunnable state');

        if ($candidateBlocks->isEmpty()) {
            log_step('dispatcher', 'No candidate blocks found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', '<>', 'resolve-exception')
            ->whereIn('state', Step::failedStepStates())
            ->pluck('block_uuid')
            ->unique()
            ->values();

        log_step('dispatcher', 'Found '.count($failingBlocks).' blocks that have failures (and resolve-exception steps)');

        if ($failingBlocks->isEmpty()) {
            log_step('dispatcher', 'No failing blocks with resolve-exception steps - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        $block = $failingBlocks->first();
        log_step('dispatcher', 'Promoting resolve-exception steps in first failing block: '.$block);

        $stepIds = Step::where('block_uuid', $block)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('id')
            ->all();

        log_step('dispatcher', 'Found '.count($stepIds).' resolve-exception steps to promote in block '.$block);

        if (! empty($stepIds)) {
            info_if('[StepDispatcher.promoteResolveExceptionSteps] Batch promoting '.count($stepIds)." resolve-exception steps in block {$block}");

            foreach ($stepIds as $stepId) {
                log_step($stepId, '⬆️ PROMOTED: Step #'.$stepId.' (resolve-exception) is being promoted to Pending (failure detected in block)');
            }

            self::batchTransitionSteps($stepIds, Pending::class);
            log_step('dispatcher', 'batchTransitionSteps() completed - resolve-exception steps now PENDING');
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ promoteResolveExceptionSteps END ←←←');
        log_step('dispatcher', 'Returning true (resolve-exception steps promoted)');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        return true;
    }

    /**
     * If a parent failed/stopped, fail all its non-terminal children.
     */
    public static function cascadeFailureToChildren(?string $group = null): bool
    {
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeFailureToChildren START ←←←');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

        $failedParents = Step::whereIn('state', [Failed::class, Stopped::class])
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        log_step('dispatcher', 'Found '.$failedParents->count().' failed/stopped parent steps with children');

        if ($failedParents->isEmpty()) {
            log_step('dispatcher', 'No failed parents found - returning false');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            return false;
        }

        foreach ($failedParents as $parent) {
            $childBlock = $parent->child_block_uuid;
            log_step($parent->id, 'FAILED PARENT: Step #'.$parent->id.' | state: '.$parent->state.' | child_block_uuid: '.$childBlock);
            log_step('dispatcher', 'Checking child block '.$childBlock.' for parent Step #'.$parent->id);

            $nonTerminalChildIds = Step::where('block_uuid', $childBlock)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->whereNotIn('state', Step::terminalStepStates())
                ->pluck('id')
                ->all();

            log_step('dispatcher', 'Found '.count($nonTerminalChildIds).' non-terminal children in block '.$childBlock);

            if (empty($nonTerminalChildIds)) {
                log_step('dispatcher', 'No non-terminal children to fail for parent Step #'.$parent->id);
                continue;
            }

            info_if('[StepDispatcher.cascadeFailureToChildren] Batch failing '.count($nonTerminalChildIds)." children in block {$childBlock}");
            log_step('dispatcher', 'Batch failing '.count($nonTerminalChildIds).' children in block '.$childBlock);

            foreach ($nonTerminalChildIds as $childId) {
                log_step($childId, '⚠️ BATCH FAILED: Step #'.$childId.' is being failed (parent failed/stopped)');
            }

            self::batchTransitionSteps($nonTerminalChildIds, Failed::class);
            log_step('dispatcher', 'batchTransitionSteps() completed - children now FAILED');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            log_step('dispatcher', '→→→ cascadeFailureToChildren END ←←←');
            log_step('dispatcher', 'Returning true (ended tick after failing one child block)');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return true; // end tick after failing one block
        }

        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '→→→ cascadeFailureToChildren END ←←←');
        log_step('dispatcher', 'Returning false (no children to fail)');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');

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
            log_step('dispatcher', '[batchTransitionSteps] Empty step IDs array - skipping');
            return;
        }

        $stepIdsStr = implode(', ', $stepIds);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($backtrace[1]) ? ($backtrace[1]['class'] ?? 'unknown') . '::' . ($backtrace[1]['function'] ?? 'unknown') : 'unknown';

        log_step('dispatcher', '╔═══════════════════════════════════════════════════════════╗');
        log_step('dispatcher', '║         BATCH TRANSITION STEPS                            ║');
        log_step('dispatcher', '╚═══════════════════════════════════════════════════════════╝');
        log_step('dispatcher', 'CALLED BY: '.$caller);
        log_step('dispatcher', 'Target State: '.$toState);
        log_step('dispatcher', 'Step Count: '.count($stepIds));
        log_step('dispatcher', 'Step IDs: ['.$stepIdsStr.']');

        log_step('dispatcher', 'Loading steps and transitioning via state machine...');
        $steps = Step::whereIn('id', $stepIds)->get();
        log_step('dispatcher', 'Loaded '.count($steps).' steps');

        $successCount = 0;
        $failureCount = 0;

        foreach ($steps as $step) {
            log_step($step->id, "Transitioning Step #{$step->id} from {$step->state} to {$toState} via state machine");

            try {
                // Use proper state transition - triggers transition class handle() and observers
                $step->state->transitionTo($toState);
                log_step($step->id, "✓ Step #{$step->id} successfully transitioned to {$toState}");
                $successCount++;
            } catch (\Exception $e) {
                log_step($step->id, "✗ Step #{$step->id} transition FAILED: {$e->getMessage()}");
                log_step($step->id, "  └─ Current state: {$step->state} | Target state: {$toState}");
                $failureCount++;
                // Log but continue - don't fail entire batch due to one invalid transition
            }
        }

        log_step('dispatcher', '✓ Batch transition complete:');
        log_step('dispatcher', "  - Succeeded: {$successCount} steps");
        log_step('dispatcher', "  - Failed: {$failureCount} steps");
        log_step('dispatcher', '╚═══════════════════════════════════════════════════════════╝');
    }

    /**
     * Check if it's safe to restart Horizon/queues and deploy new code.
     *
     * Returns true only when ALL conditions are met:
     * 1. No steps are in Running state
     * 2. No steps are in Dispatched state
     *
     * This ensures a graceful shutdown with no orphaned or interrupted jobs.
     *
     * @param  string|null  $group  Optional group filter (null = all groups)
     * @return bool True if safe to restart, false otherwise
     */
    public static function canSafelyRestart(?string $group = null): bool
    {
        log_step('dispatcher', '╔══════════════════════════════════════════════════════════════╗');
        log_step('dispatcher', '║         CHECKING IF SAFE TO RESTART HORIZON              ║');
        log_step('dispatcher', '╚══════════════════════════════════════════════════════════════╝');
        log_step('dispatcher', 'Group filter: '.($group ?? 'null (all groups)'));

        // 1. Check for Running steps
        $runningCount = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->count();

        log_step('dispatcher', '');
        log_step('dispatcher', '1️⃣ RUNNING STEPS CHECK:');
        log_step('dispatcher', '   Found '.$runningCount.' Running steps');

        if ($runningCount > 0) {
            log_step('dispatcher', '   ❌ UNSAFE: Still have '.$runningCount.' steps actively running');
            log_step('dispatcher', '   → Wait for these to complete before restarting');
            log_step('dispatcher', '');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            log_step('dispatcher', '🔴 RESULT: NOT SAFE TO RESTART ('.$runningCount.' running)');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }
        log_step('dispatcher', '   ✅ No Running steps (good)');

        // 2. Check for Dispatched steps (orphaned from previous issues)
        $dispatchedCount = Step::where('state', 'Martingalian\\Core\\States\\Dispatched')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->count();

        log_step('dispatcher', '');
        log_step('dispatcher', '2️⃣ DISPATCHED STEPS CHECK:');
        log_step('dispatcher', '   Found '.$dispatchedCount.' Dispatched steps');

        if ($dispatchedCount > 0) {
            log_step('dispatcher', '   ⚠️  WARNING: '.$dispatchedCount.' steps in Dispatched state (possibly orphaned)');
            log_step('dispatcher', '   → These may be stuck from a previous Horizon crash');
            log_step('dispatcher', '   → Consider resetting them to Pending before restart');
            log_step('dispatcher', '');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');
            log_step('dispatcher', '🟡 RESULT: NOT SAFE TO RESTART ('.$dispatchedCount.' dispatched)');
            log_step('dispatcher', '═══════════════════════════════════════════════════════════');

            return false;
        }
        log_step('dispatcher', '   ✅ No Dispatched steps (good)');

        // All checks passed!
        log_step('dispatcher', '');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '✅ ALL CHECKS PASSED - SAFE TO RESTART HORIZON!');
        log_step('dispatcher', '═══════════════════════════════════════════════════════════');
        log_step('dispatcher', '');
        log_step('dispatcher', 'You can now safely:');
        log_step('dispatcher', '  1. Stop Horizon/supervisors');
        log_step('dispatcher', '  2. Deploy new code');
        log_step('dispatcher', '  3. Restart Horizon/supervisors');
        log_step('dispatcher', '');

        return true;
    }
}
