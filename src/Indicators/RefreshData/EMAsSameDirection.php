<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;
use Martingalian\Core\Contracts\Indicators\DirectionIndicator;

final class EMAsSameDirection extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'emas-same-direction';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    /**
     * Confirms if all EMAs are either going up or going down.
     * Concludes LONG if all are going up, SHORT if all are going down.
     * Returns null if EMAs provide mixed signals or are invalid.
     */
    public function direction(): ?string
    {
        // Collect only the EMA indicators from the data
        $emas = collect($this->data)
            ->filter(static fn ($indicator, $key) => str_starts_with($key, 'ema-'));

        if ($emas->isEmpty()) {
            // No valid EMAs to analyze
            return null;
        }

        $trend = null;

        foreach ($emas as $ema) {
            if (! array_key_exists('value', $ema['result'])) {
                return null;
            }

            $values = $ema['result']['value'];

            if (! isset($values[1]) || ! isset($values[0])) {
                // Invalid or missing EMA values
                return null;
            }

            // Determine the current trend for this EMA
            $currentTrend = $values[1] >= $values[0] ? 'LONG' : 'SHORT';

            if (is_null($trend)) {
                $trend = $currentTrend; // Initialize the trend
            } elseif ($trend !== $currentTrend) {
                // If trends are inconsistent, return null
                return null;
            }
        }

        return $trend; // Return the determined trend (LONG or SHORT)
    }
}
