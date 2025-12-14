<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps BitGet plan orders (stop-loss, take-profit, trigger orders) query.
 *
 * Plan orders are conditional orders that execute when a trigger price is reached.
 * They are separate from regular limit/market orders and use a different endpoint.
 */
trait MapsPlanOrdersQuery
{
    public function prepareQueryPlanOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // BitGet V2 requires productType for futures
        $properties->set('options.productType', 'USDT-FUTURES');

        return $properties;
    }

    /**
     * Resolves BitGet plan orders response.
     *
     * BitGet V2 response structure for plan orders:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": {
     *         "entrustedList": [
     *             {
     *                 "planType": "normal_plan",
     *                 "symbol": "BTCUSDT",
     *                 "size": "0.001",
     *                 "orderId": "1234567890",
     *                 "clientOid": "xxx",
     *                 "triggerPrice": "45000",
     *                 "triggerType": "mark_price",
     *                 "executePrice": "0",
     *                 "planStatus": "live",
     *                 "side": "buy",
     *                 "posSide": "long",
     *                 "marginCoin": "USDT",
     *                 "orderType": "market",
     *                 "enterPointSource": "API",
     *                 "tradeSide": "close",
     *                 "cTime": "1627116936176",
     *                 "uTime": "1627116936176"
     *             }
     *         ],
     *         "endId": "1234567890"
     *     }
     * }
     */
    public function resolveQueryPlanOrdersResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $orders = $data['data']['entrustedList'] ?? [];

        return array_map(function (array $order): array {
            // Add computed_price using triggerPrice for plan orders
            $order['computed_price'] = $this->computePlanOrderPrice($order);

            // Mark as plan order for frontend distinction
            $order['order_source'] = 'plan';

            return $order;
        }, $orders);
    }

    /**
     * Compute the effective display price for plan orders.
     *
     * For plan orders, the trigger price is the primary display price.
     * executePrice is the limit price (0 for market orders).
     */
    private function computePlanOrderPrice(array $order): string
    {
        $triggerPrice = $order['triggerPrice'] ?? '0';
        $executePrice = $order['executePrice'] ?? '0';

        // Trigger price is the main price for plan orders
        if ((float) $triggerPrice > 0) {
            return (string) $triggerPrice;
        }

        // Fallback to execute price if trigger price is not set
        if ((float) $executePrice > 0) {
            return (string) $executePrice;
        }

        return '0';
    }
}
