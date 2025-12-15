<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolves BitGet cancel all orders response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "successList": [
     *             {"orderId": "123", "clientOid": "xxx"},
     *             {"orderId": "456", "clientOid": "yyy"}
     *         ],
     *         "failureList": []
     *     }
     * }
     */
    public function resolveCancelOrdersResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return $body['data'] ?? [];
    }
}
