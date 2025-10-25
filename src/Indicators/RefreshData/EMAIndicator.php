<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;
use Martingalian\Core\Contracts\Indicators\DirectionIndicator;

final class EMAIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'ema';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        return $this->compareTrendFromValues();
    }
}
