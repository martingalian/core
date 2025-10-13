<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use GuzzleHttp\Psr7\Response;

trait MapsOpenOrdersQuery
{
    public function prepareQueryOpenOrdersProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    public function resolveQueryOpenOrdersResponse(Response $response): array
    {
        return json_decode($response->getBody(), true);
    }
}
