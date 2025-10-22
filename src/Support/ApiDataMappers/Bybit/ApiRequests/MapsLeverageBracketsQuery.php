<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    public function prepareQueryLeverageBracketsDataProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);

        // Bybit requires category parameter for linear contracts
        $properties->set('options.category', 'linear');

        return $properties;
    }

    public function resolveLeverageBracketsDataResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        // Bybit V5 API structure: {retCode, retMsg, result: {category, list: [...]}}
        $riskLimits = $data['result']['list'] ?? [];

        // Group risk limits by symbol and transform to match Binance structure
        $grouped = [];
        foreach ($riskLimits as $risk) {
            $symbol = $risk['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            if (! isset($grouped[$symbol])) {
                $grouped[$symbol] = [
                    'symbol' => $symbol,
                    'brackets' => [],
                ];
            }

            // Transform Bybit risk limit structure to match Binance bracket structure
            $grouped[$symbol]['brackets'][] = [
                'bracket' => (int) ($risk['id'] ?? 0),
                'initialLeverage' => isset($risk['maxLeverage']) ? (int) $risk['maxLeverage'] : 0,
                'notionalCap' => isset($risk['riskLimitValue']) ? (float) $risk['riskLimitValue'] : 0,
                'notionalFloor' => 0, // Bybit doesn't provide floor, calculate from previous tier if needed
                'maintMarginRatio' => isset($risk['maintenanceMargin']) ? (float) $risk['maintenanceMargin'] : 0,
                'cum' => 0, // Bybit doesn't provide cum value directly
            ];
        }

        return array_values($grouped);
    }
}
