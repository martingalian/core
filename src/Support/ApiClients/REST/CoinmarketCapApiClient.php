<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\REST;

use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

final class CoinmarketCapApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'coinmarketcap');

        $this->credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
        ]);

        parent::__construct($config['url'], $this->credentials);
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    public function getHeaders(): array
    {
        return [
            'X-CMC_PRO_API_KEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }
}
