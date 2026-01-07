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
     *             "minimumTradeSize": 1,
     *             "contractValueTradePrecision": 0,
     *             "marginLevels": [...],
     *             ...
     *         }
     *     ]
     * }
     */
    public function resolveQueryMarketDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $instruments = $data['instruments'] ?? [];

        // Fiat currencies to exclude (Kraken offers forex perpetuals alongside crypto)
        $fiatCurrencies = ['EUR', 'GBP', 'AUD', 'CHF', 'JPY', 'CAD', 'NZD', 'SGD', 'HKD', 'NOK', 'SEK', 'DKK', 'CNY', 'CNH', 'KRW', 'INR', 'MXN', 'BRL', 'ZAR', 'TRY', 'PLN', 'CZK', 'HUF', 'ILS', 'THB', 'MYR', 'IDR', 'PHP', 'TWD', 'RUB'];

        // Stablecoins to exclude - these don't need price tracking as they're pegged to fiat
        $stablecoins = ['USDC', 'USDT', 'USDE', 'DAI', 'TUSD', 'BUSD', 'FRAX', 'USDP', 'GUSD', 'PAX', 'LUSD', 'SUSD', 'FDUSD', 'PYUSD', 'RLUSD', 'CUSD', 'USDD', 'USDJ', 'USTC', 'EURC', 'EURT'];

        $filtered = collect($instruments)
            // Only include tradeable instruments
            ->filter(static function ($instrument) {
                return ($instrument['tradeable'] ?? false) === true;
            })
            // Only include perpetual futures (PF_ prefix for multi-collateral, PI_ for inverse)
            // Excludes fixed-maturity futures like FF_XBTUSD_251226
            ->filter(static function ($instrument) {
                $symbol = $instrument['symbol'] ?? '';

                // Only include symbols starting with PF_ (perpetual flex) or PI_ (perpetual inverse)
                // This excludes FF_ (fixed-maturity futures) which have expiry dates
                return str_starts_with(haystack: $symbol, needle: 'PF_') || str_starts_with(haystack: $symbol, needle: 'PI_');
            })
            // Exclude fiat currency pairs (forex perpetuals)
            ->filter(function ($instrument) use ($fiatCurrencies) {
                $symbol = $instrument['symbol'] ?? '';
                $baseQuote = $this->identifyBaseAndQuote($symbol);

                // Exclude if base asset is a fiat currency
                return ! in_array($baseQuote['base'], $fiatCurrencies, strict: true);
            })
            // Exclude stablecoins - they don't need price tracking
            ->filter(function ($instrument) use ($stablecoins) {
                $symbol = $instrument['symbol'] ?? '';
                $baseQuote = $this->identifyBaseAndQuote($symbol);

                return ! in_array($baseQuote['base'], $stablecoins, strict: true);
            });

        // Prioritize PF_ (flexible_futures) over PI_ (inverse) when both exist for same token/quote
        // Group by base+quote, then select PF_ if available, otherwise PI_
        $prioritized = $filtered
            ->groupBy(function ($instrument) {
                $baseQuote = $this->identifyBaseAndQuote($instrument['symbol']);

                return $baseQuote['base'].'-'.$baseQuote['quote'];
            })
            ->map(static function ($group) {
                // If group has a PF_ contract, use it; otherwise use the first available
                $pfContract = $group->first(static function ($instrument) {
                    return str_starts_with(haystack: $instrument['symbol'], needle: 'PF_');
                });

                return $pfContract ?? $group->first();
            })
            ->values();

        return $prioritized
            ->map(function ($instrument) {
                // Kraken uses symbol format like PF_XBTUSD or PI_ETHUSD
                $symbol = $instrument['symbol'] ?? '';

                // Extract base and quote from symbol
                // PF_XBTUSD -> base: XBT, quote: USD
                $symbolPart = mb_ltrim($symbol, 'PF_PI_');
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

                    // Kraken-specific: contract size (typically 1 = $1 per contract)
                    // Field name in API: contractSize
                    'krakenMinOrderSize' => isset($instrument['contractSize'])
                        ? (float) $instrument['contractSize']
                        : null,

                    // Status and contract information
                    'status' => ($instrument['tradeable'] ?? false) ? 'Trading' : 'Break',
                    'contractType' => $instrument['type'] ?? null,
                    // lastTradingTime indicates delisting - convert ISO 8601 to milliseconds
                    'deliveryDate' => isset($instrument['lastTradingTime'])
                        ? strtotime($instrument['lastTradingTime']) * 1000
                        : null,
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
        $trimmed = mb_rtrim(mb_substr($decimalPart, 1), '0');

        return mb_strlen($trimmed) > 0 ? mb_strlen($trimmed) : 0;
    }
}
