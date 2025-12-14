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
        $orders = $data['openOrders'] ?? [];

        return array_map(function (array $order): array {
            $order['computed_price'] = $this->computeOrderPrice($order);

            return $order;
        }, $orders);
    }

    /**
     * Compute the effective display price based on order type.
     *
     * - lmt (limit): uses limitPrice
     * - mkt (market): uses 0
     * - stp (stop): uses stopPrice if available, else limitPrice
     */
    private function computeOrderPrice(array $order): string
    {
        $type = $order['type'] ?? '';
        $limitPrice = (string) ($order['limitPrice'] ?? '0');
        $stopPrice = $order['stopPrice'] ?? null;

        // For stop orders, use stopPrice if available
        if ($type === 'stp' && $stopPrice !== null && (float) $stopPrice > 0) {
            return (string) $stopPrice;
        }

        return match ($type) {
            'lmt' => $limitPrice,
            'mkt' => '0',
            'stp' => $limitPrice,
            default => (float) $limitPrice > 0 ? $limitPrice : '0',
        };
    }
}
