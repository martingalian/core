<?php

declare(strict_types=1);

namespace Martingalian\Core\Transitions;

use App\States\Running;
use Martingalian\Core\Models\Step;
use Spatie\ModelStates\Transition;

final class CompleteToRunning extends Transition
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
        $this->step->state = new Running($this->step);
        $this->step->save();

        return $this->step;
    }
}
