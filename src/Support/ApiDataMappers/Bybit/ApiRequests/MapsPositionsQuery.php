<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

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

    public function resolveQueryPositionsResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        // Bybit V5 response structure: { result: { list: [...] } }
        $positionsList = $body['result']['list'] ?? [];

        $positions = collect($positionsList)
            ->map(function ($position) {
                // Normalize symbol from 'BTCUSDT' to 'BTC/USDT' format
                if (isset($position['symbol'])) {
                    $parts = $this->identifyBaseAndQuote($position['symbol']);
                    $position['symbol'] = $parts['base'].'/'.$parts['quote'];
                }

                return $position;
            })
            ->keyBy(function ($position) {
                // Key by symbol:direction to support hedge mode (LONG + SHORT on same symbol)
                // Bybit uses 'side' with Buy/Sell values
                $side = mb_strtoupper($position['side'] ?? 'BOTH');
                $direction = $side === 'BUY' ? 'LONG' : ($side === 'SELL' ? 'SHORT' : 'BOTH');

                return $position['symbol'].':'.$direction;
            })
            ->toArray();

        // Remove positions with zero size (Bybit uses 'size' field)
        $positions = array_filter($positions, function ($position) {
            return (float) ($position['size'] ?? 0) !== 0.0;
        });

        return $positions;
    }
}
