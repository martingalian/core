<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Notification;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCanonical(Builder $query, string $canonical): Builder
    {
        return $query->where('canonical', $canonical);
    }
}
