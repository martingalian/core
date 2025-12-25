<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    /**
     * Prepare properties for switching margin mode on Bybit.
     *
     * Note: Bybit uses tradeMode: 0 = cross, 1 = isolated.
     * Also requires buyLeverage and sellLeverage to be set.
     *
     * @see https://bybit-exchange.github.io/docs/v5/position/cross-isolate
     */
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.category', 'linear');
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        // 0 = cross margin, 1 = isolated margin - default to cross
        $properties->set('options.tradeMode', 0);
        // Must set leverage when switching margin mode
        $leverage = (string) ($position->leverage ?? 10);
        $properties->set('options.buyLeverage', $leverage);
        $properties->set('options.sellLeverage', $leverage);

        return $properties;
    }

    /**
     * Resolve the switch margin mode response from Bybit.
     *
     * Bybit V5 response structure:
     * {
     *     "retCode": 0,
     *     "retMsg": "OK",
     *     "result": {},
     *     "time": 1672281607343
     * }
     */
    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return [
            'success' => ($data['retCode'] ?? -1) === 0,
            '_raw' => $data,
        ];
    }
}
