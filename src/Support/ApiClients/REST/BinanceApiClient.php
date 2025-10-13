<?php

namespace Martingalian\Core\Support\ApiClients\REST;

use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiRequest;
use Binance\Util\Url;

class BinanceApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'binance');

        $this->exceptionHandler = BaseExceptionHandler::make('binance');

        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    protected function getHeaders(): array
    {
        return [
            'X-MBX-APIKEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    public function signRequest(ApiRequest $apiRequest)
    {
        // Set the recvwindow
        $apiRequest->properties->set(
            'options.recvWindow',
            ApiSystem::firstWhere('canonical', 'binance')->recvwindow_margin
        );

        $apiRequest->properties->set(
            'options.timestamp',
            round(microtime(true) * 1000)
        );

        $query = Url::buildQuery($apiRequest->properties->getOr('options', []));

        $signature = hash_hmac(
            'sha256',
            $query,
            $this->credentials->get('api_secret')
        );

        $apiRequest->properties->set(
            'options.signature',
            $signature
        );

        return $this->processRequest($apiRequest);
    }
}
