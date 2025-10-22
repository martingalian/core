<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Failed;
use Spatie\ModelStates\Transition;

final class RunningToFailed extends Transition
{
    private Step $step;

    public function __construct(Step $step)
    {
        $this->step = $step;
    }

    public function canTransition(): bool
    {
        // Only allow transition if the current state is Running
        if (! ($this->step->state instanceof \Martingalian\Core\States\Running)) {
            info_if("[RunningToFailed.canTransition] Step ID {$this->step->id} is not in Running state, transition denied");

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
        // Transition to Failed state
        $this->step->state = new Failed($this->step);
        $this->step->completed_at = now();

        $this->step->save(); // Save the step after state transition

        // Log after the state is saved
        info_if("[RunningToFailed.handle] Step ID {$this->step->id} successfully transitioned to Failed");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Failed',
            self::class,
            __FUNCTION__
        );
        */

        // Return the step for further processing if needed
        return $this->step;
    }
}
