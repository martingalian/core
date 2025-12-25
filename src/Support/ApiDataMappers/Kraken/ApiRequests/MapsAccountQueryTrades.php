<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQueryTrades
{
    /**
     * Prepare properties for querying trade fills on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-fills/
     */
    public function prepareQueryTokenTradesProperties(Position $position, ?string $orderId = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);

        // Kraken fills endpoint doesn't filter by symbol or orderId directly
        // We'll filter the results in the resolve method if needed
        if (! is_null($orderId)) {
            $properties->set('options.orderId', (string) $orderId);
        }

        // lastFillTime can be used to filter recent fills
        // $properties->set('options.lastFillTime', 'timestamp');

        return $properties;
    }

    /**
     * Resolve the trade fills response from Kraken.
     *
     * Kraken fills response structure:
     * {
     *     "result": "success",
     *     "fills": [
     *         {
     *             "fill_id": "abc123",
     *             "symbol": "PF_XBTUSD",
     *             "side": "buy",
     *             "size": 1000,
     *             "price": 45000.0,
     *             "fillTime": "2024-01-15T10:30:00.000Z",
     *             "order_id": "order123",
     *             "fillType": "maker"
     *         }
     *     ]
     * }
     */
    public function resolveQueryTradeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $fills = $data['fills'] ?? [];

        return array_map(callback: static function (array $fill): array {
            return [
                'id' => $fill['fill_id'] ?? null,
                'symbol' => $fill['symbol'] ?? null,
                'orderId' => $fill['order_id'] ?? null,
                'side' => isset($fill['side']) ? mb_strtoupper($fill['side']) : null,
                'price' => (string) ($fill['price'] ?? '0'),
                'qty' => (string) ($fill['size'] ?? '0'),
                'commission' => (string) ($fill['fee'] ?? '0'),
                'commissionAsset' => $fill['feeCurrency'] ?? null,
                'time' => $fill['fillTime'] ?? null,
                'maker' => ($fill['fillType'] ?? '') === 'maker',
                '_raw' => $fill,
            ];
        }, array: $fills);
    }
}
