<?php

namespace Martingalian\Core\Concerns\Symbol;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeIncomplete(Builder $query)
    {
        return $query->whereNull('symbols.name')
            ->orWhereNull('symbols.description')
            ->orWhereNull('symbols.site_url')
            ->orWhereNull('symbols.image_url');
    }
}
