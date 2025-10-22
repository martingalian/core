<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderQuery
{
    public function prepareOrderQueryProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.orderId', (string) $order->exchange_order_id);

        return $properties;
    }

    public function resolveOrderQueryResponse(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        $raw = $result;

        // Special cases.
        if ($result['type'] === 'STOP_MARKET') {
            $price = $result['stopPrice'];
            $quantity = 0;
        } else {
            $price = $result['avgPrice'] !== 0 ? $result['avgPrice'] : $result['price'];
            $quantity = $result['executedQty'] !== 0 ? $result['executedQty'] : $result['origQty'];
        }

        if ($result['status'] === 'CANCELED') {
            $result['status'] = 'CANCELLED';
        }

        $data = [
            // Exchange order id.
            'order_id' => $result['orderId'],

            // [0 => 'RENDER', 1 => 'USDT']
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),

            // NEW, FILLED, CANCELED, PARTIALLY_FILLED
            'status' => $result['status'],

            'price' => $price,
            'quantity' => $quantity,
            'type' => $result['type'],
            'side' => $result['side'],

            '_raw' => $raw,
        ];

        return $data;
    }
}
