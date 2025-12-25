<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderCancel
{
    /**
     * Prepare properties for canceling an order on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/cancel-order/
     */
    public function prepareOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.order_id', (string) $order->exchange_order_id);

        // Optionally use client order ID if available
        if ($order->client_order_id) {
            $properties->set('options.cliOrdId', (string) $order->client_order_id);
        }

        return $properties;
    }

    /**
     * Resolve the cancel order response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "cancelStatus": {
     *         "order_id": "abc123",
     *         "status": "cancelled",
     *         "receivedTime": "2024-01-15T10:30:00.000Z"
     *     }
     * }
     */
    public function resolveOrderCancelResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $cancelStatus = $data['cancelStatus'] ?? [];

        $status = $cancelStatus['status'] ?? 'unknown';

        return [
            'order_id' => $cancelStatus['order_id'] ?? null,
            'symbol' => null, // Cancel response may not include symbol
            'status' => $this->normalizeCancelStatus($status),
            'price' => '0',
            '_price' => '0',
            'average_price' => '0',
            'original_quantity' => '0',
            'executed_quantity' => '0',
            'type' => null,
            '_orderType' => 'UNKNOWN',
            'side' => null,
            'original_type' => null,
            '_raw' => $data,
        ];
    }

    /**
     * Normalize Kraken cancel status to canonical format.
     */
    private function normalizeCancelStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'cancelled', 'canceled' => 'CANCELLED',
            'notfound', 'not_found' => 'NOT_FOUND',
            'filled' => 'FILLED', // Already filled, couldn't cancel
            default => mb_strtoupper($status),
        };
    }
}
