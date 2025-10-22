<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsOrderModify
{
    public function prepareOrderModifyProperties(order $order, $quantity, $price): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.orderId', (string) $order->exchange_order_id);
        $properties->set('options.side', (string) $order->side);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsedTradingPair);
        $properties->set('options.quantity', (string) $quantity);
        $properties->set('options.price', (string) $price);

        return $properties;
    }

    public function resolveOrderModifyResponse(Response $response): array
    {
        $result = json_decode((string) $response->getBody(), true);

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
