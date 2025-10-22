<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

final class EMAIndicator extends BaseIndicator
{
    public string $endpoint = 'ema';

    public string $type = 'direction';

    public function conclusion()
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $emaValues = $this->data['value'] ?? null;

        if ($emaValues && count($emaValues) > 1) {
            return $emaValues[1] > $emaValues[0] ? 'LONG' : 'SHORT';
        }

        return null;
    }
}
