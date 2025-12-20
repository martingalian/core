<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsPositionsQuery
{
    public function prepareQueryPositionsProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves Kraken open positions response.
     *
     * Kraken Futures response structure:
     * {
     *     "result": "success",
     *     "openPositions": [
     *         {
     *             "side": "long",
     *             "symbol": "PF_XBTUSD",
     *             "price": 30000.0,
     *             "fillTime": "2024-01-15T10:30:00.000Z",
     *             "size": 1000,
     *             "unrealizedPnl": 100.0
     *         }
     *     ]
     * }
     */
    public function resolveQueryPositionsResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        $positionsList = $body['openPositions'] ?? [];

        $positions = collect($positionsList)
            ->map(function ($position) {
                // Normalize the symbol format
                if (isset($position['symbol'])) {
                    $parts = $this->identifyBaseAndQuote($position['symbol']);
                    $position['symbol'] = $this->baseWithQuote($parts['base'], $parts['quote']);
                }

                return $position;
            })
            ->keyBy(static function ($position) {
                // Key by symbol:direction to support hedge mode (LONG + SHORT on same symbol)
                // Kraken uses 'side' with long/short values
                $side = mb_strtoupper($position['side'] ?? 'BOTH');
                $direction = $side === 'LONG' ? 'LONG' : ($side === 'SHORT' ? 'SHORT' : 'BOTH');

                return $position['symbol'].':'.$direction;
            })
            ->toArray();

        // Remove positions with zero size
        return array_filter($positions, static function ($position) {
            return (float) ($position['size'] ?? 0) !== 0.0;
        });
    }
}
