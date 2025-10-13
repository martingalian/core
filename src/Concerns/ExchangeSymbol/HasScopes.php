<?php

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeTradeable(Builder $query)
    {
        return $query->active()
            ->where('exchange_symbols.is_tradeable', true)
            ->whereNotNull('exchange_symbols.direction')
            ->where(fn ($q) => $q
                ->whereNull('exchange_symbols.tradeable_at')
                ->orWhere('exchange_symbols.tradeable_at', '<=', now()));
    }

    public function scopeActive(Builder $query)
    {
        return $query->where('exchange_symbols.is_active', true);
    }
}
