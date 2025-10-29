<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class PriceHistory extends BaseModel
{
    protected $table = 'price_history';

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
