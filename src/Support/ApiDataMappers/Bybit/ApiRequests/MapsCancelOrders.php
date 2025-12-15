<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    /**
     * Prepare properties for canceling all orders on Bybit.
     *
     * @see https://bybit-exchange.github.io/docs/v5/order/cancel-all
     */
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolve the cancel all orders response from Bybit.
     *
     * Bybit V5 response structure:
     * {
     *     "retCode": 0,
     *     "retMsg": "OK",
     *     "result": {
     *         "list": [
     *             { "orderId": "...", "orderLinkId": "..." }
     *         ],
     *         "success": "1"
     *     }
     * }
     */
    public function resolveCancelOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $result = $data['result'] ?? [];
        $list = $result['list'] ?? [];

        $cancelledOrderIds = array_map(function (array $order): string {
            return $order['orderId'] ?? '';
        }, $list);

        return [
            'cancelledOrderIds' => array_filter($cancelledOrderIds),
            'success' => ($result['success'] ?? '0') === '1',
            '_raw' => $data,
        ];
    }
}
