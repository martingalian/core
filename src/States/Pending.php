<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Pending extends StepStatus
{
    public const VALUE = 'pending';

    public function value(): string
    {
        return self::VALUE;
    }
}
