<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Account;

use Illuminate\Support\Collection;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Symbol;

/*
 * Trait HasTokenDiscovery
 *
 * Purpose:
 * - Assigns the most optimal ExchangeSymbol to each "new" position using BTC bias-based selection.
 * - Uses BTC's current direction and timeframe as the basis for scoring and selecting tokens.
 *
 * BTC Bias Algorithm:
 * When BTC has a direction signal (LONG or SHORT):
 *   1. Get BTC's indicators_timeframe (e.g., "4h")
 *   2. Score tokens using: elasticity × |correlation| on that timeframe
 *   3. Optionally filter by correlation sign based on direction alignment
 *
 * Correlation Sign Rules:
 * - BTC=LONG + Position=LONG   → Want POSITIVE correlation (token rises WITH BTC)
 * - BTC=LONG + Position=SHORT  → Want NEGATIVE correlation (token falls AGAINST BTC)
 * - BTC=SHORT + Position=LONG  → Want NEGATIVE correlation (token rises AGAINST BTC)
 * - BTC=SHORT + Position=SHORT → Want POSITIVE correlation (token falls WITH BTC)
 *
 * Rule: (BTC direction == position direction) → want POSITIVE correlation
 *
 * Priority System:
 *   1. Fast-tracked tokens (recently profitable positions) - skip correlation check
 *   2. Best BTC bias score (elasticity × |correlation|) with correlation sign filtering
 *
 * Fallback Behavior (when BTC has no direction):
 * - If btc_biased_restriction=true: Delete all position slots (STRICT mode)
 * - If btc_biased_restriction=false: Use non-BTC algorithm across all timeframes (RELAXED mode)
 *
 * Usage Requirements:
 * - positions() relationship returning Position models
 * - tradeConfiguration property for timeframes
 * - availableExchangeSymbols() method returning usable ExchangeSymbols
 * - fastTrackedPositions() returning recently profitable positions
 */
trait HasTokenDiscovery
{
    /*
     * Collection of currently eligible ExchangeSymbols.
     * Filtered and updated during assignment process.
     */
    public Collection $availableExchangeSymbols;

    /*
     * Tracking string for assigned tokens (used for logging/reporting).
     */
    public string $tokens = '';

    /*
     * Current position being processed.
     */
    public Position $positionReference;

