<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsTokenLeverageRatios
{
    // V4 ready.
    public function prepareUpdateLeverageRatioProperties(Position $position, int $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);

        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.leverage', (string) $leverage);

        return $properties;
    }

    // V4 ready.
    public function resolveUpdateLeverageRatioResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
