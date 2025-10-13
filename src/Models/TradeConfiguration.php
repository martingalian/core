<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\TradeConfiguration\HasGetters;
use Martingalian\Core\Concerns\TradeConfiguration\HasScopes;

class TradeConfiguration extends BaseModel
{
    use HasDebuggable;
    use HasGetters;
    use HasLoggable;
    use HasScopes;

    protected $table = 'trade_configuration';

    protected $casts = [
        'is_default' => 'boolean',
        'disable_exchange_symbol_from_negative_pnl_position' => 'boolean',

        'indicator_timeframes' => 'array',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
}
