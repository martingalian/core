<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Failed extends StepStatus
{
    public function value(): string
    {
        return 'failed';
    }
}
