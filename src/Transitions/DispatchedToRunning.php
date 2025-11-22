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
        log_step($this->step->id, "[DispatchedToRunning.handle] Transitioning to Running");

        $this->step->hostname = gethostname();
        $this->step->started_at = now();
        $this->step->is_throttled = false; // Step is no longer waiting due to throttling
        $this->step->state = new Running($this->step);
        $this->step->save();

        log_step($this->step->id, "[DispatchedToRunning.handle] SUCCESS - Transitioned to Running | hostname: {$this->step->hostname}");

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
