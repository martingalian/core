<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * Note: Kraken uses symbol and resolution in the URL path.
     * Note: Kraken symbols have a PF_ prefix for perpetuals (e.g., PF_XBTUSD).
     * Note: Kraken API accepts timestamps in milliseconds for from/to parameters.
     * Note: Kraken doesn't have a limit param - we calculate time range from limit.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-ohlc/
     */
    public function prepareQueryKlinesProperties(
        ExchangeSymbol $exchangeSymbol,
        string $resolution = '5m',
        ?int $startTime = null,
        ?int $endTime = null,
        ?int $limit = null
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);
        $properties->set('options.resolution', $resolution);

        // Kraken doesn't support a limit param - use time range instead
        // Note: Kraken API accepts from/to in SECONDS (not milliseconds)
        // If limit is set and no explicit time range, calculate from limit
        if ($limit !== null && $startTime === null && $endTime === null) {
            $intervalSeconds = $this->getIntervalSeconds($resolution);
            $nowSec = time();
            $fromSec = $nowSec - ($limit * $intervalSeconds);

            $properties->set('options.from', $fromSec);
            $properties->set('options.to', $nowSec);
        } else {
            if ($startTime !== null) {
                $properties->set('options.from', $startTime);
            }

            if ($endTime !== null) {
                $properties->set('options.to', $endTime);
            }
        }

        return $properties;
    }

    /**
     * Convert interval string to seconds.
     */
    private function getIntervalSeconds(string $interval): int
    {
        $map = [
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '30m' => 1800,
            '1h' => 3600,
            '4h' => 14400,
            '12h' => 43200,
            '1d' => 86400,
            '1w' => 604800,
        ];

        return $map[$interval] ?? 300;
    }

    /**
     * Resolve klines response into normalized array.
     *
     * Kraken returns: { candles: [{time, open, high, low, close, volume}, ...] }
     * Note: Kraken returns objects, not arrays.
     * Note: Kraken timestamps are already in milliseconds.
     *
     * @return array<int, array{timestamp: int, open: string, high: string, low: string, close: string, volume: string}>
     */
    public function resolveQueryKlinesResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $list = $data['candles'] ?? [];

        if (! is_array($list)) {
            return [];
        }

        $normalized = [];

        foreach ($list as $candle) {
            if (! is_array($candle)) {
                continue;
            }

            // Kraken already returns timestamps in milliseconds
            $normalized[] = [
                'timestamp' => (int) ($candle['time'] ?? 0),
                'open' => (string) ($candle['open'] ?? '0'),
                'high' => (string) ($candle['high'] ?? '0'),
                'low' => (string) ($candle['low'] ?? '0'),
                'close' => (string) ($candle['close'] ?? '0'),
                'volume' => (string) ($candle['volume'] ?? '0'),
            ];
        }

        return $normalized;
    }
}
