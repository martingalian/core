<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ExchangeSymbol;

final class RemoveIndicatorDataJob extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function compute()
    {
        $this->exchangeSymbol->updateSaving([
            'direction' => null,
            'indicators_values' => null,
            'indicators_timeframe' => null,
            'indicators_synced_at' => null,
            'is_active' => false,
        ]);

        $this->exchangeSymbol->logApplicationEvent(
            'Indicator data was removed, possibly due to an exception during indicator assessment',
            self::class,
            __FUNCTION__
        );

        return ['response' => "Indicator data from {$this->exchangeSymbol->parsed_trading_pair} was removed"];
    }
}
