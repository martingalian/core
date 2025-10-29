<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class Quote extends BaseModel
{
    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    public function positions()
    {
        return $this->hasManyThrough(Position::class, ExchangeSymbol::class, 'quote_id', 'exchange_symbol_id', 'id', 'id');
    }
}
