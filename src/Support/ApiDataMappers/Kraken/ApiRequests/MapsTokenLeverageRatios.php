<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsTokenLeverageRatios
{
    /**
     * Prepare properties for updating leverage ratio on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/set-leverage-setting/
     *
     * Note: Setting maxLeverage automatically sets margin mode to ISOLATED.
     * To use CROSS margin, don't set maxLeverage (use MapsSymbolMarginType instead).
     */
    public function prepareUpdateLeverageRatioProperties(Position $position, int $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);
        $properties->set('options.maxLeverage', (string) $leverage);

        return $properties;
    }

    /**
     * Resolve the update leverage response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "serverTime": "2024-01-15T10:30:00.000Z"
     * }
     */
    public function resolveUpdateLeverageRatioResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return [
            'result' => $data['result'] ?? 'unknown',
            'serverTime' => $data['serverTime'] ?? null,
            'symbol' => null, // Kraken doesn't return symbol in response
            'leverage' => null, // Kraken doesn't return the new leverage in response
            'maxNotionalValue' => null,
            '_raw' => $data,
        ];
    }
}
