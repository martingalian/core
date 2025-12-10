<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;

/**
 * MarkBinanceOverlapForExchangeSymbolsJob
 *
 * Marks exchange symbols with `overlaps_with_binance` flag to indicate whether
 * the token exists on Binance. This is important because TAAPI indicators use
 * Binance data as reference - tokens that don't exist on Binance cannot have
 * indicator data.
 *
 * Logic:
 * - Binance symbols: overlaps_with_binance = true (they ARE the reference)
 * - Other exchanges: true if token exists on Binance, false otherwise
 */
final class MarkBinanceOverlapForExchangeSymbolsJob extends BaseQueueableJob
{
    public function relatable()
    {
        return null;
    }

    public function compute()
    {
        return DB::transaction(function () {
            // Get Binance api_system
            $binanceSystem = ApiSystem::where('canonical', 'binance')->first();

            if (! $binanceSystem) {
                return [
                    'error' => 'Binance API system not found',
                    'binance_marked' => 0,
                    'overlapping_marked' => 0,
                    'non_overlapping_marked' => 0,
                ];
            }

            // Get all Binance tokens
            $binanceTokens = ExchangeSymbol::where('api_system_id', $binanceSystem->id)
                ->pluck('token')
                ->toArray();

            // Mark all Binance symbols as overlapping (they ARE the reference)
            $binanceMarked = ExchangeSymbol::where('api_system_id', $binanceSystem->id)
                ->update(['overlaps_with_binance' => true]);

            // Mark other exchanges: true if token exists on Binance
            $overlappingMarked = ExchangeSymbol::where('api_system_id', '!=', $binanceSystem->id)
                ->whereIn('token', $binanceTokens)
                ->update(['overlaps_with_binance' => true]);

            // Mark other exchanges: false if token does NOT exist on Binance
            $nonOverlappingMarked = ExchangeSymbol::where('api_system_id', '!=', $binanceSystem->id)
                ->whereNotIn('token', $binanceTokens)
                ->update(['overlaps_with_binance' => false]);

            return [
                'binance_marked' => $binanceMarked,
                'overlapping_marked' => $overlappingMarked,
                'non_overlapping_marked' => $nonOverlappingMarked,
                'total_binance_tokens' => count($binanceTokens),
                'message' => sprintf(
                    'Marked %d Binance symbols, %d overlapping symbols, %d non-overlapping symbols',
                    $binanceMarked,
                    $overlappingMarked,
                    $nonOverlappingMarked
                ),
            ];
        });
    }
}
