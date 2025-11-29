<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Database\Factories\CandleFactory;

final class Candle extends BaseModel
{
    use HasFactory;

    protected $casts = [
        'created_at' => 'datetime',
        'candle_time' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }

    protected static function newFactory(): CandleFactory
    {
        return CandleFactory::new();
    }
}
