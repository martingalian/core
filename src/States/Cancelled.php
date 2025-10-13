<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class Cancelled extends StepStatus
{
    public const VALUE = 'cancelled';

    public function value(): string
    {
        return self::VALUE;
    }
}
