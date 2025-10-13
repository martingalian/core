<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Completed extends StepStatus
{
    public function value(): string
    {
        return 'completed';
    }
}
