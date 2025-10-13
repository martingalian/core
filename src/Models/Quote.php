<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class Quote extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    public function positions()
    {
        return $this->hasManyThrough(Position::class, ExchangeSymbol::class, 'quote_id', 'exchange_symbol_id', 'id', 'id');
    }
}
