<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsMarkPriceQuery
{
    public function prepareQueryMarkPriceProperties(ExchangeSymbol $exchangeSymbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolves BitGet symbol price response (mark price).
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "markPrice": "40500.5",
     *         "indexPrice": "40495.2",
     *         "lastPrice": "40510.0"
     *     }
     * }
     */
    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        return $body['data']['markPrice'] ?? null;
    }
}
