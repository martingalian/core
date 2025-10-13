<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);

        $properties->set('options.symbol', $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.margintype', 'CROSSED');

        return $properties;
    }

    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
