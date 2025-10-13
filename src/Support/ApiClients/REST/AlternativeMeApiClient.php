<?php

namespace Martingalian\Core\Support\ApiClients\REST;

use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

class AlternativeMeApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'alternativeme');

        parent::__construct($config['url'], null);
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }
}
