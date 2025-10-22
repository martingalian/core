<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Skipped;
use Spatie\ModelStates\Transition;

final class PendingToSkipped extends Transition
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
        // Transition to Skipped state
        $this->step->state = new Skipped($this->step);
        $this->step->save(); // Save the transition

        info_if("[RunningToSkipped.handle] Step ID {$this->step->id} successfully transitioned to Skipped");

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
