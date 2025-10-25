<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

final class CandleComparisonIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public function conclusion(): ?string
    {
        // This indicator does not provide a conclusion yet
        return null;
    }
}
