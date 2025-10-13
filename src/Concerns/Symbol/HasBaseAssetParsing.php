<?php

namespace Martingalian\Core\Concerns\Symbol;

use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\Symbol;

trait HasBaseAssetParsing
{
    /**
     * Returns the right Symbol model, scoped by Api System token.
     *
     * e.g: Symbol::getByExchangeBaseAsset('1000BONK', 1 as Binance) will return Symbol BONK.
     * If none found, returns the Symbol with that token as base asset.
     */
    public static function getByExchangeBaseAsset(string $baseAsset, ApiSystem $apiSystem): ?Symbol
    {
        $tradingPair = BaseAssetMapper::where('api_system_id', $apiSystem->id)
            ->where('exchange_token', $baseAsset)
            ->first();

        if ($tradingPair) {
            return Symbol::firstWhere('token', $tradingPair->symbol_token);
        } else {
            return Symbol::firstWhere('token', $baseAsset);
        }
    }
}
