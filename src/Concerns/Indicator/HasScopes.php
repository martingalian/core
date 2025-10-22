<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Indicator;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeActive(Builder $query)
    {
        return $query->where('indicators.is_active', true);
    }

    public function scopeApiable(Builder $query)
    {
        return $query->where('indicators.is_apiable', true);
    }
}
