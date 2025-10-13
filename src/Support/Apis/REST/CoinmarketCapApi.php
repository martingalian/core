<?php

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Support\ApiClients\REST\CoinmarketCapApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

class CoinmarketCapApi
{
    protected $client;

    // Initializes CoinMarketCap API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new CoinmarketCapApiClient([
            'url' => config('martingalian.api.url.coinmarketcap.rest'),
            'api_key' => $credentials->get('coinmarketcap_api_key'),
        ]);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV2CryptocurrencyInfo
    public function getSymbolsMetadata(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/info?',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://coinmarketcap.com/api/documentation/v1/#operation/getV1CryptocurrencyMap
    public function getSymbols(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/v1/cryptocurrency/map',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }
}
