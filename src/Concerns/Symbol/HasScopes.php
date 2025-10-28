<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Symbol;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeIncomplete(Builder $query)
    {
        return $query->whereNull('symbols.name');
    }
}
