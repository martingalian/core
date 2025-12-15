<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsTokenLeverageRatios
{
    public function prepareTokenLeverageRatiosProperties(Position $position, string $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.productType', 'USDT-FUTURES');
        $properties->set('options.marginCoin', 'USDT');
        $properties->set('options.leverage', (string) $leverage);
        $properties->set('options.holdSide', $this->directionType($position->direction));

        return $properties;
    }

    /**
     * Resolves BitGet set leverage response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "data": {
     *         "symbol": "BTCUSDT",
     *         "marginCoin": "USDT",
     *         "longLeverage": "20",
     *         "shortLeverage": "20"
     *     }
     * }
     */
    public function resolveTokenLeverageRatiosResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return $body['data'] ?? [];
    }
}
