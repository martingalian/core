<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // Bybit requires category parameter for linear futures
        $properties->set('options.category', 'linear');

        // Set limit to max to get all symbols (Bybit default is 500, max is 1000)
        $properties->set('options.limit', 1000);

        return $properties;
    }

    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        // Bybit V5 API structure: {retCode, retMsg, result: {list: [...], nextPageCursor: ...}}
        $symbols = $data['result']['list'] ?? [];

        return collect($symbols)
            // Remove symbols with underscores in the name (same logic as Binance)
            ->filter(function ($symbolData) {
                return mb_strpos($symbolData['symbol'], '_') === false;
            })
            // Only include perpetual contracts (exclude dated futures like BTCUSDT-31OCT25)
            ->filter(function ($symbolData) {
                return ($symbolData['contractType'] ?? null) === 'LinearPerpetual';
            })
            ->map(function ($symbolData) {
                // Extract price filter
                $priceFilter = $symbolData['priceFilter'] ?? [];
                $lotSizeFilter = $symbolData['lotSizeFilter'] ?? [];

                // Calculate price precision from priceScale
                $pricePrecision = (int) ($symbolData['priceScale'] ?? 2);

                // Calculate quantity precision from qtyStep
                $qtyStep = $lotSizeFilter['qtyStep'] ?? '0.001';
                $decimalPart = mb_strrchr($qtyStep, '.');
                $quantityPrecision = $decimalPart !== false ? mb_strlen(mb_substr($decimalPart, 1)) : 0;

                return [
                    // Map to canonical format matching Binance structure
                    'pair' => $symbolData['symbol'],
                    'pricePrecision' => $pricePrecision,
                    'quantityPrecision' => $quantityPrecision,
                    'tickSize' => isset($priceFilter['tickSize']) ? (float) $priceFilter['tickSize'] : null,
                    'minPrice' => isset($priceFilter['minPrice']) ? (float) $priceFilter['minPrice'] : null,
                    'maxPrice' => isset($priceFilter['maxPrice']) ? (float) $priceFilter['maxPrice'] : null,
                    'minNotional' => isset($lotSizeFilter['minNotionalValue']) ? (float) $lotSizeFilter['minNotionalValue'] : null,

                    // Status and contract information
                    'status' => $symbolData['status'] ?? null,
                    'contractType' => $symbolData['contractType'] ?? null,
                    'deliveryDate' => 0, // Bybit linear perpetuals don't have delivery dates
                    'onboardDate' => isset($symbolData['launchTime']) ? (int) $symbolData['launchTime'] : 0,
                    'baseAsset' => $symbolData['baseCoin'] ?? null,
                    'quoteAsset' => $symbolData['quoteCoin'] ?? null,
                    'marginAsset' => $symbolData['settleCoin'] ?? null,
                ];
            })
            ->toArray();
    }
}
