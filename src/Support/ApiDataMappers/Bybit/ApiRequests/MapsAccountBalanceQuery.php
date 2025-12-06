<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);
        $properties->set('options.accountType', 'UNIFIED');

        return $properties;
    }

    /**
     * Resolves Bybit wallet balance response.
     * Returns asset => balance pairs, filtering out zero balances.
     *
     * Bybit V5 response structure:
     * { result: { list: [{ coin: [{ coin: "USDT", walletBalance: "1000.00" }, ...] }] } }
     */
    public function resolveGetBalanceResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        if (! isset($data['result']['list'][0]['coin'])) {
            return [];
        }

        $coins = $data['result']['list'][0]['coin'];

        return collect($coins)
            ->filter(fn ($item) => (float) ($item['walletBalance'] ?? 0) !== 0.0)
            ->mapWithKeys(fn ($item) => [$item['coin'] => $item['walletBalance']])
            ->toArray();
    }
}