    public function assignBestTokenToNewPositions()
    {
        /*
         * BTC Bias-Based Token Assignment Algorithm
         *
         * Flow:
         * 1. Load available exchange symbols pool
         * 2. Get BTC ExchangeSymbol (same api_system_id and quote)
         * 3. Check BTC direction:
         *    - HAS direction: Use BTC bias algorithm with BTC's timeframe
         *    - NO direction: Check btc_biased_restriction config
         *      - true: Delete all slots, return (STRICT)
         *      - false: Fallback to non-BTC algorithm (RELAXED)
         * 4. For each position:
         *    a) Priority 1: Fast-tracked symbols (direction check only)
         *    b) Priority 2: BTC bias scoring OR fallback scoring
         *    c) Delete unassigned slots
         */

        // Reset tokens string for each call
        $this->tokens = '';

        /*
         * Step 1: Load Available Exchange Symbols Pool
         *
         * availableExchangeSymbols() returns symbols that:
         * - Are tradeable (is_active=1, is_tradeable=1, has direction)
         * - Match account's trading_quote (usually USDT)
         * - Are NOT already in opened positions for this account (local DB)
         *
         * We then filter to only include symbols from this account's exchange.
         */
        $this->availableExchangeSymbols = $this->availableExchangeSymbols()
            ->where('api_system_id', $this->api_system_id);

        /*
         * Step 1b: Exclude Tokens Already Open on Exchange
         *
         * Check api_snapshots for both:
         * - 'account-positions': Open positions on exchange
         * - 'account-open-orders': Pending orders on exchange
         *
         * Keys in account-positions are formatted as 'BTCUSDT:LONG'.
         * Orders in account-open-orders have 'symbol' field (e.g., 'BTCUSDT').
         */
        $openTradingPairs = collect();

        // Check open positions
        $openPositionsOnExchange = ApiSnapshot::getFrom($this, 'account-positions') ?? [];
        $positionPairs = collect(array_keys($openPositionsOnExchange))
            ->map(static function (string $key): string {
                // Extract trading pair from 'BTCUSDT:LONG' -> 'BTCUSDT'
                return explode(':', $key)[0];
            });
        $openTradingPairs = $openTradingPairs->merge($positionPairs);

        // Check open orders
        $openOrdersOnExchange = ApiSnapshot::getFrom($this, 'account-open-orders') ?? [];
        $orderPairs = collect($openOrdersOnExchange)
            ->pluck('symbol')
            ->filter();
        $openTradingPairs = $openTradingPairs->merge($orderPairs);

        $openTradingPairs = $openTradingPairs->unique()->values();

        if ($openTradingPairs->isNotEmpty()) {
            $this->availableExchangeSymbols = $this->availableExchangeSymbols
                ->filter(static function (ExchangeSymbol $symbol) use ($openTradingPairs): bool {
                    return ! $openTradingPairs->contains($symbol->parsed_trading_pair);
                });
        }

        /*
         * Step 2: Filter Pool - Only Complete Symbols
         *
         * Filter symbols that have:
         * - Complete trading metadata (min_notional, tick_size, etc.)
         * - Complete correlation/elasticity data
         */
        $correlationType = config('martingalian.token_discovery.correlation_type', 'rolling');
        $correlationField = 'btc_correlation_'.$correlationType;

        $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(static function ($symbol) use ($correlationField) {
            return filled($symbol->min_notional)
                && filled($symbol->tick_size)
                && filled($symbol->price_precision)
                && filled($symbol->quantity_precision)
                && filled($symbol->btc_elasticity_long)
                && filled($symbol->btc_elasticity_short)
                && filled($symbol->{$correlationField});
        });

        /*
         * Step 3: Get BTC ExchangeSymbol for BTC Bias
         *
         * Find BTC's ExchangeSymbol with:
         * - Same api_system_id (same exchange: Binance/Bybit)
         * - Same quote (same trading pair quote: USDT)
         *
         * BTC provides:
         * - direction: LONG/SHORT/null (market bias signal)
         * - indicators_timeframe: Which timeframe the signal was computed on
         */
        $btcSymbol = Symbol::where('token', config('martingalian.correlation.btc_token', 'BTC'))->first();
        $btcExchangeSymbol = null;

        if ($btcSymbol) {
            $btcExchangeSymbol = ExchangeSymbol::query()
                ->where('symbol_id', $btcSymbol->id)
                ->where('api_system_id', $this->api_system_id)
                ->where('quote', $this->trading_quote)
                ->first();
        }

        /*
         * Step 4: Get New Positions Ready for Token Assignment
         *
         * Query positions where:
         * - status = 'new' (freshly created)
         * - direction IS NOT NULL (set by earlier job)
         * - exchange_symbol_id IS NULL (no token assigned yet)
         */
        $newPositions = $this->positions()
            ->where('positions.status', 'new')
            ->whereNotNull('positions.direction')
            ->whereNull('positions.exchange_symbol_id')
            ->get();

        /*
         * Step 5: Check BTC Direction - Determines Algorithm Path
         */
        $btcDirection = $btcExchangeSymbol?->direction;
        $btcTimeframe = $btcExchangeSymbol?->indicators_timeframe;
        $btcBiasedRestriction = config('martingalian.token_discovery.btc_biased_restriction', true);

        /*
         * If BTC has NO direction and btc_biased_restriction=true:
         * Delete all position slots and return (STRICT mode)
         */
        if (! $btcDirection && $btcBiasedRestriction) {
            $this->deleteUnassignedPositionSlots();

            return '';
        }

        /*
         * Determine algorithm mode:
         * - useBtcBias=true: Use single timeframe from BTC with correlation sign logic
         * - useBtcBias=false: Use fallback algorithm (all timeframes, no sign filtering)
         */
        $useBtcBias = filled($btcDirection) && filled($btcTimeframe);

        /*
         * Step 6: Initialize Batch Exclusions Tracking
         */
        $batchExclusions = [];

        /*
         * Step 7: Iterate Each Position and Assign Best Token
         */
        foreach ($newPositions as $position) {
            $this->positionReference = $position;
            $direction = $position->direction;
            $bestToken = null;

            /*
             * Priority 1: Fast-Tracked Symbols
             *
             * Fast-tracked positions are those that:
             * - Closed recently (within last hour by default)
             * - Had quick duration (<10 minutes by default)
             * - Were profitable
             *
             * Fast-tracked symbols ONLY verify direction match.
             * They skip correlation/elasticity checks entirely.
             */
            $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction, $batchExclusions);
            if ($fastTrackedSymbol) {
                $bestToken = $fastTrackedSymbol;
            }

            /*
             * Priority 2: BTC Bias Scoring OR Fallback Scoring
             */
            if (! $bestToken) {
                if ($useBtcBias) {
                    /*
                     * BTC Bias Algorithm:
                     * - Use BTC's timeframe only
                     * - Apply correlation sign filtering based on direction alignment
                     * - Score: elasticity × |correlation|
                     */
                    $bestToken = $this->selectBestTokenByBtcBias(
                        $direction,
                        $btcDirection,
                        $btcTimeframe,
                        $batchExclusions
                    );
                } else {
                    /*
                     * Fallback Algorithm (when BTC has no direction):
                     * - Iterate ALL timeframes from TradeConfiguration
                     * - No correlation sign filtering
                     * - Score: elasticity × |correlation| (best across all timeframes)
                     */
                    $bestToken = $this->selectBestTokenFallback($direction, $batchExclusions);
                }
            }

            /*
             * No Token Available - Skip Position
             *
             * Position will be deleted below along with other unassigned slots.
             */
            if (! $bestToken) {
                continue;
            }

            /*
             * Assign Token to Position
             */
            $this->tokens .= $bestToken->parsed_trading_pair.'-'.$bestToken->direction.' ';

            $position->updateSaving([
                'exchange_symbol_id' => $bestToken->id,
                'direction' => $bestToken->direction,
            ]);

            $position->updateSaving([
                'parsed_trading_pair' => $position->getParsedTradingPair(),
            ]);

            $batchExclusions[] = $bestToken->id;
        }

