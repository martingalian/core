<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsExchangeInformationQuery
{
    public function prepareQueryMarketDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        return $properties;
    }

    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return collect($data['symbols'] ?? [])
            // Remove symbols with underscores in the name.
            ->filter(function ($symbolData) {
                return mb_strpos($symbolData['symbol'], '_') === false;
            })
            // Only include perpetual contracts (exclude quarterly and dated futures)
            ->filter(function ($symbolData) {
                return ($symbolData['contractType'] ?? null) === 'PERPETUAL';
            })
            // Only include actively trading symbols (exclude PENDING_TRADING, BREAK, SETTLING, etc.)
            ->filter(function ($symbolData) {
                return ($symbolData['status'] ?? null) === 'TRADING';
            })
            ->map(function ($symbolData) {
                $filters = collect($symbolData['filters'] ?? []);

                $priceFilter = $filters->firstWhere('filterType', 'PRICE_FILTER');
                $minNotionalFilter = $filters->firstWhere('filterType', 'MIN_NOTIONAL');

                return [
                    // Existing fields
                    'pair' => $symbolData['symbol'],
                    'pricePrecision' => $symbolData['pricePrecision'],
                    'quantityPrecision' => $symbolData['quantityPrecision'],
                    'tickSize' => isset($priceFilter['tickSize']) ? (float) $priceFilter['tickSize'] : null,
                    'minPrice' => isset($priceFilter['minPrice']) ? (float) $priceFilter['minPrice'] : null,
                    'maxPrice' => isset($priceFilter['maxPrice']) ? (float) $priceFilter['maxPrice'] : null,
                    'minNotional' => isset($minNotionalFilter['notional']) ? (float) $minNotionalFilter['notional'] : null,

                    // New fields needed for delisting/tradeability logic and metadata
                    'status' => $symbolData['status'] ?? null,     // e.g. TRADING, BREAK, SETTLING, DELIVERING, PENDING_TRADING
                    'contractType' => $symbolData['contractType'] ?? null,     // e.g. PERPETUAL, CURRENT_QUARTER, NEXT_QUARTER
                    // deliveryDate in ms epoch; perpetuals have default 4133404800000 (Dec 25, 2100)
                    'deliveryDate' => isset($symbolData['deliveryDate']) ? (int) $symbolData['deliveryDate'] : null,
                    'onboardDate' => isset($symbolData['onboardDate']) ? (int) $symbolData['onboardDate'] : 0, // ms epoch
                    'baseAsset' => $symbolData['baseAsset'] ?? null,
                    'quoteAsset' => $symbolData['quoteAsset'] ?? null,
                    'marginAsset' => $symbolData['marginAsset'] ?? null,
                ];
            })
            ->toArray();
    }
}
