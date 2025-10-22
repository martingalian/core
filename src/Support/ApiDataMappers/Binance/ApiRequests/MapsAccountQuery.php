<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    public function resolveQueryAccountResponse(Response $response): array
    {
        $response = json_decode((string) $response->getBody(), true);

        if (array_key_exists('assets', $response)) {
            unset($response['assets']);
        }

        if (array_key_exists('positions', $response)) {
            unset($response['positions']);
        }

        return $response;
    }
}
