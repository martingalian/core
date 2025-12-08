<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Taapi\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsQueryIndicator
{
    public function prepareQueryIndicatorProperties(ExchangeSymbol $exchangeSymbol, array $parameters): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);

        foreach ($parameters as $key => $value) {
            $properties->set('options.'.$key, $value);
        }

        // Token and quote are stored directly on exchange_symbols
        $properties->set('options.symbol', $this->baseWithQuote($exchangeSymbol->token, $exchangeSymbol->quote));
        $properties->set('options.exchange', $exchangeSymbol->apiSystem->taapi_canonical);

        return $properties;
    }

    public function resolveQueryIndicatorResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }
}
