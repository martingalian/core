<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsTokenLeverageRatios
{
    /**
     * Prepare properties for updating leverage preferences on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/set-leverage-setting/
     *
     * Kraken combines margin mode + leverage in one endpoint:
     * - Setting maxLeverage = ISOLATED margin with that leverage
     * - Omitting maxLeverage = CROSS margin (dynamic leverage based on wallet balance)
     *
     * For CROSSED margin mode, we only set the symbol (no maxLeverage).
     * For ISOLATED margin mode, we set both symbol and maxLeverage.
     */
    public function prepareUpdateLeverageRatioProperties(Position $position, int $leverage): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        // Only set maxLeverage for ISOLATED margin mode
        // For CROSSED, omitting maxLeverage tells Kraken to use cross margin
        if ($position->account->margin_mode === 'isolated') {
            $properties->set('options.maxLeverage', (string) $leverage);
        }

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
        $data = json_decode((string) $response->getBody(), associative: true);

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
