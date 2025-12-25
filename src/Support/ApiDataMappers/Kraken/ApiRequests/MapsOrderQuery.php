<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderQuery
{
    /**
     * Prepare properties for querying a single order on Kraken Futures.
     *
     * Note: Kraken doesn't have a direct single-order query endpoint.
     * We query open orders and filter by orderId.
     */
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    /**
     * Resolve the order query response from Kraken.
     *
     * This method expects the response from openorders endpoint,
     * filtered to find the specific order.
     *
     * Kraken open order structure:
     * {
     *     "orderId": "abc123",
     *     "cliOrdId": "client-id",
     *     "type": "lmt",
     *     "symbol": "PF_XBTUSD",
     *     "side": "buy",
     *     "quantity": 1000,
     *     "filledSize": 0,
     *     "limitPrice": 29000.0,
     *     "reduceOnly": false,
     *     "timestamp": "2024-01-15T10:30:00.000Z"
     * }
     */
    public function resolveOrderQueryResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $orders = $data['openOrders'] ?? [];

        // Find the order (should be pre-filtered, but handle as array)
        $result = is_array($orders) && ! isset($orders['orderId'])
            ? ($orders[0] ?? [])
            : $orders;

        if (empty($result)) {
            return [
                'order_id' => null,
                'status' => 'NOT_FOUND',
                '_raw' => $data,
            ];
        }

        $status = $this->normalizeKrakenOrderStatus($result);

        return [
            'order_id' => $result['orderId'] ?? null,
            'symbol' => isset($result['symbol']) ? $this->identifyBaseAndQuote($result['symbol']) : null,
            'status' => $status,
            'price' => $this->computeKrakenOrderQueryPrice($result),
            '_price' => $this->computeKrakenOrderQueryPrice($result),
            'quantity' => $result['filledSize'] ?? $result['quantity'] ?? 0,
            'type' => $result['type'] ?? null,
            '_orderType' => $this->canonicalOrderType($result),
            'side' => isset($result['side']) ? mb_strtoupper($result['side']) : null,
            '_raw' => $result,
        ];
    }

    /**
     * Compute the effective display price for a Kraken order.
     */
    private function computeKrakenOrderQueryPrice(array $order): string
    {
        $type = $order['type'] ?? '';
        $limitPrice = (string) ($order['limitPrice'] ?? '0');
        $stopPrice = (string) ($order['stopPrice'] ?? '0');

        return match ($type) {
            'lmt', 'post', 'ioc' => $limitPrice,
            'mkt' => '0',
            'stp', 'take_profit' => (float) $stopPrice > 0 ? $stopPrice : $limitPrice,
            default => (float) $limitPrice > 0 ? $limitPrice : $stopPrice,
        };
    }

    /**
     * Normalize Kraken order status to canonical format.
     */
    private function normalizeKrakenOrderStatus(array $order): string
    {
        // If order is in openOrders, it's either NEW or PARTIALLY_FILLED
        $filledSize = (float) ($order['filledSize'] ?? 0);
        $quantity = (float) ($order['quantity'] ?? 0);

        if ($filledSize > 0 && $filledSize < $quantity) {
            return 'PARTIALLY_FILLED';
        }

        if ($filledSize >= $quantity && $quantity > 0) {
            return 'FILLED';
        }

        return 'NEW';
    }
}
