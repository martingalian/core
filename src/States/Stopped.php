<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Stopped extends StepStatus
{
    public function value(): string
    {
        return 'stopped';
    }
}
