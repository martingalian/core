<?php

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

class RunningToRunning extends Transition
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
        // Transition from Running to Running state
        $this->step->state = new Running($this->step);
        $this->step->save(); // Save the transition

        info_if("[RunningToRunning.handle] Step ID {$this->step->id} successfully transitioned to Running");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Skipped',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
