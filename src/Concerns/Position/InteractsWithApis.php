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

    /**
     * Update margin type for the position's symbol on the exchange.
     *
     * The margin mode is read from the account's margin_mode setting.
     * Each exchange's ApiDataMapper handles the conversion to exchange-specific format.
     */
    public function apiUpdateMarginType(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateMarginTypeProperties($this);
        $this->apiProperties->set('account', $this->account);
        $this->apiResponse = $this->account->withApi()->updateMarginType($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveUpdateMarginTypeResponse($this->apiResponse)
        );
    }

    /**
     * Set leverage preferences (Kraken only - combines margin mode + leverage).
     *
     * Kraken's API uses a single endpoint for both margin mode and leverage:
     * - Setting maxLeverage = ISOLATED margin
     * - Omitting maxLeverage = CROSS margin
     */
    public function apiSetLeveragePreferences(int $leverage): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateLeverageRatioProperties($this, $leverage);
        $this->apiProperties->set('account', $this->account);
        $this->apiResponse = $this->account->withApi()->setLeveragePreferences($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveUpdateLeverageRatioResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiUpdateLeverageRatio(int $leverage): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareUpdateLeverageRatioProperties($this, $leverage);
        $this->apiProperties->set('account', $this->account);
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
        $this->apiProperties->set('account', $this->account);

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
        $this->apiProperties->set('account', $this->account);

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

        $matching = collect($positions)->filter(static function ($p) use ($want) {
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
