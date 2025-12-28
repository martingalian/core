<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * Note: BitGet uses `granularity` with suffix (e.g., "5m" for 5 minutes).
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-Candle-Data
     */
    public function prepareQueryKlinesProperties(
        ExchangeSymbol $exchangeSymbol,
        string $granularity = '5m',
        ?int $startTime = null,
        ?int $endTime = null,
        ?int $limit = null
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);
        $properties->set('options.granularity', $granularity);
        $properties->set('options.productType', 'USDT-FUTURES');

        if ($startTime !== null) {
            $properties->set('options.startTime', $startTime);
        }

        if ($endTime !== null) {
            $properties->set('options.endTime', $endTime);
        }

        if ($limit !== null) {
            $properties->set('options.limit', $limit);
        }

        return $properties;
    }

    /**
     * Resolve klines response into normalized array.
     *
     * BitGet returns: { data: [["ts", "o", "h", "l", "c", "vol", "quoteVol"], ...] }
     * Note: Response data is array of string arrays.
     *
     * @return array<int, array{timestamp: int, open: string, high: string, low: string, close: string, volume: string}>
     */
    public function resolveQueryKlinesResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $list = $data['data'] ?? [];

        if (! is_array($list)) {
            return [];
        }

        $normalized = [];

        foreach ($list as $candle) {
            if (! is_array($candle) || count($candle) < 6) {
                continue;
            }

            $normalized[] = [
                'timestamp' => (int) $candle[0],
                'open' => (string) $candle[1],
                'high' => (string) $candle[2],
                'low' => (string) $candle[3],
                'close' => (string) $candle[4],
                'volume' => (string) $candle[5],
            ];
        }

        return $normalized;
    }
}
