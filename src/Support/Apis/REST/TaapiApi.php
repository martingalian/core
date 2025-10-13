<?php

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Concerns\HasPropertiesValidation;
use Martingalian\Core\Support\ApiClients\REST\TaapiApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

/**
 * TaapiApi handles the communication with the Taapi.io API,
 * allowing retrieval of indicator values for specific symbols.
 */
class TaapiApi
{
    use HasPropertiesValidation;

    // API client instance.
    protected $client;

    // Decrypted API secret key.
    protected $secret;

    // Constructor to initialize the API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->secret = $credentials->get('taapi_secret');
        $url = config('martingalian.api.url.taapi.rest');

        $this->client = new TaapiApiClient([
            'url' => $url,
            'secret' => $this->secret,
        ]);
    }

    public function getGroupedIndicatorsValues(ApiProperties $properties)
    {
        $payload = [
            'secret' => $this->secret,
            'construct' => [
                'exchange' => $properties->get('options.exchange'),
                'symbol' => $properties->get('options.symbol'),
                'interval' => $properties->get('options.interval'),
                'indicators' => $properties->get('options.indicators'),
            ],
            'debug' => $properties->getOr('debug', []),
        ];

        $mergedProperties = $properties->mergeIntoNew($payload);

        $apiRequest = ApiRequest::make(
            'POST',
            '/bulk',
            $mergedProperties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // Fetches indicator values for the given API properties.
    public function getIndicatorValues(ApiProperties $properties)
    {
        $properties->set('options.secret', $this->secret);

        $apiRequest = ApiRequest::make(
            'GET',
            '/'.$properties->get('options.endpoint'),
            new ApiProperties($properties->toArray())
        );

        return $this->client->publicRequest($apiRequest);
    }
}
