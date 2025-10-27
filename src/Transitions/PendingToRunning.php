<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToRunning extends Transition
{
    public function __construct(
        private Step $step
    ) {
    }

    public function handle(): Step
    {
        $this->step->state = new Running($this->step);
        $this->step->save();

        return $this->step;
    }
}
