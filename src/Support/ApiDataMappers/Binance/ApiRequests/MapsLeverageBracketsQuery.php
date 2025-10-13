<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsLeverageBracketsQuery
{
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        return $properties;
    }

    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
