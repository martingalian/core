<?php

declare(strict_types=1);

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

final class Pending extends StepStatus
{
    public const VALUE = 'pending';

    public function value(): string
    {
        return self::VALUE;
    }
}
