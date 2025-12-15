<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsPlaceOrder
{
    /**
     * Prepare properties for placing an order on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/send-order/
     */
    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order ID if null
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.cliOrdId', (string) $order->client_order_id);
        $properties->set('options.size', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));

        switch ($order->type) {
            case 'PROFIT-LIMIT':
            case 'LIMIT':
                $properties->set('options.orderType', 'lmt');
                $properties->set('options.limitPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.orderType', 'mkt');
                break;

            case 'STOP-MARKET':
                $properties->set('options.orderType', 'stp');
                $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                $properties->set('options.triggerSignal', 'mark');
                break;

            case 'TAKE-PROFIT':
                $properties->set('options.orderType', 'take_profit');
                $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                $properties->set('options.triggerSignal', 'mark');
                break;
        }

        // Set reduceOnly if closing position
        if ($order->reduce_only ?? false) {
            $properties->set('options.reduceOnly', true);
        }

        return $properties;
    }

    /**
     * Resolve the place order response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "sendStatus": {
     *         "order_id": "abc123",
     *         "status": "placed",
     *         "receivedTime": "2024-01-15T10:30:00.000Z",
     *         "orderEvents": [...]
     *     }
     * }
     */
    public function resolvePlaceOrderResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $sendStatus = $data['sendStatus'] ?? [];

        // Normalize response to expected format
        $order = [
            'orderId' => $sendStatus['order_id'] ?? null,
            'clientOrderId' => $sendStatus['cliOrdId'] ?? null,
            'status' => $this->normalizeOrderStatus($sendStatus['status'] ?? 'unknown'),
            'receivedTime' => $sendStatus['receivedTime'] ?? null,
            '_raw' => $data,
        ];

        $order['_price'] = $this->computePlaceOrderPrice($sendStatus);
        $order['_orderType'] = $this->canonicalOrderType($sendStatus);

        return $order;
    }

    /**
     * Compute the effective display price from Kraken place order response.
     */
    private function computePlaceOrderPrice(array $sendStatus): string
    {
        // Kraken sendorder response typically doesn't include price info
        // Return limitPrice or stopPrice from order events if available
        $orderEvents = $sendStatus['orderEvents'] ?? [];

        foreach ($orderEvents as $event) {
            $eventOrder = $event['order'] ?? [];
            if (isset($eventOrder['limitPrice'])) {
                return (string) $eventOrder['limitPrice'];
            }
            if (isset($eventOrder['stopPrice'])) {
                return (string) $eventOrder['stopPrice'];
            }
        }

        return '0';
    }

    /**
     * Normalize Kraken order status to canonical format.
     */
    private function normalizeOrderStatus(string $status): string
    {
        return match (mb_strtolower($status)) {
            'placed' => 'NEW',
            'filled' => 'FILLED',
            'cancelled', 'canceled' => 'CANCELED',
            'partiallyFilled', 'partially_filled' => 'PARTIALLY_FILLED',
            'rejected' => 'REJECTED',
            'insufficientavailablefunds', 'insufficientfunds' => 'REJECTED',
            default => mb_strtoupper($status),
        };
    }
}
