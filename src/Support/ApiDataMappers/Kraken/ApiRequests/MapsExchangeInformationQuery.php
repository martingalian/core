<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

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

    /**
     * Resolves Kraken instruments response.
     *
     * Kraken Futures response structure:
     * {
     *     "result": "success",
     *     "instruments": [
     *         {
     *             "symbol": "PF_XBTUSD",
     *             "type": "flexible_futures",
     *             "tradeable": true,
     *             "tickSize": 0.5,
     *             "contractSize": 1,
     *             "marginLevels": [...],
     *             ...
     *         }
     *     ]
     * }
     */
    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        $instruments = $data['instruments'] ?? [];

        return collect($instruments)
            // Only include tradeable instruments
            ->filter(function ($instrument) {
                return ($instrument['tradeable'] ?? false) === true;
            })
            // Only include perpetual futures (PF_ prefix for multi-collateral, PI_ for inverse)
            ->filter(function ($instrument) {
                $symbol = $instrument['symbol'] ?? '';
                $type = $instrument['type'] ?? '';

                // Include flexible_futures (multi-collateral perpetuals)
                // and perpetual_inverse contracts
                return in_array($type, ['flexible_futures', 'perpetual_inverse'], true)
                    || str_starts_with($symbol, 'PF_')
                    || str_starts_with($symbol, 'PI_');
            })
            ->map(function ($instrument) {
                // Kraken uses symbol format like PF_XBTUSD or PI_ETHUSD
                $symbol = $instrument['symbol'] ?? '';

                // Extract base and quote from symbol
                // PF_XBTUSD -> base: XBT, quote: USD
                $symbolPart = ltrim($symbol, 'PF_PI_');
                $baseQuote = $this->identifyBaseAndQuote($symbol);

                // Calculate precision from tickSize
                $tickSize = $instrument['tickSize'] ?? 0.01;
                $pricePrecision = $this->calculatePrecisionFromStep((string) $tickSize);

                // Calculate quantity precision from contractValueTradePrecision
                $quantityPrecision = (int) ($instrument['contractValueTradePrecision'] ?? 0);

                return [
                    'pair' => $symbol,
                    'pricePrecision' => $pricePrecision,
                    'quantityPrecision' => $quantityPrecision,
                    'tickSize' => (float) $tickSize,
                    'minPrice' => null,
                    'maxPrice' => null,
                    'minNotional' => null,

                    // Status and contract information
                    'status' => ($instrument['tradeable'] ?? false) ? 'Trading' : 'Break',
                    'contractType' => $instrument['type'] ?? null,
                    'deliveryDate' => 0,
                    'onboardDate' => isset($instrument['openingDate'])
                        ? strtotime($instrument['openingDate']) * 1000
                        : 0,
                    'baseAsset' => $baseQuote['base'],
                    'quoteAsset' => $baseQuote['quote'],
                    'marginAsset' => $baseQuote['quote'],
                ];
            })
            ->toArray();
    }

    /**
     * Calculate decimal precision from a step value.
     */
    private function calculatePrecisionFromStep(string $step): int
    {
        $decimalPart = mb_strrchr($step, '.');

        if ($decimalPart === false) {
            return 0;
        }

        // Remove trailing zeros and count decimal places
        $trimmed = rtrim(mb_substr($decimalPart, 1), '0');

        return mb_strlen($trimmed) > 0 ? mb_strlen($trimmed) : 0;
    }
}
