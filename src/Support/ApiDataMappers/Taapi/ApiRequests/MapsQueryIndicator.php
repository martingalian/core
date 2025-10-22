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

        $base = $exchangeSymbol->symbol->token;
        $quote = $exchangeSymbol->quote->canonical;

        $properties->set('options.symbol', $this->baseWithQuote($base, $quote));
        $properties->set('options.exchange', $exchangeSymbol->apiSystem->taapi_canonical);

        return $properties;
    }

    public function resolveQueryIndicatorResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
