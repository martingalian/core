<?php

namespace Martingalian\Core\Concerns\Martingalian;

trait HasAccessors
{
    public function getAllCredentialsAttribute(): array
    {
        return [
            'binance_api_key' => $this->binance_api_key,
            'binance_api_secret' => $this->binance_api_secret,
            'bybit_api_key' => $this->bybit_api_key,
            'bybit_api_secret' => $this->bybit_api_secret,
            'coinmarketcap_api_key' => $this->coinmarketcap_api_key,
            'taapi_secret' => $this->taapi_secret,
        ];
    }
}
