<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsAccountQueryTrades
{
    public function prepareQueryTokenTradesProperties(Position $position, ?string $orderId = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);

        if (! is_null($orderId)) {
            $properties->set('options.orderId', (string) $orderId);
        }

        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    public function resolveQueryTradeResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
