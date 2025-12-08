<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;

trait HasAccessors
{
    /**
     * Accessor for the `parsed_trading_pair` attribute.
     * Returns a unified trading pair string from token and quote.
     * Token and quote are now stored directly on exchange_symbols.
     */
    public function getParsedTradingPairAttribute(): ?string
    {
        $apiSystem = ApiSystem::find($this->api_system_id);
        if (! $apiSystem) {
            return null;
        }

        $dataMapper = new ApiDataMapperProxy($apiSystem->canonical);

        return $dataMapper->baseWithQuote($this->token, $this->quote);
    }

    public function getParsedTradingPairExtendedAttribute(): ?string
    {
        return "{$this->parsed_trading_pair}/{$this->indicators_timeframe}/{$this->direction}";
    }

    // ->parsed_trading_pair_with_exchange
    public function getParsedTradingPairWithExchangeAttribute(): ?string
    {
        return "{$this->parsed_trading_pair}@{$this->apiSystem->name}";
    }
}
