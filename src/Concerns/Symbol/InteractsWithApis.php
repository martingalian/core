<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Symbol;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public Account $apiAccount;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount->apiSystem->canonical);
    }

    public function apiSyncCMCData(): ApiResponse
    {
        $this->apiAccount = Account::admin('coinmarketcap');
        $this->apiProperties = $this->apiMapper()->prepareSyncMarketDataProperties($this);
        $this->apiProperties->set('account', $this->apiAccount);
        $this->apiResponse = $this->apiAccount->withApi()->getSymbolsMetadata($this->apiProperties);
        $result = json_decode((string) $this->apiResponse->getBody(), true);

        // Sync symbol metadata
        $marketData = collect($result['data'])->first();

        if ($marketData) {
            $this->updateSaving([
                'token' => $marketData['symbol'],
                'name' => $marketData['name'],
                'description' => $marketData['description'],
                'image_url' => $marketData['logo'],
                'site_url' => $this->sanitizeWebsiteAttribute($marketData['urls']['website']),
                'created_at' => now(),
            ]);
        }

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveSyncMarketDataResponse($this->apiResponse)
        );
    }

    protected function sanitizeWebsiteAttribute(mixed $website): ?string
    {
        return is_array($website) ? collect($website)->first() : $website;
    }
}
