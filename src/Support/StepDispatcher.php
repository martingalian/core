<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Illuminate\Support\Facades\DB;
use Log;
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
        // Acquire the DB lock authoritatively; bail if already running.
        if (! StepsDispatcher::startDispatch($group)) {
            Log::channel('dispatcher')->warning('[TICK SKIPPED] Already running');

            return;
        }

        $progress = 0;

        try {
            // Marks as skipped all children steps on a skipped step.
            $result = self::skipAllChildStepsOnParentAndChildSingleStep($group);
            if ($result) {
                info_if('-= TICK ENDED (skipAllChildStepsOnParentAndChildSingleStep = true) =-');

                return;
            }

            $progress = 1;

            // Perform cascading cancellation for failed steps and return early if needed
            $result = self::cascadeCancelledSteps($group);
            if ($result) {
                info_if('-= TICK ENDED (cascadeCancelledSteps = true) =-');

                return;
            }

            $progress = 2;

            $result = self::promoteResolveExceptionSteps($group);
            if ($result) {
                info_if('-= TICK ENDED (promoteResolveExceptionSteps = true) =-');

                return;
            }

            $progress = 3;

            // Check if we need to transition parent steps to Failed first, but only if no cancellations occurred
            $result = self::transitionParentsToFailed($group);
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToFailed = true) =-');

                return;
            }

            $progress = 4;

            $result = self::cascadeFailureToChildren($group);
            if ($result) {
                info_if('-= TICK ENDED (cascadeFailureToChildren = true) =-');

                return;
            }

            $progress = 5;

            // Check if we need to transition parent steps to Completed
            $result = self::transitionParentsToComplete($group);
            if ($result) {
                info_if('-= TICK ENDED (transitionParentsToComplete = true) =-');

                return;
            }

            $progress = 6;

            // Distribute the steps to be dispatched (only if no cancellations or failures happened)
            $dispatchedSteps = collect();

            $pendingQuery = Step::pending()
                ->when($group !== null, static fn ($q) => $q->where('group', $group), static fn ($q) => $q->whereNull('group'))
                ->where(function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<=', now());
                });

            $pendingSteps = $pendingQuery->get();

            // Priority Queue System: If any high-priority steps exist, filter to only those
            if ($pendingSteps->contains('priority', 'high')) {
                $totalSteps = $pendingSteps->count();
                $pendingSteps = $pendingSteps->where('priority', 'high')->values();
                info_if('[StepDispatcher] High-priority steps detected - filtering to '.$pendingSteps->count().' high-priority steps (from '.$totalSteps.' total pending steps)');
            }

            $canTransitionCount = 0;
            $cannotTransitionCount = 0;

            $pendingSteps->each(static function (Step $step) use ($dispatchedSteps, &$canTransitionCount, &$cannotTransitionCount) {
                info_if("[StepDispatcher.dispatch] Evaluating Step ID {$step->id} with index {$step->index} in block {$step->block_uuid}");
                $transition = new PendingToDispatched($step);

                if ($transition->canTransition()) {
                    info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} CAN transition to DISPATCHED");
                    $transition->apply();
                    $dispatchedSteps->push($step->fresh());
                    $canTransitionCount++;
                } else {
                    info_if("[StepDispatcher.dispatch] -> Step ID {$step->id} cannot transition to DISPATCHED");
                    $cannotTransitionCount++;
                }
            });

            // Dispatch all steps that are ready
            $dispatchedSteps->each(fn ($step) => (new self)->dispatchSingleStep($step));

            info_if('Total steps dispatched: '.$dispatchedSteps->count().($group ? " [group={$group}]" : ''));
            info_if('-= TICK ENDED (full cycle) =-');

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

        info("[StepDispatcher.transitionParentsToComplete] Found " . $runningParents->count() . " running parents");

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = self::collectAllNestedChildBlocks($runningParents, $group);
        info("[StepDispatcher.transitionParentsToComplete] Collected " . count($childBlockUuids) . " nested child block UUIDs");

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        info("[StepDispatcher.transitionParentsToComplete] Loaded child steps for " . $childStepsByBlock->count() . " blocks");

        $changed = false;

        foreach ($runningParents as $step) {
            info("[StepDispatcher.transitionParentsToComplete] Checking Parent Step #{$step->id} | CLASS: {$step->class} | child_block_uuid: {$step->child_block_uuid}");

            try {
                $areConcluded = $step->childStepsAreConcludedFromMap($childStepsByBlock);
                info("[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | childStepsAreConcludedFromMap returned: " . ($areConcluded ? 'TRUE' : 'FALSE'));

                if ($areConcluded) {
                    info("[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: TRANSITION TO COMPLETED");
                    $step->state->transitionTo(Completed::class);
                    $changed = true;
                    info("[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | SUCCESS - Transitioned to Completed");
                } else {
                    info("[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | DECISION: SKIP - Children not concluded yet");
                }
            } catch (\Exception $e) {
                info("[StepDispatcher.transitionParentsToComplete] Parent Step #{$step->id} | EXCEPTION during transition: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine());
            }
        }

        return $changed;
    }

    /**
     * If a parent was skipped, mark all its descendants as skipped.
     */
    public static function skipAllChildStepsOnParentAndChildSingleStep(?string $group = null): bool
    {
        $skippedParents = Step::where('state', Skipped::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($skippedParents->isEmpty()) {
            return false;
        }

        $allChildBlocks = self::collectAllNestedChildBlocks($skippedParents, $group);

        if (empty($allChildBlocks)) {
            return true;
        }

        $descendantIds = Step::whereIn('block_uuid', $allChildBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->pluck('id')
            ->all();

        if (! empty($descendantIds)) {
            info_if('[StepDispatcher.skipAllChildStepsOnParentAndChildSingleStep] - Batch transitioning '.count($descendantIds).' steps to SKIPPED');
            self::batchTransitionSteps($descendantIds, Skipped::class);
        }

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

            info("[StepDispatcher.transitionParentsToFailed] Checking Parent Step #{$parentStep->id} | CLASS: {$parentStep->class} | child_block_uuid: {$parentStep->child_block_uuid} | Children count: " . $childSteps->count());

            if ($childSteps->isEmpty()) {
                info("[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | WARNING: No children found for child_block_uuid");
                continue;
            }

            $allChildStates = $childSteps->map(fn($s) => "ID:{$s->id}=" . class_basename($s->state))->join(', ');
            info("[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | All child states: [{$allChildStates}]");

            $failedChildSteps = $childSteps->filter(
                fn ($step) => in_array(get_class($step->state), Step::failedStepStates())
            );

            if ($failedChildSteps->isNotEmpty()) {
                $failedIds = $failedChildSteps->pluck('id')->join(', ');
                $failedStates = $failedChildSteps->map(fn($s) => class_basename($s->state))->join(', ');
                info("[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: TRANSITION TO FAILED | Failed children: [{$failedIds}] | States: [{$failedStates}]");
                $parentStep->state->transitionTo(Failed::class);

                return true;
            }

            info("[StepDispatcher.transitionParentsToFailed] Parent Step #{$parentStep->id} | DECISION: SKIP - No failed children");
        }

        return false;
    }

    /**
     * Cancel downstream runnable steps after a failure/stop.
     */
    public static function cascadeCancelledSteps(?string $group = null): bool
    {
        $cancellationsOccurred = false;

        // Find all failed/stopped steps that should trigger downstream cancellation.
        $failedSteps = Step::whereIn('state', Step::failedStepStates())
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('index')
            ->get();

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->where('index', '>', $failedStep->index)
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->where('type', 'default')
                ->get();

            if ($stepsToCancel->isEmpty()) {
                continue;
            }

            info_if("[StepDispatcher.cascadeCancelledSteps] Total Steps to cancel: {$stepsToCancel->count()}");

            $stepIdsToCancel = [];
            $parentSteps = [];

            foreach ($stepsToCancel as $step) {
                // Double guard: never attempt to transition terminal states.
                if (in_array(get_class($step->state), Step::terminalStepStates(), true)) {
                    continue;
                }

                $stepIdsToCancel[] = $step->id;

                // Track parent steps to cancel their children
                if ($step->isParent()) {
                    $parentSteps[] = $step;
                }
            }

            if (! empty($stepIdsToCancel)) {
                info_if('[StepDispatcher.cascadeCancelledSteps] Batch cancelling '.count($stepIdsToCancel)." steps in block {$blockUuid}");
                self::batchTransitionSteps($stepIdsToCancel, Cancelled::class);
                $cancellationsOccurred = true;

                // Cancel child blocks
                foreach ($parentSteps as $parentStep) {
                    self::cancelChildBlockSteps($parentStep, $group);
                }
            }
        }

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
        $candidateBlocks = Step::where('type', 'resolve-exception')
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        if ($candidateBlocks->isEmpty()) {
            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', '<>', 'resolve-exception')
            ->whereIn('state', Step::failedStepStates())
            ->pluck('block_uuid')
            ->unique()
            ->values();

        if ($failingBlocks->isEmpty()) {
            return false;
        }

        $block = $failingBlocks->first();

        $stepIds = Step::where('block_uuid', $block)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('id')
            ->all();

        if (! empty($stepIds)) {
            info_if('[StepDispatcher.promoteResolveExceptionSteps] Batch promoting '.count($stepIds)." resolve-exception steps in block {$block}");
            self::batchTransitionSteps($stepIds, Pending::class);
        }

        return true;
    }

    /**
     * If a parent failed/stopped, fail all its non-terminal children.
     */
    public static function cascadeFailureToChildren(?string $group = null): bool
    {
        $failedParents = Step::whereIn('state', [Failed::class, Stopped::class])
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        foreach ($failedParents as $parent) {
            $childBlock = $parent->child_block_uuid;

            $nonTerminalChildIds = Step::where('block_uuid', $childBlock)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
                ->whereNotIn('state', Step::terminalStepStates())
                ->pluck('id')
                ->all();

            if (empty($nonTerminalChildIds)) {
                continue;
            }

            info_if('[StepDispatcher.cascadeFailureToChildren] Batch failing '.count($nonTerminalChildIds)." children in block {$childBlock}");
            self::batchTransitionSteps($nonTerminalChildIds, Failed::class);

            return true; // end tick after failing one block
        }

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
     * Batch transition steps to a new state for performance.
     */
    public static function batchTransitionSteps(array $stepIds, string $toState): void
    {
        if (empty($stepIds)) {
            return;
        }

        $stepIdsStr = implode(', ', $stepIds);
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($backtrace[1]) ? ($backtrace[1]['class'] ?? 'unknown') . '::' . ($backtrace[1]['function'] ?? 'unknown') : 'unknown';

        info("[StepDispatcher.batchTransitionSteps] CALLED BY: {$caller} | Batch transitioning " . count($stepIds) . " steps to state: {$toState} | Step IDs: [{$stepIdsStr}]");

        DB::table('steps')
            ->whereIn('id', $stepIds)
            ->update([
                'state' => $toState,
                'updated_at' => now(),
            ]);

        info("[StepDispatcher.batchTransitionSteps] SUCCESS - Batch transition complete for " . count($stepIds) . " steps");
    }
}
