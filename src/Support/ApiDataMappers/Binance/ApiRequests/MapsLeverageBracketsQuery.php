<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

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
        return json_decode((string) $response->getBody(), associative: true);
    }
}
