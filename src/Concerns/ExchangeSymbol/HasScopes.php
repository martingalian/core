<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * Symbols that can be used to open NEW positions.
     */
    public function scopeTradeableForNewPositions(Builder $query): Builder
    {
        return $query->where(function ($q) {
            // Admin explicitly enabled (bypasses most system checks)
            $q->where('exchange_symbols.is_manually_enabled', true)
                ->where(function ($q2) {
                    // But still can't bypass missing indicator data
                    $q2->where('exchange_symbols.auto_disabled', false)
                        ->orWhere('exchange_symbols.auto_disabled_reason', '!=', 'no_indicator_data');
                })
                // OR default state (no manual override, not auto-disabled)
                ->orWhere(function ($q2) {
                    $q2->whereNull('exchange_symbols.is_manually_enabled')
                        ->where('exchange_symbols.auto_disabled', false);
                });
        })
        ->whereNotNull('exchange_symbols.direction')
        ->where(fn ($q) => $q
            ->whereNull('exchange_symbols.tradeable_at')
            ->orWhere('exchange_symbols.tradeable_at', '<=', now()));
    }

    /**
     * Symbols that can be used for ANY trading operation
     * (queries, closes, updates - includes symbols with active positions).
     */
    public function scopeTradeableForOperations(Builder $query): Builder
    {
        return $query->where(function ($q) {
            // Not explicitly disabled by admin (NULL or true, but not false)
            $q->where(function ($q2) {
                $q2->whereNull('exchange_symbols.is_manually_enabled')
                    ->orWhere('exchange_symbols.is_manually_enabled', true);
            })
            // And either not auto-disabled, or manually overridden
            ->where(function ($q2) {
                $q2->where('exchange_symbols.auto_disabled', false)
                    ->orWhere('exchange_symbols.is_manually_enabled', true);
            });
        });
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
     */
    public function scopeNeedsIndicatorAttempt(Builder $query): Builder
    {
        return $query->where(function ($q) {
            // Never tried before
            $q->whereNull('exchange_symbols.indicators_synced_at')
                // OR tried >7 days ago and might have data now
                ->orWhere('exchange_symbols.indicators_synced_at', '<', now()->subDays(7));
        })
        // But not if admin explicitly disabled it (NULL or true, but not false)
        ->where(function ($q) {
            $q->whereNull('exchange_symbols.is_manually_enabled')
                ->orWhere('exchange_symbols.is_manually_enabled', true);
        });
    }

    /**
     * Symbols to skip when fetching indicators (permanently no data).
     */
    public function scopeSkipIndicators(Builder $query): Builder
    {
        return $query->where('exchange_symbols.auto_disabled', true)
            ->where('exchange_symbols.auto_disabled_reason', 'no_indicator_data')
            ->whereNotNull('exchange_symbols.indicators_synced_at');
    }

    /**
     * Legacy compatibility: Alias for TradeableForNewPositions.
     * @deprecated Use tradeableForNewPositions() instead
     */
    public function scopeTradeable(Builder $query): Builder
    {
        return $this->scopeTradeableForNewPositions($query);
    }

    /**
     * Legacy compatibility: Symbols that are not auto-disabled.
     * @deprecated Use specific scopes based on your use case
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('exchange_symbols.auto_disabled', false);
    }
}
