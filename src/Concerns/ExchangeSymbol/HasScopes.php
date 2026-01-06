<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    /**
     * Symbols that can be used to open positions.
     * Checks: overlaps with Binance, linked to CMC symbol, manually enabled, has TAAPI data,
     * has direction, respects cooldowns, no behavioral flags, and has correlation data
     * for the symbol's concluded timeframe.
     */
    public function scopeTradeable(Builder $query): Builder
    {
        $correlationType = config('martingalian.token_discovery.correlation_type', 'rolling');
        $correlationColumn = 'btc_correlation_'.$correlationType;

        return $query->where('exchange_symbols.overlaps_with_binance', true)
            ->where('exchange_symbols.api_statuses->has_taapi_data', true)
            ->where('exchange_symbols.has_no_indicator_data', false)
            ->where('exchange_symbols.is_marked_for_delisting', false)
            ->where('exchange_symbols.has_price_trend_misalignment', false)
            ->where('exchange_symbols.has_early_direction_change', false)
            ->where('exchange_symbols.has_invalid_indicator_direction', false)
            ->whereNotNull('exchange_symbols.symbol_id')
            ->where(static function ($q) {
                $q->whereNull('exchange_symbols.is_manually_enabled')
                    ->orWhere('exchange_symbols.is_manually_enabled', true);
            })
            ->whereNotNull('exchange_symbols.direction')
            ->where(static function ($q) {
                $q->whereNull('exchange_symbols.tradeable_at')
                    ->orWhere('exchange_symbols.tradeable_at', '<=', now());
            })
            // Must have correlation data for the symbol's concluded timeframe
            ->whereNotNull('exchange_symbols.indicators_timeframe')
            ->whereRaw(
                "JSON_EXTRACT(exchange_symbols.{$correlationColumn}, CONCAT('$.\"', exchange_symbols.indicators_timeframe, '\"')) IS NOT NULL"
            );
    }

    /**
     * Symbols that should receive price updates via WebSocket.
     */
    public function scopeNeedsPriceUpdates(Builder $query): Builder
    {
        return $query->where(static function ($q) {
            // Has indicator data OR we're still trying to get it
            $q->where('exchange_symbols.has_no_indicator_data', false)
            // And not explicitly disabled by admin (NULL or true, but not false)
                ->where(static function ($q2) {
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
            ->whereHas('apiSystem', static function ($q) {
                $q->where('canonical', 'binance');
            });
    }
}
