<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Support\ApiClients\REST\BybitApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

final class BybitApi
{
    // The REST api client.
    private $client;

    // Initializes Bybit API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BybitApiClient([
            'url' => config('martingalian.api.url.bybit.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('bybit_api_key'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_secret' => $credentials->get('bybit_api_secret'),
        ]);
    }

    // https://bybit-exchange.github.io/docs/v5/market/instrument
    public function getExchangeInformation(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v5/market/instruments-info',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }
}
