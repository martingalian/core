<?php

declare(strict_types=1);

namespace Martingalian\Core\States;

use Martingalian\Core\Abstracts\StepStatus;

final class Stopped extends StepStatus
{
    public function value(): string
    {
        return 'stopped';
    }
}
