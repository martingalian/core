<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Completed;
use Martingalian\Core\States\Dispatched;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToDispatched extends Transition
{
    private Step $step;

    private ?array $stepsCache;

    public function __construct(Step $step, ?array $stepsCache = null)
    {
        $this->step = $step;
        $this->stepsCache = $stepsCache;
    }

    public function canTransition(): bool
    {
        Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
        Step::log($this->step->id, 'transition', '→→→ PendingToDispatched::canTransition() START ←←←');
        Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
        Step::log($this->step->id, 'transition', 'Step state: '.$this->step->state);
        Step::log($this->step->id, 'transition', 'Step type: '.$this->step->type);
        Step::log($this->step->id, 'transition', 'Step index: '.($this->step->index ?? 'null'));
        Step::log($this->step->id, 'transition', 'Block UUID: '.$this->step->block_uuid);

        Step::log($this->step->id, 'transition', 'Checking if state is Pending...');
        if (! $this->step->state instanceof Pending) {
            Step::log($this->step->id, 'transition', '✗ State is NOT Pending - returning false');
            Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
            return false;
        }
        Step::log($this->step->id, 'transition', '✓ State is Pending');

        /**
         * Check if the step is a 'resolve-exception' without index.
         * The logic to put this resolve-exception into pending state is made
         * as a passive decision, no worries.
         */
        Step::log($this->step->id, 'transition', '[CHECK 1/4] Is resolve-exception WITHOUT index?');
        if ($this->step->type === 'resolve-exception' && is_null($this->step->index)) {
            Step::log($this->step->id, 'transition', '✓ YES - resolve-exception with NO index - returning TRUE');
            Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
            return true;
        }
        Step::log($this->step->id, 'transition', '✗ NO - not resolve-exception without index');

        // Check if the step is a 'resolve-exception' with an index.
        Step::log($this->step->id, 'transition', '[CHECK 2/4] Is resolve-exception WITH index?');
        if ($this->step->type === 'resolve-exception' && ! is_null($this->step->index)) {
            Step::log($this->step->id, 'transition', '✓ YES - resolve-exception with index '.$this->step->index);

            // If the index is 1, there's no previous step to check, so allow the transition
            Step::log($this->step->id, 'transition', 'Checking if index === 1...');
            if ($this->step->index === 1) {
                Step::log($this->step->id, 'transition', '✓ Index is 1 (first step) - returning TRUE');
                Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
                return true;
            }
            Step::log($this->step->id, 'transition', '✗ Index is NOT 1 - need to check previous step');

            // Ensure that the previous step (index - 1) is 'resolve-exception' and completed.
            Step::log($this->step->id, 'transition', 'Looking for previous resolve-exception step at index '.($this->step->index - 1));
            $previousSteps = Step::where('block_uuid', $this->step->block_uuid)
                ->where('index', $this->step->index - 1)
                ->where('type', 'resolve-exception')
                ->get();

            Step::log($this->step->id, 'transition', 'Found '.$previousSteps->count().' previous resolve-exception step(s)');

            // If the previous step exists and is completed, allow transition
            if ($previousSteps->isNotEmpty() && in_array(get_class($previousSteps->first()->state), Step::concludedStepStates(), true)) {
                Step::log($this->step->id, 'transition', '✓ Previous resolve-exception step is concluded - returning TRUE');
                Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
                return true;
            }

            Step::log($this->step->id, 'transition', '✗ Previous resolve-exception step NOT concluded - returning FALSE');
            Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
            return false;
        }
        Step::log($this->step->id, 'transition', '✗ NO - not resolve-exception with index');

        /**
         * Orphan step:
         * ----------------------------
         * No parent and no child block.
         * If index is null → dispatch immediately.
         * Else → dispatch only if previous index is concluded.
         */
        Step::log($this->step->id, 'transition', '[CHECK 3/4] Is ORPHAN step?');
        if ($this->isOrphan()) {
            Step::log($this->step->id, 'transition', '✓ YES - step is ORPHAN (no parent, no children)');
            Step::log($this->step->id, 'transition', 'Checking index...');
            if (is_null($this->step->index)) {
                Step::log($this->step->id, 'transition', '✓ Index is NULL - dispatch immediately - returning TRUE');
                Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
                return true;
            }
            Step::log($this->step->id, 'transition', 'Index is '.$this->step->index.' - checking if previous index is concluded');
            $result = $this->previousIndexIsConcluded();
            Step::log($this->step->id, 'transition', 'previousIndexIsConcluded() returned: '.($result ? 'TRUE' : 'FALSE'));
            Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
            return $result;
        }
        Step::log($this->step->id, 'transition', '✗ NO - not orphan');

        /**
         * Child step:
         * ----------------------------
         * Belongs to a child block (has a parent).
         * Dispatch if parent has started (Running or Completed)
         * and previous index in same block is concluded.
         */
        Step::log($this->step->id, 'transition', '[CHECK 4/4] Is CHILD step?');
        if ($this->isChild()) {
            Step::log($this->step->id, 'transition', '✓ YES - step is CHILD (has parent)');
            Step::log($this->step->id, 'transition', 'Getting parent step...');
            $parent = $this->getParentStep();

            if (! $parent) {
                Step::log($this->step->id, 'transition', '✗ Parent step NOT found - returning FALSE');
                Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
                return false;
            }
            Step::log($this->step->id, 'transition', '✓ Parent found: Step #'.$parent->id.' | state: '.$parent->state);

            $parentState = get_class($parent->state);
            Step::log($this->step->id, 'transition', 'Checking if parent is Running or Completed...');
            if (! in_array($parentState, [Running::class, Completed::class], true)) {
                Step::log($this->step->id, 'transition', '✗ Parent is NOT Running/Completed - returning FALSE');
                Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
                return false;
            }
            Step::log($this->step->id, 'transition', '✓ Parent is Running or Completed');

            Step::log($this->step->id, 'transition', 'Checking if previous index is concluded...');
            $result = $this->previousIndexIsConcluded();
            Step::log($this->step->id, 'transition', 'previousIndexIsConcluded() returned: '.($result ? 'TRUE' : 'FALSE'));
            Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
            return $result;
        }
        Step::log($this->step->id, 'transition', '✗ NO - not child');

        /**
         * Parent step:
         * ----------------------------
         * Spawns a child block (has child_block_uuid).
         * Dispatch if previous index is concluded.
         * Children may not exist yet at this point.
         */
        Step::log($this->step->id, 'transition', '[CHECK 5/5] Is PARENT step?');
        if ($this->isParent()) {
            Step::log($this->step->id, 'transition', '✓ YES - step is PARENT (has child_block_uuid: '.$this->step->child_block_uuid.')');
            Step::log($this->step->id, 'transition', 'Checking if previous index is concluded...');
            $result = $this->previousIndexIsConcluded();
            Step::log($this->step->id, 'transition', 'previousIndexIsConcluded() returned: '.($result ? 'TRUE' : 'FALSE'));
            Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
            return $result;
        }
        Step::log($this->step->id, 'transition', '✗ NO - not parent');

        /**
         * Fallback:
         * ----------------------------
         * Not orphan, not child, not parent.
         * Should never happen, deny dispatch.
         */
        Step::log($this->step->id, 'transition', '⚠️ FALLBACK: Step is neither orphan, child, nor parent - returning FALSE');
        Step::log($this->step->id, 'transition', 'This should never happen - investigate!');
        Step::log($this->step->id, 'transition', '═══════════════════════════════════════════════════════════');
        return false;
    }

    public function handle(): Step
    {
        return $this->apply();
    }

    public function apply(): Step
    {
        Step::log($this->step->id, 'transition', '╔═══════════════════════════════════════════════════════════╗');
        Step::log($this->step->id, 'transition', '║      PendingToDispatched::apply() - STATE CHANGE         ║');
        Step::log($this->step->id, 'transition', '╚═══════════════════════════════════════════════════════════╝');
        Step::log($this->step->id, 'transition', 'BEFORE: state = '.$this->step->state);

        Step::log($this->step->id, 'transition', 'Creating new Dispatched state object...');
        $this->step->state = new Dispatched($this->step); // Transition to Dispatched state
        Step::log($this->step->id, 'transition', 'AFTER: state = '.$this->step->state);

        // If we have a tick id, let's update the step with it.
        if (cache()->has('current_tick_id')) {
            $tickId = cache('current_tick_id');
            Step::log($this->step->id, 'transition', 'Setting tick_id from cache: '.$tickId);
            $this->step->tick_id = $tickId;
        } else {
            Step::log($this->step->id, 'transition', 'No tick_id in cache - not setting tick_id');
        }

        Step::log($this->step->id, 'transition', 'Calling save()...');
        $this->step->save();
        Step::log($this->step->id, 'transition', 'save() completed - state transition persisted');
        Step::log($this->step->id, 'transition', '✓ PendingToDispatched::apply() completed successfully');
        Step::log($this->step->id, 'transition', '╚═══════════════════════════════════════════════════════════╝');

        return $this->step;
    }

    /**
     * Get parent step from cache or database.
     * Replicates Step::parentStep() logic but uses cache when available.
     */
    private function getParentStep(): ?Step
    {
        if ($this->stepsCache !== null) {
            return $this->stepsCache['parents_by_child_block'][$this->step->block_uuid] ?? null;
        }

        return Step::where('child_block_uuid', $this->step->block_uuid)->first();
    }

    /**
     * Check if previous index is concluded from cache or database.
     * Replicates Step::previousIndexIsConcluded() logic but uses cache when available.
     */
    private function previousIndexIsConcluded(): bool
    {
        if ($this->step->index === 1) {
            return true;
        }

        if ($this->step->index === null && $this->isChild() && $this->parentIsRunning()) {
            return true;
        }

        if ($this->stepsCache !== null) {
            return $this->previousIndexIsConcludedFromCache();
        }

        return $this->previousIndexIsConcludedFromDatabase();
    }

    /**
     * Check if previous index is concluded using the cache.
     */
    private function previousIndexIsConcludedFromCache(): bool
    {
        $hasPendingResolveException = isset($this->stepsCache['pending_resolve_exceptions'][$this->step->block_uuid]);

        $key = $this->step->block_uuid.'_'.($this->step->index - 1);
        $previousSteps = $this->stepsCache['steps_by_block_and_index'][$key] ?? collect([]);

        if ($hasPendingResolveException) {
            $previousSteps = $previousSteps->where('type', 'resolve-exception');
        } else {
            $previousSteps = $previousSteps->where('type', 'default');
        }

        if ($previousSteps->isEmpty()) {
            return false;
        }

        return $previousSteps->every(
            fn ($step) => in_array(get_class($step->state), Step::concludedStepStates(), true)
        );
    }

    /**
     * Check if previous index is concluded using database queries (fallback).
     */
    private function previousIndexIsConcludedFromDatabase(): bool
    {
        $hasPendingResolveException = Step::where('block_uuid', $this->step->block_uuid)
            ->where('type', 'resolve-exception')
            ->where('state', Pending::class)
            ->exists();

        $query = Step::where('block_uuid', $this->step->block_uuid)
            ->where('index', $this->step->index - 1);

        if ($hasPendingResolveException) {
            $query->where('type', 'resolve-exception');
        } else {
            $query->where('type', 'default');
        }

        $previousSteps = $query->get();

        if ($previousSteps->isEmpty()) {
            return false;
        }

        return $previousSteps->every(
            fn ($step) => in_array(get_class($step->state), Step::concludedStepStates(), true)
        );
    }

    /**
     * Check if parent is running (helper for previousIndexIsConcluded).
     */
    private function parentIsRunning(): bool
    {
        $parent = $this->getParentStep();

        return $parent && $parent->state->equals(Running::class);
    }

    /**
     * Check if step is a child (has a parent) using cache when available.
     * Replicates Step::isChild() logic.
     */
    private function isChild(): bool
    {
        if ($this->stepsCache !== null) {
            return isset($this->stepsCache['parents_by_child_block'][$this->step->block_uuid]);
        }

        return Step::where('child_block_uuid', $this->step->block_uuid)->exists();
    }

    /**
     * Check if step is a parent (has children) using cache when available.
     * Replicates Step::isParent() logic.
     */
    private function isParent(): bool
    {
        return ! is_null($this->step->child_block_uuid);
    }

    /**
     * Check if step is an orphan (no parent, no children) using cache when available.
     * Replicates Step::isOrphan() logic.
     */
    private function isOrphan(): bool
    {
        return is_null($this->step->child_block_uuid) && is_null($this->getParentStep());
    }
}
