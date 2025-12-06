<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);
        $properties->set('options.category', 'linear');
        $properties->set('options.settleCoin', 'USDT');

        return $properties;
    }

    /**
     * Resolves Bybit open orders response.
     *
     * Bybit V5 response structure:
     * { result: { list: [...orders] } }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return $data['result']['list'] ?? [];
    }
}
