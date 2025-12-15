<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    /**
     * Prepare properties for updating margin mode on KuCoin Futures.
     *
     * Note: KuCoin uses ISOLATED/CROSS while Binance uses ISOLATED/CROSSED.
     *
     * @see https://www.kucoin.com/docs-new/rest/futures-trading/positions/switch-margin-mode
     */
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        // KuCoin uses 'CROSS' not 'CROSSED'
        $properties->set('options.marginMode', 'CROSS');

        return $properties;
    }

    /**
     * Resolve the update margin type response from KuCoin.
     *
     * KuCoin response structure:
     * {
     *     "code": "200000",
     *     "data": true
     * }
     */
    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return [
            'success' => ($data['data'] ?? false) === true,
            '_raw' => $data,
        ];
    }
}
