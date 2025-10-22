<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

final class DispatchedToRunning extends Transition
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
        info_if('[DispatchedToRunning.handle] Step ID trying to transition to Running');

        $this->step->hostname = gethostname();
        $this->step->started_at = now();
        $this->step->state = new Running($this->step);
        $this->step->save();

        info_if("[DispatchedToRunning.handle] Step ID {$this->step->id} successfully transitioned to Running");

        /*
        $this->step->logApplicationEvent(
            'Step successfully transitioned to Running',
            self::class,
            __FUNCTION__
        );
        */

        return $this->step;
    }
}
