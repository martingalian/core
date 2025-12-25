<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsSymbolMarginType
{
    /**
     * Prepare properties for updating margin type on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/set-leverage-setting/
     *
     * Note: Kraken uses the same endpoint for leverage and margin type.
     * - Setting maxLeverage = ISOLATED margin
     * - Not setting maxLeverage (or setting to null) = CROSS margin
     *
     * To set CROSS margin, we call the endpoint without maxLeverage parameter.
     */
    public function prepareUpdateMarginTypeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        // Kraken: omitting maxLeverage sets margin mode to CROSS
        // The symbol parameter alone is sufficient to reset to cross margin

        return $properties;
    }

    /**
     * Resolve the update margin type response from Kraken.
     *
     * Kraken response structure:
     * {
     *     "result": "success",
     *     "serverTime": "2024-01-15T10:30:00.000Z"
     * }
     */
    public function resolveUpdateMarginTypeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return [
            'result' => $data['result'] ?? 'unknown',
            'serverTime' => $data['serverTime'] ?? null,
            'marginMode' => 'crossed', // We're setting to cross margin
            '_raw' => $data,
        ];
    }
}
