<?php

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasAccessors
{
    /**
     * Accessor for the `parsed_trading_pair` attribute.
     * Returns a unified trading pair string from exchange and quote tokens.
     * This is a derived value, not persisted in the database.
     */
    public function getParsedTradingPairAttribute(): ?string
    {
        $apiSystem = ApiSystem::find($this->api_system_id);
        if (! $apiSystem) {
            return null;
        }

        $dataMapper = new ApiDataMapperProxy($apiSystem->canonical);

        $exchangeBaseAsset = BaseAssetMapper::where('symbol_token', $this->symbol->token)
            ->where('api_system_id', $this->api_system_id)
            ->first();

        $baseToken = $exchangeBaseAsset
        ? $exchangeBaseAsset->exchange_token
        : $this->symbol->token;

        return $dataMapper->baseWithQuote($baseToken, $this->quote->canonical);
    }

    public function getParsedTradingPairExtendedAttribute(): ?string
    {
        return "{$this->parsed_trading_pair}/{$this->indicators_timeframe}/{$this->direction}";
    }
}
