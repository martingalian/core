<?php

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Completed;
use Spatie\ModelStates\Transition;

class RunningToCompleted extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        return true;
    }

    public function handle(): Step
    {
        /**
         * Prevent parent steps from completing early.
         * Only allow transition if all child steps are concluded.
         */
        if ($this->step->isParent() && ! $this->step->childStepsAreConcluded()) {
            // Log if the step is a parent and the child steps are not concluded
            info_if("[RunningToCompleted.handle] Step ID {$this->step->id} is a parent, but child steps are not concluded, transition denied");

            /*
            $this->step->logApplicationEvent(
                'Step is a parent, but child steps are not concluded, transition denied',
                self::class,
                __FUNCTION__
            );
            */

            return $this->step;
        }

        $this->step->state = new Completed($this->step);
        $this->step->completed_at = now();
        $this->step->save();

        // Log after the state is saved
        info_if("[RunningToCompleted.handle] Step ID {$this->step->id} successfully transitioned to Completed");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Completed',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
