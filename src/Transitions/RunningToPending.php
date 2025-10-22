<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Pending;
use Spatie\ModelStates\Transition;

final class RunningToPending extends Transition
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
        // Increment retry count
        $this->step->increment('retries');
        info_if("[RunningToPending.handle] - Step ID {$this->step->id} retries incremented to ".$this->step->retries);

        /*
        $this->step->logApplicationEvent(
            'Step retries incremented to '.$this->step->retries,
            self::class,
            __FUNCTION__
        );
        */

        $this->step->started_at = null;
        $this->step->completed_at = null;
        $this->step->duration = 0;

        // Reset the state to Pending
        $this->step->state = new Pending($this->step);
        $this->step->save();

        info_if("[RunningToPending.handle] Step ID {$this->step->id} transitioned back to Pending (retry) (timers reset).");

        /*
        $this->step->logApplicationEvent(
            'Step transitioned back to Pending (retry) (timers reset).',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
