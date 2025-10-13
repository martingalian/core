<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

class Candle extends BaseModel
{
    protected $casts = [
        'created_at' => 'datetime',
        'candle_time' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
