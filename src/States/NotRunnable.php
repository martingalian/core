<?php

declare(strict_types=1);

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

final class NotRunnable extends StepStatus
{
    public const VALUE = 'not-runnable';

    public function value(): string
    {
        return self::VALUE;
    }
}
