<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    /**
     * Prepare properties for querying leverage brackets on Kraken Futures.
     *
     * Uses the instruments endpoint which returns marginLevels (market data).
     * Note: leveragepreferences endpoint returns user-specific settings, not market data.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-instruments
     */
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem, ?string $symbol = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // Kraken instruments endpoint returns all symbols in one call

        return $properties;
    }

    /**
     * Resolve the leverage brackets response from Kraken instruments endpoint.
     *
     * Kraken instruments response structure:
     * {
     *     "result": "success",
     *     "instruments": [
     *         {
     *             "symbol": "PF_XBTUSD",
     *             "marginLevels": [
     *                 {
     *                     "contracts": 0,           // Position floor
     *                     "initialMargin": 0.02,    // 1/leverage (0.02 = 50x)
     *                     "maintenanceMargin": 0.01 // Maintenance margin ratio
     *                 },
     *                 ...
     *             ]
     *         }
     *     ]
     * }
     */
    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $instruments = $data['instruments'] ?? [];

        // Transform instruments with marginLevels to normalized bracket format
        return collect($instruments)
            ->filter(static function (array $instrument): bool {
                // Only include instruments that have marginLevels
                return ! empty($instrument['marginLevels']);
            })
            ->keyBy('symbol')
            ->map(static function (array $instrument): array {
                $marginLevels = $instrument['marginLevels'] ?? [];

                // Convert marginLevels to normalized bracket format
                $brackets = collect($marginLevels)
                    ->values()
                    ->map(static function (array $level, int $index) use ($marginLevels): array {
                        // Calculate leverage from initialMargin: leverage = 1 / initialMargin
                        $initialMargin = $level['initialMargin'] ?? 0.02;
                        $leverage = $initialMargin > 0 ? (int) round(1 / $initialMargin) : 50;

                        // Position floor: PF_ uses numNonContractUnits, PI_ uses contracts
                        $notionalFloor = $level['numNonContractUnits'] ?? $level['contracts'] ?? 0;

                        // notionalCap is the next tier's floor (or null for last tier)
                        $nextLevel = $marginLevels[$index + 1] ?? null;
                        $notionalCap = $nextLevel !== null
                            ? ($nextLevel['numNonContractUnits'] ?? $nextLevel['contracts'] ?? null)
                            : null;

                        return [
                            'bracket' => $index + 1,
                            'initialLeverage' => $leverage,
                            'notionalCap' => $notionalCap,
                            'notionalFloor' => $notionalFloor,
                            'maintMarginRatio' => $level['maintenanceMargin'] ?? null,
                        ];
                    })
                    ->toArray();

                // Get max leverage from first bracket (lowest position size = highest leverage)
                $maxLeverage = $brackets[0]['initialLeverage'] ?? 50;

                return [
                    'symbol' => $instrument['symbol'] ?? null,
                    'maxLeverage' => $maxLeverage,
                    'brackets' => $brackets,
                ];
            })
            ->toArray();
    }
}
