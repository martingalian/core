<?php

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsPositionsQuery
{
    public function prepareQueryPositionsProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    public function resolveQueryPositionsResponse(Response $response): array
    {
        $positions = collect(json_decode($response->getBody(), true))->keyBy('symbol')->toArray();

        // Remove false positive positions (positionAmt = 0.0)
        $positions = array_filter($positions, function ($position) {
            return (float) $position['positionAmt'] != 0.0;
        });

        return $positions;
    }
}
