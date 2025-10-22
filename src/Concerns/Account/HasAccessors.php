<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Account;

use ArrayAccess;
use Illuminate\Support\Arr;

trait HasAccessors
{
    /**
     * Aggregate credentials from concrete columns.
     *
     * @return array<string,string|null>
     */
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

    /**
     * Fan-in mutator: assign a full credentials array and populate the real columns.
     * This lets you do: $account->all_credentials = $someArray;
     *
     * Important:
     * - This uses setAttribute() so your 'encrypted' casts run automatically.
     * - Absent keys are ignored (no overwrites); pass only the keys you intend to set.
     *
     * @param  array|ArrayAccess  $value
     */
    public function setAllCredentialsAttribute($value): void
    {
        $keys = [
            'binance_api_key',
            'binance_api_secret',
            'bybit_api_key',
            'bybit_api_secret',
            'coinmarketcap_api_key',
            'taapi_secret',
        ];

        foreach ($keys as $key) {
            if (Arr::has($value, $key)) {
                $this->setAttribute($key, Arr::get($value, $key));
            }
        }
    }
}