        /*
         * Step 8: Delete Unassigned Position Slots
         *
         * Clean up positions that couldn't be assigned a token.
         */
        $this->deleteUnassignedPositionSlots();

        return $this->tokens;
    }

    public function getFastTrackedSymbolForDirection(string $direction, array $batchExclusions)
    {
        /*
         * Fast-Track Symbol Selection
         *
         * Purpose:
         * Prioritize tokens from recently profitable quick trades.
         * Assumes tokens that closed profitably in <10 minutes still have momentum.
         *
         * IMPORTANT: Fast-tracked symbols ONLY check:
         * 1. Direction match (verify it hasn't changed since the fast trade)
         * 2. Not in batch exclusions
         * 3. Available in pool
         *
         * They SKIP correlation/elasticity checks entirely.
         * This is intentional - proven momentum trumps theoretical scoring.
         */

        $fastTracked = $this->fastTrackedPositions()->where('direction', $direction);

        if ($fastTracked->isNotEmpty()) {
            foreach ($fastTracked as $trackedPosition) {
                if (in_array($trackedPosition->exchange_symbol_id, $batchExclusions)) {
                    continue;
                }

                /*
                 * Check if Symbol Still Available AND Direction Matches
                 *
                 * Direction check is critical - the symbol's direction may have
                 * changed since the fast trade. We only want symbols that
                 * STILL have the same direction signal.
                 */
                $symbol = $this->availableExchangeSymbols
                    ->where('direction', $direction)
                    ->whereNotIn('id', $batchExclusions)
                    ->first(static function ($availableSymbol) use ($trackedPosition) {
                        return $availableSymbol->id === $trackedPosition->exchange_symbol_id;
                    });

                if ($symbol) {
                    return $symbol;
                }
            }
        }

        return null;
    }

    public function selectBestTokenByBtcBias(
        string $positionDirection,
        string $btcDirection,
        string $timeframe,
        array $batchExclusions
    ) {
        /*
         * ═══════════════════════════════════════════════════════════════════════════════
         * BTC BIAS-BASED TOKEN SELECTION ALGORITHM
         * ═══════════════════════════════════════════════════════════════════════════════
         *
         * Purpose:
         * Select the optimal trading token based on BTC's current direction and timeframe.
         * Uses correlation sign alignment to maximize position profitability.
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * CORRELATION SIGN RULES
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * | BTC Direction | Position Direction | Desired Correlation |
         * |---------------|-------------------|---------------------|
         * | LONG          | LONG              | POSITIVE (token rises WITH BTC) |
         * | LONG          | SHORT             | NEGATIVE (token falls AGAINST BTC) |
         * | SHORT         | LONG              | NEGATIVE (token rises AGAINST BTC) |
         * | SHORT         | SHORT             | POSITIVE (token falls WITH BTC) |
         *
         * Rule: (BTC direction == position direction) → want POSITIVE correlation
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * SCORING FORMULA (Single Timeframe from BTC)
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * For LONG positions:  score = elasticity_long[timeframe] × |correlation[timeframe]|
         * For SHORT positions: score = |elasticity_short[timeframe]| × |correlation[timeframe]|
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         */

        $correlationType = config('martingalian.token_discovery.correlation_type', 'rolling');
        $correlationField = 'btc_correlation_'.$correlationType;
        $requireMatchingSign = config('martingalian.token_discovery.require_matching_correlation_sign', true);

        /*
         * Determine desired correlation sign:
         * - Same direction (BTC=LONG, Position=LONG): want POSITIVE
         * - Opposite direction (BTC=LONG, Position=SHORT): want NEGATIVE
         */
        $wantPositiveCorrelation = ($btcDirection === $positionDirection);

        /*
         * Filter Candidate Symbols
         */
        $candidates = $this->availableExchangeSymbols
            ->where('direction', $positionDirection)
            ->whereNotIn('id', $batchExclusions);

        if ($candidates->isEmpty()) {
            return null;
        }

        /*
         * Score Each Candidate on BTC's Timeframe Only
         */
        $scoredSymbols = $candidates->map(static function ($symbol) use (
            $positionDirection,
            $timeframe,
            $correlationField,
            $requireMatchingSign,
            $wantPositiveCorrelation
        ) {
            /*
             * Validate Data Availability for BTC's Timeframe
             */
            if (! isset($symbol->btc_elasticity_long[$timeframe])
                || ! isset($symbol->btc_elasticity_short[$timeframe])
                || ! isset($symbol->{$correlationField}[$timeframe])) {
                return null;
            }

            $elasticityLong = $symbol->btc_elasticity_long[$timeframe];
            $elasticityShort = $symbol->btc_elasticity_short[$timeframe];
            $correlation = $symbol->{$correlationField}[$timeframe];

            /*
             * Apply Correlation Sign Filter (if enabled)
             *
             * If require_matching_correlation_sign=true:
             * - Skip symbols with wrong correlation sign
             * - This ensures high conviction trades
             *
             * If require_matching_correlation_sign=false:
             * - Don't filter by sign
             * - Always find a token if any available
             */
            if ($requireMatchingSign) {
                $hasCorrectSign = $wantPositiveCorrelation
                    ? ($correlation > 0)
                    : ($correlation < 0);

                if (! $hasCorrectSign) {
                    return null;
                }
            }

            /*
             * Calculate Score Using Direction-Specific Formula
             */
            if ($positionDirection === 'SHORT') {
                $score = abs($elasticityShort) * abs($correlation);
            } else {
                $score = $elasticityLong * abs($correlation);
            }

            return [
                'symbol' => $symbol,
                'score' => $score,
                'timeframe' => $timeframe,
                'correlation' => $correlation,
            ];
        })->filter();

        if ($scoredSymbols->isEmpty()) {
            return null;
        }

        /*
         * Sort by Score and Return Best Symbol
         */
        $best = $scoredSymbols->sortByDesc('score')->first();

        return $best ? $best['symbol'] : null;
    }

    public function selectBestTokenFallback(string $direction, array $batchExclusions)
    {
        /*
         * ═══════════════════════════════════════════════════════════════════════════════
         * FALLBACK TOKEN SELECTION ALGORITHM (No BTC Direction)
         * ═══════════════════════════════════════════════════════════════════════════════
         *
         * Purpose:
         * Select optimal token when BTC has no direction signal.
         * Uses all timeframes and ignores correlation sign (no BTC bias to align with).
         *
         * Scoring Formula:
         * For LONG:  score = elasticity_long × |correlation| (best across all timeframes)
         * For SHORT: score = |elasticity_short| × |correlation| (best across all timeframes)
         *
         * This is the "RELAXED" mode when btc_biased_restriction=false.
         */

        $correlationType = config('martingalian.token_discovery.correlation_type', 'rolling');
        $correlationField = 'btc_correlation_'.$correlationType;

        $candidates = $this->availableExchangeSymbols
            ->where('direction', $direction)
            ->whereNotIn('id', $batchExclusions);

        if ($candidates->isEmpty()) {
            return null;
        }

        /*
         * Score Each Candidate Across ALL Timeframes
         */
        $scoredSymbols = $candidates->map(function ($symbol) use ($direction, $correlationField) {
            $timeframes = $this->tradeConfiguration->indicator_timeframes;

            $bestScore = 0;
            $bestTimeframe = null;

            foreach ($timeframes as $timeframe) {
                if (! isset($symbol->btc_elasticity_long[$timeframe])
                    || ! isset($symbol->btc_elasticity_short[$timeframe])
                    || ! isset($symbol->{$correlationField}[$timeframe])) {
                    continue;
                }

                $elasticityLong = $symbol->btc_elasticity_long[$timeframe];
                $elasticityShort = $symbol->btc_elasticity_short[$timeframe];
                $correlation = $symbol->{$correlationField}[$timeframe];

                if ($direction === 'SHORT') {
                    $score = abs($elasticityShort) * abs($correlation);
                } else {
                    $score = $elasticityLong * abs($correlation);
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTimeframe = $timeframe;
                }
            }

            return [
                'symbol' => $symbol,
                'score' => $bestScore,
                'timeframe' => $bestTimeframe,
            ];
        });

        $best = $scoredSymbols->sortByDesc('score')->first();

        return $best ? $best['symbol'] : null;
    }

    public function deleteUnassignedPositionSlots(): int
    {
        /*
         * Delete Unassigned Position Slots
         *
         * Removes positions that:
         * - status = 'new'
         * - exchange_symbol_id IS NULL (no token was assigned)
         *
         * This happens when:
         * - BTC has no direction and btc_biased_restriction=true
         * - No tokens match the correlation sign requirement
         * - All available tokens were already assigned in this batch
         *
         * Returns: Number of deleted positions
         */
        $unassignedPositions = $this->positions()
            ->where('positions.status', 'new')
            ->whereNull('positions.exchange_symbol_id')
            ->get();

        $deletedCount = 0;

        foreach ($unassignedPositions as $position) {
            $position->forceDelete();
            $deletedCount++;
        }

        return $deletedCount;
    }
}
