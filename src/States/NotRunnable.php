<?php

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

class NotRunnable extends StepStatus
{
    public const VALUE = 'not-runnable';

    public function value(): string
    {
        return self::VALUE;
    }
}
