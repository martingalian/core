<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // Filter for active orders only
        $properties->set('options.status', 'active');

        return $properties;
    }

    /**
     * Resolves KuCoin open orders response.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "currentPage": 1,
     *         "pageSize": 100,
     *         "totalNum": 1,
     *         "totalPage": 1,
     *         "items": [
     *             {
     *                 "id": "5cdfc138b21023a909e5ad55",
     *                 "symbol": "XBTUSDTM",
     *                 "type": "limit",
     *                 "side": "buy",
     *                 "price": "3600",
     *                 "size": 20,
     *                 "value": "56.568",
     *                 "dealValue": "0",
     *                 "dealSize": 0,
     *                 "stp": "",
     *                 "stop": "",
     *                 "stopPriceType": "",
     *                 "stopTriggered": false,
     *                 "stopPrice": null,
     *                 "timeInForce": "GTC",
     *                 "postOnly": false,
     *                 "hidden": false,
     *                 "iceberg": false,
     *                 "leverage": "20",
     *                 "forceHold": false,
     *                 "closeOrder": false,
     *                 "visibleSize": null,
     *                 "clientOid": "5ce24c16b210233c36ee321d",
     *                 "remark": null,
     *                 "tags": null,
     *                 "isActive": true,
     *                 "cancelExist": false,
     *                 "createdAt": 1558167872000,
     *                 "updatedAt": 1558167872000,
     *                 "endAt": null,
     *                 "orderTime": 1558167872000000000,
     *                 "settleCurrency": "USDT",
     *                 "status": "open",
     *                 "filledSize": 0,
     *                 "filledValue": "0",
     *                 "reduceOnly": false
     *             }
     *         ]
     *     }
     * }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        // KuCoin wraps orders in a paginated data.items structure
        return $data['data']['items'] ?? [];
    }
}
