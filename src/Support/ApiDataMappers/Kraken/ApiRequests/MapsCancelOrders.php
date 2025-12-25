<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    /**
     * Prepare properties for canceling all orders on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/cancel-all-orders/
     */
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolve the cancel all orders response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "cancelStatus": {
     *         "cancelledOrders": [
     *             {"order_id": "abc123"},
     *             {"order_id": "def456"}
     *         ],
     *         "status": "cancelled"
     *     }
     * }
     */
    public function resolveCancelOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $cancelStatus = $data['cancelStatus'] ?? [];

        $cancelledOrders = $cancelStatus['cancelledOrders'] ?? [];

        return [
            'successList' => array_map(callback: static function (array $order): array {
                return [
                    'orderId' => $order['order_id'] ?? null,
                ];
            }, array: $cancelledOrders),
            'failureList' => [], // Kraken doesn't return failed orders in this endpoint
            '_raw' => $data,
        ];
    }
}
