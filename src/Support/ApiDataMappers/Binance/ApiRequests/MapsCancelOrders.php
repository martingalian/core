<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsCancelOrders
{
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    public function resolveCancelOrdersResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
