<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ApiSystem;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->canonical);
    }

    // V4 ready.
    public function apiQueryMarketData(): ApiResponse
    {
        $account = Account::admin($this->canonical);

        $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getExchangeInformation($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryMarketDataResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryLeverageBracketsData(): ApiResponse
    {
        $account = Account::admin($this->canonical);

        $this->apiProperties = $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }

    /**
     * Query leverage brackets for a specific symbol.
     *
     * Used by exchanges that require per-symbol API calls (Bybit, KuCoin).
     */
    public function apiQueryLeverageBracketsDataForSymbol(string $symbol): ApiResponse
    {
        $account = Account::admin($this->canonical);

        $this->apiProperties = $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this, $symbol);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }
}
