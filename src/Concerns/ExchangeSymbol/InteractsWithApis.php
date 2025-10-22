<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper($canonical)
    {
        return new ApiDataMapperProxy($canonical);
    }

    // V4 ready.
    public function apiQueryMarkPrice(): ApiResponse
    {
        $account = Account::admin($this->apiSystem->canonical);
        $canonical = $this->apiSystem->canonical;

        $this->apiProperties = $this->apiMapper($canonical)->prepareQueryMarkPriceProperties($this);
        $this->apiResponse = $account->withApi()->getMarkPrice($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: ['mark_price' => $this->apiMapper($canonical)->resolveQueryMarkPriceResponse($this->apiResponse)]
        );
    }
}
