<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsKlinesQuery
{
    /**
     * Prepare properties for klines query.
     *
     * Note: KuCoin uses `granularity` in minutes (e.g., 5 for 5 minutes).
     * Note: KuCoin symbols have an 'M' suffix for perpetuals (e.g., XBTUSDTM).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-klines
     */
    public function prepareQueryKlinesProperties(
        ExchangeSymbol $exchangeSymbol,
        string $interval = '5m',
        ?int $startTime = null,
        ?int $endTime = null,
        ?int $limit = null
    ): ApiProperties {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);
        $properties->set('options.granularity', $this->convertIntervalForKucoin($interval));

        if ($startTime !== null) {
            $properties->set('options.from', $startTime);
        }

        if ($endTime !== null) {
            $properties->set('options.to', $endTime);
        }

        return $properties;
    }

    /**
     * Resolve klines response into normalized array.
     *
     * KuCoin returns: { data: [[time, open, high, low, close, volume], ...] }
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

    /**
     * Convert canonical interval format to KuCoin granularity (in minutes).
     *
     * KuCoin uses integer minutes for granularity.
     */
    private function convertIntervalForKucoin(string $interval): int
    {
        // Map canonical intervals to minutes
        $map = [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '2h' => 120,
            '4h' => 240,
            '8h' => 480,
            '12h' => 720,
            '1d' => 1440,
            '1w' => 10080,
        ];

        return $map[$interval] ?? 5;
    }
}
