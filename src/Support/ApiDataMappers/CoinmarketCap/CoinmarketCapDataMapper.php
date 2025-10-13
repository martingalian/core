<?php

namespace Martingalian\Core\Support\ApiDataMappers\CoinmarketCap;

use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests\MapsSyncMarketData;

class CoinmarketCapDataMapper extends BaseDataMapper
{
    use MapsSyncMarketData;
}
