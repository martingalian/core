<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\CoinmarketCap;

use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests\MapsSyncMarketData;

final class CoinmarketCapDataMapper extends BaseDataMapper
{
    use MapsSyncMarketData;
}
