<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Skipped extends StepStatus
{
    public function value(): string
    {
        return 'skipped';
    }
}
