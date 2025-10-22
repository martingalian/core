<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\History;

use Martingalian\Core\Abstracts\BaseIndicator;

final class CandleIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public string $type = 'value';

    public function conclusion()
    {
        return $this->data;
    }
}
