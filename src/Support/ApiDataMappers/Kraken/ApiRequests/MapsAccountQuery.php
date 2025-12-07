<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves Kraken account response.
     *
     * Kraken Futures response structure:
     * {
     *     "result": "success",
     *     "accounts": {
     *         "flex": {
     *             "balances": { "usdt": 1000.0, "xbt": 0.1 },
     *             "collateralValue": 10000.0,
     *             "marginRequirements": {
     *                 "im": 500.0,
     *                 "mm": 250.0
     *             },
     *             "availableFunds": 5000.0,
     *             "unrealizedPnl": 100.0,
     *             "pnl": 50.0
     *         }
     *     }
     * }
     */
    public function resolveQueryAccountResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        $accounts = $data['accounts'] ?? [];
        $flexAccount = $accounts['flex'] ?? [];

        if (empty($flexAccount)) {
            return [];
        }

        // Map Kraken fields to match Binance structure for consistency
        $marginRequirements = $flexAccount['marginRequirements'] ?? [];

        return [
            'totalWalletBalance' => (string) ($flexAccount['collateralValue'] ?? '0'),
            'totalUnrealizedProfit' => (string) ($flexAccount['unrealizedPnl'] ?? '0'),
            'totalMaintMargin' => (string) ($marginRequirements['mm'] ?? '0'),
            'totalMarginBalance' => (string) ($flexAccount['collateralValue'] ?? '0'),
            'availableFunds' => (string) ($flexAccount['availableFunds'] ?? '0'),
            'initialMargin' => (string) ($marginRequirements['im'] ?? '0'),
        ];
    }
}
