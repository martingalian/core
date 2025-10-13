<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class PriceHistory extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    protected $table = 'price_history';

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
