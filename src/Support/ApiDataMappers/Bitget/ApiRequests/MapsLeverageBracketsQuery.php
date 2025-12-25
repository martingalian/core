<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsLeverageBracketsQuery
{
    public function prepareLeverageBracketsQueryProperties(ApiSystem $apiSystem): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $apiSystem);
        $properties->set('options.productType', 'USDT-FUTURES');

        return $properties;
    }

    /**
     * Resolves BitGet contracts response (leverage brackets).
     *
     * BitGet doesn't have a direct leverage brackets endpoint like Binance.
     * Instead, we use the contracts endpoint which returns maxLever per symbol.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "data": [
     *         {
     *             "symbol": "BTCUSDT",
     *             "maxLever": "125",
     *             "minLever": "1",
     *             ...
     *         }
     *     ]
     * }
     */
    public function resolveLeverageBracketsQueryResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);

        return $body['data'] ?? [];
    }
}
