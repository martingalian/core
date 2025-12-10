<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // BitGet V2 requires productType for futures
        $properties->set('options.productType', 'USDT-FUTURES');

        return $properties;
    }

    /**
     * Resolves BitGet open orders response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": {
     *         "entrustedList": [
     *             {
     *                 "symbol": "BTCUSDT",
     *                 "size": "0.001",
     *                 "orderId": "1234567890",
     *                 "clientOid": "xxx",
     *                 "filledQty": "0",
     *                 "priceAvg": "0",
     *                 "fee": "0",
     *                 "price": "40000",
     *                 "state": "new",
     *                 "side": "buy",
     *                 "force": "gtc",
     *                 "totalProfits": "0",
     *                 "posSide": "long",
     *                 "marginCoin": "USDT",
     *                 "presetStopSurplusPrice": "",
     *                 "presetStopLossPrice": "",
     *                 "quoteSize": "40",
     *                 "orderType": "limit",
     *                 "leverage": "10",
     *                 "marginMode": "crossed",
     *                 "reduceOnly": "NO",
     *                 "enterPointSource": "API",
     *                 "tradeSide": "open",
     *                 "cTime": "1627116936176",
     *                 "uTime": "1627116936176"
     *             }
     *         ],
     *         "endId": "1234567890"
     *     }
     * }
     */
    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        // BitGet wraps orders in data.entrustedList structure
        return $data['data']['entrustedList'] ?? [];
    }
}
