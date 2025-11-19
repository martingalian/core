<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Database\Factories\QuoteFactory;

/**
 * @property int $id
 * @property string $canonical
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Quote extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }

    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    public function positions()
    {
        return $this->hasManyThrough(Position::class, ExchangeSymbol::class, 'quote_id', 'exchange_symbol_id', 'id', 'id');
    }
}
