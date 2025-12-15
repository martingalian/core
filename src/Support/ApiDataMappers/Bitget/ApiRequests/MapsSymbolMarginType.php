<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    public function prepareSymbolMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');
        $properties->set('options.marginMode', 'crossed');

        return $properties;
    }

    /**
     * Resolves BitGet set margin mode response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "marginCoin": "USDT",
     *         "marginMode": "crossed"
     *     }
     * }
     */
    public function resolveSymbolMarginTypeResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return $body['data'] ?? [];
    }
}
