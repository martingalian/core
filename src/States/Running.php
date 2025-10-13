<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Running extends StepStatus
{
    public function value(): string
    {
        return 'running';
    }
}
