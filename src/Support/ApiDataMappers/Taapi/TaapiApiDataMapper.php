<?php

namespace Martingalian\Core\Support\ApiDataMappers\Taapi;

use Martingalian\Core\Support\ApiDataMappers\Taapi\ApiRequests\MapsGroupedQueryIndicators;
use Martingalian\Core\Support\ApiDataMappers\Taapi\ApiRequests\MapsQueryIndicator;

class TaapiApiDataMapper
{
    use MapsGroupedQueryIndicators;
    use MapsQueryIndicator;

    public function baseWithQuote(string $token, string $quote): string
    {
        return $token.'/'.$quote;
    }
}
