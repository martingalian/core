<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves Kraken open orders response.
     *
     * Kraken Futures response structure:
     * {
     *     "result": "success",
     *     "openOrders": [
     *         {
     *             "orderId": "abc123",
     *             "cliOrdId": null,
     *             "type": "lmt",
     *             "symbol": "PF_XBTUSD",
     *             "side": "buy",
     *             "quantity": 1000,
     *             "filledSize": 0,
     *             "limitPrice": 29000.0,
     *             "reduceOnly": false,
     *             "timestamp": "2024-01-15T10:30:00.000Z",
     *             "lastUpdateTimestamp": "2024-01-15T10:30:00.000Z"
     *         }
     *     ]
     * }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return $data['openOrders'] ?? [];
    }
}
