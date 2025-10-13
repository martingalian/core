<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsPlaceOrder
{
    public function preparePlaceOrderProperties(Order $order): ApiProperties
    {
        // Auto-generate client order id, if null.
        if (is_null($order->client_order_id)) {
            $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
        }

        $properties = new ApiProperties;
        $properties->set('relatable', $order);
        $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.side', (string) $this->sideType($order->side));
        $properties->set('options.newClientOrderId', (string) $order->client_order_id);
        $properties->set('options.positionSide', (string) $order->position_side);
        $properties->set('options.quantity', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));

        switch ($order->type) {
            // A profit order type limit.
            case 'PROFIT-LIMIT':
                $properties->set('options.timeInForce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'LIMIT':
                $properties->set('options.timeInForce', 'GTC');
                $properties->set('options.type', 'LIMIT');
                $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;

            case 'MARKET':
            case 'MARKET-MAGNET':
            case 'MARKET-CANCEL':
                $properties->set('options.type', 'MARKET');
                break;

            case 'STOP-MARKET':
                $properties->set('options.type', 'STOP_MARKET');
                $properties->set('options.timeInForce', 'GTC');
                $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
                break;
        }

        return $properties;
    }

    public function resolvePlaceOrderResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
