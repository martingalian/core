<?php

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Support\ApiClients\REST\AlternativeMeApiClient;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

class AlternativeMeApi
{
    protected $client;

    public function __construct()
    {
        $this->client = new AlternativeMeApiClient([
            'url' => config('martingalian.api.url.alternativeme.rest'),
        ]);
    }

    public function getFearAndGreedIndex()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fng',
        );

        return $this->client->publicRequest($apiRequest);
    }
}
