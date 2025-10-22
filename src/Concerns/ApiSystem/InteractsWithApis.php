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
        $this->apiResponse = $account->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }
}
