<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * [
        "BNB" => "0.00000002",
        "BTC" => "155.432",
        ...
        ]

        Quotes with zero are discarded.
     */
    public function resolveGetBalanceResponse(Response $response): array
    {
        return collect(json_decode($response->getBody(), true))
            ->filter(fn ($item) => (float) $item['balance'] !== 0.0)
            ->mapWithKeys(fn ($item) => [$item['asset'] => $item['balance']])
            ->toArray();
    }
}
