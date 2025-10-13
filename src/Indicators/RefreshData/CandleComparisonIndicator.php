<?php

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

class CandleComparisonIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public string $type = 'value';

    public function conclusion()
    {
        //
    }
}
