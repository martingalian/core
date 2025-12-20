<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    /**
     * Prepare properties for querying leverage preferences on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-leverage-setting/
     */
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        return $properties;
    }

    /**
     * Resolve the leverage preferences response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "serverTime": "2024-01-15T10:30:00.000Z",
     *     "leveragePreferences": [
     *         {
     *             "symbol": "PF_XBTUSD",
     *             "maxLeverage": 50.0
     *         }
     *     ]
     * }
     */
    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $preferences = $data['leveragePreferences'] ?? [];

        // Transform to a more usable format keyed by symbol
        return collect($preferences)
            ->keyBy('symbol')
            ->map(static function (array $pref): array {
                return [
                    'symbol' => $pref['symbol'] ?? null,
                    'maxLeverage' => $pref['maxLeverage'] ?? null,
                    // Kraken doesn't have brackets like Binance, just max leverage per symbol
                    'brackets' => [
                        [
                            'bracket' => 1,
                            'initialLeverage' => $pref['maxLeverage'] ?? 50,
                            'notionalCap' => null,
                            'notionalFloor' => 0,
                            'maintMarginRatio' => null,
                        ],
                    ],
                ];
            })
            ->toArray();
    }
}
