<?php

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Running;
use Martingalian\Core\States\Stopped;
use Spatie\ModelStates\Transition;

class RunningToStopped extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        if (! ($this->step->state instanceof Running)) {
            info_if("[RunningToStopped.canTransition] Step ID {$this->step->id} is not in Running state, transition denied");

            /*
            $this->step->logApplicationEvent(
                'Step is not in Running state, transition denied',
                self::class,
                __FUNCTION__
            );
            */

            return false;
        }

        return true;
    }

    public function handle(): Step
    {
        // Transition to Stopped state
        $this->step->state = new Stopped($this->step);
        $this->step->save();

        // Log after the state is saved
        info_if("[RunningToStopped.handle] Step ID {$this->step->id} successfully transitioned to Stopped");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Stopped',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
