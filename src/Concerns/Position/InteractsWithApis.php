<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Position;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->account->apiSystem->canonical);
    }

    // V4 ready.
    public function apiUpdateMarginTypeToCrossed()
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateMarginTypeProperties($this);
        $this->apiResponse = $this->account->withApi()->updateMarginType($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveUpdateMarginTypeResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiUpdateLeverageRatio(int $leverage): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateLeverageRatioProperties($this, $leverage);
        $this->apiResponse = $this->account->withApi()->changeInitialLeverage($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveUpdateLeverageRatioResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiCancelOpenOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareCancelOrdersProperties($this);

        $this->apiResponse = $this->account->withApi()->cancelAllOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveCancelOrdersResponse($this->apiResponse)
        );
    }

    // Queries the trade data for this position.
    public function apiQueryTokenTrades()
    {
        $parsedSymbol = $this->parsed_trading_pair;
        $orderId = $this->profitOrder()->exchange_order_id;

        $this->apiProperties = $this->apiMapper()->prepareQueryTokenTradesProperties($this);

        $this->apiResponse = $this->account->withApi()->accountTrades($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryTradeResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiClose(): ApiResponse
    {
        $apiResponse = $this->account->apiQueryPositions();
        $positions = $apiResponse->result;
        $want = mb_strtoupper(mb_trim($this->parsed_trading_pair));

        $matching = collect($positions)->filter(function ($p) use ($want) {
            if (! isset($p['symbol'], $p['positionSide'], $p['positionAmt'])) {
                return false;
            }
            if (mb_strtoupper(mb_trim($p['symbol'])) !== $want) {
                return false;
            }

            return abs((float) $p['positionAmt']) > 0.0001;
        });

        $symbols = $matching->pluck('symbol')->unique()->values();
        if ($symbols->count() > 1) {
            return new ApiResponse;
        }

        foreach ($matching as $positionData) {
            $side = ((float) $positionData['positionAmt'] < 0)
            ? $this->apiMapper()->sideType('BUY')
            : $this->apiMapper()->sideType('SELL');

            $data = [
                'type' => 'MARKET-CANCEL',
                'side' => $side,
                'position_side' => $positionData['positionSide'],
                'quantity' => abs((float) $positionData['positionAmt']),
                'position_id' => $this->id,
            ];

            $order = Order::create($data);
            $apiResponse = $order->apiPlace();
        }

        return $apiResponse ?? new ApiResponse;
    }
}
