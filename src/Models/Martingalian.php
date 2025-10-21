<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Martingalian\HasAccessors;

final class Martingalian extends BaseModel
{
    use HasAccessors;
    use HasDebuggable;
    use HasLoggable;

    protected $table = 'martingalian';

    protected $casts = [
        'should_kill_order_events' => 'boolean',
        'allow_opening_positions' => 'boolean',

        'binance_api_key' => 'encrypted',
        'binance_api_secret' => 'encrypted',
        'bybit_api_key' => 'encrypted',
        'bybit_api_secret' => 'encrypted',
        'coinmarketcap_api_key' => 'encrypted',
        'taapi_secret' => 'encrypted',
    ];
}
