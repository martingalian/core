<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Account;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeTradeable(Builder $query)
    {
        return $query->where('accounts.can_trade', true);
    }
}
