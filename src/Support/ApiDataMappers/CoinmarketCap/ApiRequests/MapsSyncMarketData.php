<?php

namespace Martingalian\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests;

use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsSyncMarketData
{
    public function prepareSyncMarketDataProperties(Symbol $symbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $symbol);
        $properties->set('options.id', $symbol->cmc_id);
        $properties->set('loggable', $symbol);

        return $properties;
    }

    public function resolveSyncMarketDataResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
