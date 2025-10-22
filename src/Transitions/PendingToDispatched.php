<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Dispatched;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToDispatched extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        if (! $this->step->state instanceof Pending) {
            info_if("[PendingToDispatched.canTransition] Step ID {$this->step->id} is not in Pending state, transition denied");
            /*
            $this->step->logApplicationEvent(
                "Step ID {$this->step->id} is not in Pending state, transition denied",
                self::class,
                __FUNCTION__
            );
            */

            return false;
        }

        /**
         * Check if the step is a 'resolve-exception' without index.
         * The logic to put this resolve-exception into pending state is made
         * as a passive decision, no worries.
         */
        if ($this->step->type === 'resolve-exception' && is_null($this->step->index)) {
            return true;
        }

        // Check if the step is a 'resolve-exception' with an index.
        if ($this->step->type === 'resolve-exception' && ! is_null($this->step->index)) {
            // If the index is 1, there's no previous step to check, so allow the transition
            if ($this->step->index === 1) {
                return true;
            }

            // Ensure that the previous step (index - 1) is 'resolve-exception' and completed.
            $previousSteps = Step::where('block_uuid', $this->step->block_uuid)
                ->where('index', $this->step->index - 1)
                ->where('type', 'resolve-exception')
                ->get();

            // If the previous step exists and is completed, allow transition
            if ($previousSteps->isNotEmpty() && in_array(get_class($previousSteps->first()->state), Step::concludedStepStates(), true)) {
                return true;
            }

            // If previous step is not completed or doesn't exist, deny transition
            info_if("[PendingToDispatched.canTransition] Previous 'resolve-exception' step for Step ID {$this->step->id} is not completed, transition denied");

            /*
            $this->step->logApplicationEvent(
                "Previous 'resolve-exception' step for Step ID {$this->step->id} is not completed, transition denied",
                self::class,
                __FUNCTION__
            );
            */

            return false;
        }

        /**
         * Orphan step:
         * ----------------------------
         * No parent and no child block.
         * If index is null → dispatch immediately.
         * Else → dispatch only if previous index is concluded.
         */
        if ($this->step->isOrphan()) {
            if (is_null($this->step->index)) {
                info_if("[PendingToDispatched.canTransition] Orphan step with null index, dispatching immediately for Step ID {$this->step->id}");

                /*
                $this->step->logApplicationEvent(
                    "Orphan step with null index, dispatching immediately for Step ID {$this->step->id}",
                    self::class,
                    __FUNCTION__
                );
                */

                return true;
            }

            $canDispatch = $this->step->previousIndexIsConcluded();
            info_if('[PendingToDispatched.canTransition] Orphan step, previous index concluded: '.($canDispatch ? 'Yes' : 'No')." for Step ID {$this->step->id}");

            /*
            $this->step->logApplicationEvent(
                'Orphan step, previous index concluded: '.($canDispatch ? 'Yes' : 'No')." for Step ID {$this->step->id}",
                self::class,
                __FUNCTION__
            );
            */

            return $canDispatch;
        }

        /**
         * Child step:
         * ----------------------------
         * Belongs to a child block (has a parent).
         * Dispatch if parent has started (Running or Completed)
         * and previous index in same block is concluded.
         */
        if ($this->step->isChild()) {
            $parent = $this->step->parentStep();

            if (! $parent) {
                info_if("[PendingToDispatched.canTransition] No parent found for Step ID {$this->step->id}, transition denied");

                /*
                $this->step->logApplicationEvent(
                    "No parent found for Step ID {$this->step->id}, transition denied",
                    self::class,
                    __FUNCTION__
                );
                */

                return false;
            }

            $parentState = get_class($parent->state);
            if (! in_array($parentState, [Running::class, Completed::class], true)) {
                info_if("[PendingToDispatched.canTransition] Parent Step ID {$parent->id} is not Running or Completed, transition denied for Step ID {$this->step->id}");

                /*
                $this->step->logApplicationEvent(
                    "Parent Step ID {$parent->id} is not Running or Completed, transition denied for Step ID {$this->step->id}",
                    self::class,
                    __FUNCTION__
                );
                */

                return false;
            }

            $canDispatch = $this->step->previousIndexIsConcluded();
            info_if('[PendingToDispatched.canTransition] Child step, previous index concluded: '.($canDispatch ? 'Yes' : 'No')." for Step ID {$this->step->id}");

            /*
            $this->step->logApplicationEvent(
                'Child step, previous index concluded: '.($canDispatch ? 'Yes' : 'No')." for Step ID {$this->step->id}",
                self::class,
                __FUNCTION__
            );
            */

            return $canDispatch;
        }

        /**
         * Parent step:
         * ----------------------------
         * Spawns a child block (has child_block_uuid).
         * Dispatch if previous index is concluded.
         * Children may not exist yet at this point.
         */
        if ($this->step->isParent()) {
            $canDispatch = $this->step->previousIndexIsConcluded();
            info_if('[PendingToDispatched.canTransition] Parent step, previous index concluded: '.($canDispatch ? 'Yes' : 'No')." for Step ID {$this->step->id}");

            /*
            $this->step->logApplicationEvent(
                '[PendingToDispatched.canTransition] Parent step, previous index concluded: '.($canDispatch ? 'Yes' : 'No')." for Step ID {$this->step->id}",
                self::class,
                __FUNCTION__
            );
            */

            return $canDispatch;
        }

        /**
         * Fallback:
         * ----------------------------
         * Not orphan, not child, not parent.
         * Should never happen, deny dispatch.
         */
        // info_if("[PendingToDispatched.canTransition] Step ID {$this->step->id} is not orphan, child, or parent, transition denied");

        return false;
    }

    public function apply(): Step
    {
        $this->step->state = new Dispatched($this->step); // Transition to Dispatched state

        // If we have a tick id, let's update the step with it.
        if (cache()->has('current_tick_id')) {
            $this->step->tick_id = cache('current_tick_id');
        }

        $this->step->save();

        // Log after the state is saved
        info_if("[PendingToDispatched.apply] Step ID {$this->step->id} successfully transitioned to Dispatched");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Dispatched',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
