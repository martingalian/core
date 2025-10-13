<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsMarkPriceQuery
{
    public function prepareQueryMarkPriceProperties(ExchangeSymbol $exchangeSymbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $data = json_decode($response->getBody(), true);

        if (array_key_exists('markPrice', $data)) {
            return $data['markPrice'];
        }

        return null;
    }
}
