<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\REST;

use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

final class AlternativeMeApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'alternativeme');

        parent::__construct($config['url'], null);
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
