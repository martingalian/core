<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsCancelOrders
{
    public function prepareCancelOrdersProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    public function resolveCancelOrdersResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }
}
