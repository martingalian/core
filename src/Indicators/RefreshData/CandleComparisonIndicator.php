<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

final class CandleComparisonIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public string $type = 'value';

    public function conclusion()
    {
        //
    }
}
