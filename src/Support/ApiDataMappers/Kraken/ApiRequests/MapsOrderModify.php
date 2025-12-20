<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderModify
{
    /**
     * Prepare properties for modifying an order on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/edit-order/
     */
    public function prepareOrderModifyProperties(Order $order, string $quantity, string $price): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        // Set new size if provided
        if ($quantity !== '' && $quantity !== '0') {
            $properties->set('options.size', (string) api_format_quantity($quantity, $order->position->exchangeSymbol));
        }

        // Set new price based on order type
        if ($price !== '' && $price !== '0') {
            $formattedPrice = (string) api_format_price($price, $order->position->exchangeSymbol);

            // For stop orders, use stopPrice
            if (in_array($order->type, ['STOP-MARKET', 'TAKE-PROFIT'], true)) {
                $properties->set('options.stopPrice', $formattedPrice);
            } else {
                $properties->set('options.limitPrice', $formattedPrice);
            }
        }

        return $properties;
    }

    /**
     * Resolve the modify order response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "editStatus": {
     *         "order_id": "abc123",
     *         "status": "edited",
     *         "receivedTime": "2024-01-15T10:30:00.000Z",
     *         "orderEvents": [...]
     *     }
     * }
     */
    public function resolveOrderModifyResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $editStatus = $data['editStatus'] ?? [];

        $orderEvents = $editStatus['orderEvents'] ?? [];
        $orderInfo = $this->extractOrderFromEvents($orderEvents);

        return [
            'order_id' => $editStatus['order_id'] ?? null,
            'symbol' => isset($orderInfo['symbol']) ? $this->identifyBaseAndQuote($orderInfo['symbol']) : null,
            'status' => $this->normalizeEditStatus($editStatus['status'] ?? 'unknown'),
            'price' => $orderInfo['limitPrice'] ?? '0',
            '_price' => $this->computeEditOrderPrice($orderInfo),
            'average_price' => '0',
            'original_quantity' => $orderInfo['quantity'] ?? '0',
            'executed_quantity' => $orderInfo['filledSize'] ?? '0',
            'type' => $orderInfo['type'] ?? null,
            '_orderType' => $this->canonicalOrderType($orderInfo),
            'side' => isset($orderInfo['side']) ? mb_strtoupper($orderInfo['side']) : null,
            'original_type' => $orderInfo['type'] ?? null,
            '_raw' => $data,
        ];
    }

    /**
     * Extract order information from order events.
     */
    private function extractOrderFromEvents(array $orderEvents): array
    {
        foreach ($orderEvents as $event) {
            if (!(isset($event['order']))) { continue; }

return $event['order'];
        }

        return [];
    }

    /**
     * Compute the effective display price for the modified order.
     */
    private function computeEditOrderPrice(array $order): string
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
     * Normalize Kraken edit status to canonical format.
     */
    private function normalizeEditStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'edited' => 'MODIFIED',
            'notfound', 'not_found' => 'NOT_FOUND',
            'filled' => 'FILLED', // Already filled, couldn't modify
            'cancelled', 'canceled' => 'CANCELLED',
            default => mb_strtoupper($status),
        };
    }
}
