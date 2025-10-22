<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

final class OBVIndicator extends BaseIndicator
{
    public string $endpoint = 'obv';

    public string $type = 'direction';

    public function conclusion()
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $obvValues = $this->data['value'] ?? null;

        if ($obvValues && count($obvValues) > 1) {
            return $obvValues[1] > $obvValues[0] ? 'LONG' : 'SHORT';
        }

        return null;
    }
}
