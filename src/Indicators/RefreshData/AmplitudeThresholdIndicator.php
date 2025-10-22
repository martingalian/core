<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

/**
 * Verifies if there was a specific growth/drop price percentage in the respective
 * candle timeframe length. This is an indicator that will cancel tokens that in
 * case of an extreme fluctuation on a token price (e.g: > 30% in 24h for instance).
 */
final class AmplitudeThresholdIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public string $type = 'validation';

    protected float $amplitude = 20; // 20% default threshold

    public function conclusion()
    {
        return $this->isValid();
    }

    public function isValid(): bool
    {
        if (empty($this->data['low']) ||
            empty($this->data['high']) ||
            count($this->data['low']) < 2 ||
            count($this->data['high']) < 2
        ) {
            return false; // Not enough data to analyze
        }

        // Identify the oldest (first) and newest (last) candle indices
        $oldestIndex = 0;
        $newestIndex = count($this->data['low']) - 1;

        // Extract relevant values
        $oldestLow = $this->data['low'][$oldestIndex];
        $oldestHigh = $this->data['high'][$oldestIndex];
        $newestLow = $this->data['low'][$newestIndex];
        $newestHigh = $this->data['high'][$newestIndex];

        // Calculate the amplitude (percentage change from oldest LOW to newest HIGH)
        $lowPrice = min($oldestLow, $newestLow);
        $highPrice = max($oldestHigh, $newestHigh);

        $priceDifference = $highPrice - $lowPrice;
        $percentageAmplitude = ($priceDifference / $lowPrice) * 100;

        if ($percentageAmplitude > $this->amplitude) {
            return false;
        }

        return true;
    }
}
