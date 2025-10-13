<?php

namespace Martingalian\Core\Concerns\TradeConfiguration;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeDefault(Builder $query)
    {
        return $query->where('trade_configuration.is_default', true);
    }
}
