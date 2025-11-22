<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToRunning extends Transition
{
    public function __construct(
        private Step $step
    ) {}

    public function handle(): Step
    {
        log_step($this->step->id, "[PendingToRunning.handle] Transitioning to Running");

        $this->step->state = new Running($this->step);
        $this->step->save();

        log_step($this->step->id, "[PendingToRunning.handle] SUCCESS - Transitioned to Running");

        return $this->step;
    }
}
