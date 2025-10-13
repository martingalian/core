<?php

namespace Martingalian\Core\Concerns\Order;

use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiResponse;
use GuzzleHttp\Psr7\Response;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiAccount()
    {
        return $this->position->account;
    }

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiAccount()->apiSystem->canonical);
    }

    public function apiCancel(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderCancelProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->cancelOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderCancelResponse($this->apiResponse)
        );
    }

    public function apiModify(?float $quantity = null, ?float $price = null): ApiResponse
    {
        if (! $quantity) {
            $quantity = $this->quantity;
        }

        if (! $price) {
            $price = $this->price;
        }

        $this->apiProperties = $this->apiMapper()->prepareOrderModifyProperties($this, $quantity, $price);
        $this->apiResponse = $this->apiAccount()->withApi()->modifyOrder($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderModifyResponse($this->apiResponse)
        );
    }

    // Queries an order.
    public function apiQuery(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareOrderQueryProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->orderQuery($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveOrderQueryResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiSync(): ApiResponse
    {
        $apiResponse = $this->apiQuery();

        $this->updateSaving([
            'status' => $apiResponse->result['status'],
            'quantity' => $apiResponse->result['quantity'],
            'price' => $apiResponse->result['price'],
        ]);

        return $apiResponse;
    }

    // V4 ready.
    public function apiPlace(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->preparePlaceOrderProperties($this);
        $this->apiResponse = $this->apiAccount()->withApi()->placeOrder($this->apiProperties);

        $finalResponse = new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolvePlaceOrderResponse($this->apiResponse)
        );

        $this->updateSaving([
            'exchange_order_id' => $finalResponse->result['orderId'],
            'opened_at' => now(),
        ]);

        return $finalResponse;
    }
}
