<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\REST;

use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

final class BybitApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        $this->exceptionHandler = BaseExceptionHandler::make('bybit');

        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    public function signRequest(ApiRequest $apiRequest)
    {
        $timestamp = round(microtime(true) * 1000);
        $recvWindow = ApiSystem::firstWhere('canonical', 'bybit')->recvwindow_margin;

        // Build query string from options
        $queryString = http_build_query($apiRequest->properties->getOr('options', []));

        // Bybit V5 signature for GET: timestamp + api_key + recv_window + queryString
        $signaturePayload = $timestamp.$this->credentials->get('api_key').$recvWindow.$queryString;

        $signature = hash_hmac(
            'sha256',
            $signaturePayload,
            $this->credentials->get('api_secret')
        );

        // Add Bybit-specific headers
        $apiRequest->properties->set('headers.X-BAPI-TIMESTAMP', $timestamp);
        $apiRequest->properties->set('headers.X-BAPI-SIGN', $signature);
        $apiRequest->properties->set('headers.X-BAPI-RECV-WINDOW', $recvWindow);

        return $this->processRequest($apiRequest);
    }

    protected function getHeaders(): array
    {
        return [
            'X-BAPI-API-KEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }
}
