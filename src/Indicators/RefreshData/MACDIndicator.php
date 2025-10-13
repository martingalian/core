<?php

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;

class MACDIndicator extends BaseIndicator
{
    public string $endpoint = 'macd';

    public string $type = 'direction';

    public function conclusion()
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $macd = $this->data['valueMACD'] ?? null;
        $macdSignal = $this->data['valueMACDSignal'] ?? null;
        $macdHist = $this->data['valueMACDHist'] ?? null;

        if ($macd && $macdSignal && $macdHist && count($macd) > 1 && count($macdSignal) > 1 && count($macdHist) > 1) {
            if ($macd[1] > $macdSignal[1] && $macdHist[1] > 0) {
                return 'LONG';
            }

            if ($macd[1] < $macdSignal[1] && $macdHist[1] < 0) {
                return 'SHORT';
            }
        }

        return null;
    }
}
