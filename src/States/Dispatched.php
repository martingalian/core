<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Dispatched extends StepStatus
{
    public function value(): string
    {
        return 'dispatched';
    }
}
