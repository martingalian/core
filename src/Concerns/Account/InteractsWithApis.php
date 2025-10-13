<?php

namespace Martingalian\Core\Concerns\Account;

use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\Proxies\ApiRESTProxy;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiResponse;
use GuzzleHttp\Psr7\Response;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiSystem->canonical);
    }

    /**
     * Return the right api client object from the ApiRESTProxy given the account
     * connection id. Also, verifies if we should use test account credentials or not.
     */
    public function withApi()
    {
        // Mask values (keep last 6 chars) instead of logging raw secrets
        $masked = collect($this->all_credentials)->map(function ($v) {
            return is_string($v) && $v !== ''
                ? str_repeat('*', max(0, strlen($v) - 6)).substr($v, -6)
                : $v;
        })->all();

        return new ApiRESTProxy(
            $this->apiSystem->canonical,
            new ApiCredentials($this->all_credentials)
        );
    }

    // V4 ready.
    public function apiQuery(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryAccountProperties($this);
        $this->apiResponse = $this->withApi()->account($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryAccountResponse($this->apiResponse)
        );
    }

    public function apiQueryOpenOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryOpenOrdersProperties($this);
        $this->apiResponse = $this->withApi()->getCurrentOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryOpenOrdersResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryPositions(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryPositionsProperties($this);
        $this->apiResponse = $this->withApi()->getPositions($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryPositionsResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryBalance(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareGetBalanceProperties($this);
        $this->apiResponse = $this->withApi()->getAccountBalance($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveGetBalanceResponse($this->apiResponse)
        );
    }
}
