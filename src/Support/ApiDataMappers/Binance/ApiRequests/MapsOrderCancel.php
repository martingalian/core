<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderCancel
{
    public function prepareOrderCancelProperties(Order $order): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    public function resolveOrderCancelResponse(Response $response): array
    {
        $result = json_decode($response->getBody(), true);

        return [
            'order_id' => $result['orderId'],
            'symbol' => $this->identifyBaseAndQuote($result['symbol']),
            'status' => $result['status'],
            'price' => $result['price'],
            'average_price' => $result['avgPrice'],
            'original_quantity' => $result['origQty'],
            'executed_quantity' => $result['executedQty'],
            'type' => $result['type'],
            'side' => $result['side'],
            'original_type' => $result['origType'],
        ];
    }
}
