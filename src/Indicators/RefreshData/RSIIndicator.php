<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

final class RSIIndicator extends BaseIndicator
{
    public string $endpoint = 'rsi';

    public string $type = 'direction';

    public function conclusion()
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $rsiValues = $this->data['value'] ?? null;

        if ($rsiValues && count($rsiValues) > 1) {
            $previousRsi = $rsiValues[0];
            $currentRsi = $rsiValues[1];

            // If the current RSI is lower than the previous RSI, the trend is downward (SHORT).
            if ($currentRsi < $previousRsi) {
                return 'SHORT';
            }

            // If the current RSI is higher than the previous RSI, the trend is upward (LONG).
            if ($currentRsi > $previousRsi) {
                return 'LONG';
            }
        }

        // No trend detected or insufficient data
        return null;
    }
}
