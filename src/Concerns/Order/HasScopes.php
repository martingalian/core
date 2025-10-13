<?php

namespace Martingalian\Core\Concerns\Order;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeCancellable(Builder $query)
    {
        return $query->whereIn('type', ['LIMIT', 'STOP-LOSS', 'PROFIT-LIMIT', 'PROFIT-MARKET']);
    }

    public function scopeActiveOnExchange($query)
    {
        return $query->whereNotNull('orders.exchange_order_id')
            ->whereIn('orders.status', ['NEW', 'FILLED', 'PARTIALLY_FILLED']);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('orders.reference_status', ['NEW', 'FILLED', 'PARTIALLY_FILLED']);
    }

    public function scopeReferencedActive($query)
    {
        return $query->whereIn('orders.reference_status', ['NEW', 'FILLED', 'PARTIALLY_FILLED']);
    }

    public function scopeCancelled($query)
    {
        return $query->where('orders.reference_status', 'CANCELLED');
    }
}
