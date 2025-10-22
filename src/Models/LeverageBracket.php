<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class LeverageBracket extends BaseModel
{
    protected $casts = [
        'source_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
