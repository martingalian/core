<?php

namespace Martingalian\Core\Concerns\ApiSystem;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public static function scopeExchange(Builder $query)
    {
        return $query->where('api_systems.is_exchange', true);
    }
}
