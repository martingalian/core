<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * Symbols that can be used to open positions.
     * Checks: manually enabled, auto-enabled, has TAAPI data, has direction, respects cooldowns.
     */
    public function scopeTradeable(Builder $query): Builder
    {
        return $query->where('exchange_symbols.api_statuses->has_taapi_data', true)
            ->where('exchange_symbols.auto_disabled', false)
            ->where(function ($q) {
                $q->whereNull('exchange_symbols.is_manually_enabled')
                    ->orWhere('exchange_symbols.is_manually_enabled', true);
            })
            ->whereNotNull('exchange_symbols.direction')
            ->where(fn ($q) => $q
                ->whereNull('exchange_symbols.tradeable_at')
                ->orWhere('exchange_symbols.tradeable_at', '<=', now()));
    }

    /**
     * Symbols that should receive price updates via WebSocket.
     */
    public function scopeNeedsPriceUpdates(Builder $query): Builder
    {
        return $query->where(function ($q) {
            // Has indicator data OR we're still trying to get it
            $q->where(function ($q2) {
                $q2->where('exchange_symbols.auto_disabled_reason', '!=', 'no_indicator_data')
                    ->orWhereNull('exchange_symbols.auto_disabled_reason');
            })
            // And not explicitly disabled by admin (NULL or true, but not false)
            ->where(function ($q2) {
                $q2->whereNull('exchange_symbols.is_manually_enabled')
                    ->orWhere('exchange_symbols.is_manually_enabled', true);
            });
        });
    }

    /**
     * Symbols that should attempt to fetch indicators.
     * Only Binance symbols can query TAAPI - other exchanges receive copied results.
     */
    public function scopeNeedsIndicatorAttempt(Builder $query): Builder
    {
        return $query
            ->where('exchange_symbols.api_statuses->has_taapi_data', true)
            ->whereHas('apiSystem', function ($q) {
                $q->where('canonical', 'binance');
            });
    }
}
