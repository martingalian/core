<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Account;

use Illuminate\Support\Collection;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Position;

/*
 * Trait HasTokenDiscovery
 *
 * Purpose:
 * - Assigns the most optimal ExchangeSymbol to each "new" position based on correlation & elasticity metrics.
 * - Implements an intelligent symbol selection strategy:
 *   1. Priority 1: Fast-tracked tokens (recently profitable positions)
 *   2. Priority 2: Best elasticity/correlation score for the position's direction
 *
 * Selection Algorithm:
 * - SHORT positions: Maximize (elasticity_short * correlation_rolling) across all timeframes
 * - LONG positions: Maximize ((elasticity_long - elasticity_short) * correlation_rolling)
 *
 * Usage Requirements:
 * - positions() relationship returning Position models
 * - tradeConfiguration property for timeframes
 * - availableExchangeSymbols() method returning usable ExchangeSymbols
 * - fastTrackedPositions() returning recently profitable positions
 *
 * Workflow:
 * 1. Load available exchange symbols (exclude already opened positions)
 * 2. Filter by complete metadata + elasticity data
 * 3. Get all "new" positions with direction pre-set (by earlier job)
 * 4. For each position:
 *    a) Check fast-tracked symbols first
 *    b) Calculate best elasticity-based symbol
 *    c) Prevent duplicate assignments in same batch
 * 5. Unassigned positions will be deleted by another job
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
         * New Algorithm: Correlation & Elasticity Based Token Assignment
         *
         * Flow:
         * 1. Load available exchange symbols pool (exclude already opened positions)
         * 2. Filter by complete metadata + elasticity data
         * 3. Get all "new" positions with direction already set
         * 4. For each position:
         *    a) Priority 1: Fast-tracked symbols (recently profitable)
         *    b) Priority 2: Best elasticity-based symbol for direction
         *    c) Exclude already assigned symbols in this batch
         * 5. Positions without assignment will be deleted by another job
         */

        /*
         * Step 1: Load Available Exchange Symbols Pool
         *
         * availableExchangeSymbols() method (from HasCollections trait) returns symbols that:
         * - Are tradeable (is_active=1, is_tradeable=1, has direction)
         * - Match account's trading_quote_id (usually USDT)
         * - Are NOT already in opened positions for this account
         *   (opened statuses: opening, waping, active, new, closing, cancelling, watching)
         *
         * This ensures we don't assign the same token to multiple positions.
         */
        $this->availableExchangeSymbols = $this->availableExchangeSymbols();

        /*
         * Step 2: Filter Pool - Only Complete Symbols
         *
         * Additional filtering to ensure symbols have:
         * - Complete trading metadata (min_notional, tick_size, price_precision, quantity_precision)
         *   Required for order placement calculations
         * - Complete correlation/elasticity data (btc_elasticity_long, btc_elasticity_short, btc_correlation_rolling)
         *   Required for scoring algorithm
         *
         * Symbols missing any of this data are excluded from assignment.
         */
        $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(function ($symbol) {
            return filled($symbol->min_notional)
                && filled($symbol->tick_size)
                && filled($symbol->price_precision)
                && filled($symbol->quantity_precision)
                && filled($symbol->btc_elasticity_long)
                && filled($symbol->btc_elasticity_short)
                && filled($symbol->btc_correlation_rolling);
        });

        /*
         * Step 3: Get New Positions Ready for Token Assignment
         *
         * Query positions where:
         * - status = 'new' (freshly created, awaiting token assignment)
         * - direction IS NOT NULL (set by earlier job based on market conditions)
         * - exchange_symbol_id IS NULL (no token assigned yet)
         *
         * Important: Direction is pre-determined by another job before this runs.
         * This job only selects the BEST token for each pre-assigned direction.
         */
        $newPositions = $this->positions()
            ->where('positions.status', 'new')
            ->whereNotNull('positions.direction')
            ->whereNull('positions.exchange_symbol_id')
            ->get();

        /*
         * Step 4: Initialize Batch Exclusions Tracking
         *
         * Track symbols assigned during this execution to prevent duplicates.
         * Each assigned symbol ID is added to this array.
         *
         * Example flow:
         * - Position #1 assigned SQD (id=161) → batchExclusions = [161]
         * - Position #2 cannot select SQD → batchExclusions = [161]
         * - Position #2 assigned SPX (id=143) → batchExclusions = [161, 143]
         * - Position #3 cannot select SQD or SPX → batchExclusions = [161, 143]
         */
        $batchExclusions = [];

        /*
         * Step 5: Iterate Each Position and Assign Best Token
         *
         * For each position:
         * 1. Check fast-tracked symbols first (recently profitable)
         * 2. If none available, calculate best elasticity-based symbol
         * 3. Skip position if no symbols available (will be deleted by another job)
         * 4. Assign token and add to exclusions
         */
        foreach ($newPositions as $position) {
            // Store current position reference for potential use in other methods
            $this->positionReference = $position;

            // Get the pre-determined direction (LONG or SHORT)
            $direction = $position->direction;

            $bestToken = null;

            /*
             * Priority 1: Fast-Tracked Symbols
             *
             * Fast-tracked positions are those that:
             * - Closed recently (within last hour by default)
             * - Had quick duration (<10 minutes by default)
             * - Were profitable (implied by fast close)
             *
             * Reusing these symbols assumes they have strong momentum.
             * This is the HIGHEST priority - overrides elasticity scoring.
             */
            $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction, $batchExclusions);
            if ($fastTrackedSymbol) {
                $bestToken = $fastTrackedSymbol;
            }

            /*
             * Priority 2: Elasticity-Based Selection
             *
             * If no fast-tracked symbol available, calculate best symbol based on:
             * - Correlation & elasticity metrics across all timeframes
             * - Direction-specific scoring (different formulas for LONG vs SHORT)
             * - Excludes symbols already assigned in this batch
             */
            if (! $bestToken) {
                $bestToken = $this->selectBestTokenByElasticity($direction, $batchExclusions);
            }

            /*
             * No Token Available - Skip Position
             *
             * Reasons this might happen:
             * - All symbols of this direction already assigned in this batch
             * - All symbols of this direction already in opened positions
             * - No symbols have required elasticity data
             *
             * Another job will delete unassigned "new" positions later.
             */
            if (! $bestToken) {
                continue;
            }

            /*
             * Assign Token to Position
             *
             * 1. Add to tracking string for logging/reporting
             * 2. Update position with exchange_symbol_id and direction
             * 3. Update position with parsed_trading_pair (formatted for exchange API)
             * 4. Add to batch exclusions to prevent reuse
             */

            // Build tracking string (e.g., "SQD/USDT-SHORT IOTA/USDT-LONG")
            $this->tokens .= $bestToken->parsed_trading_pair.'-'.$bestToken->direction.' ';

            // Update position with assigned token and direction
            $position->updateSaving([
                'exchange_symbol_id' => $bestToken->id,
                'direction' => $bestToken->direction,
            ]);

            // Update position with parsed trading pair for exchange API
            // Example: "SQDUSTD" for Binance, "SQDUSD" for Bybit
            $position->updateSaving([
                'parsed_trading_pair' => $position->getParsedTradingPair(),
            ]);

            // Prevent reuse of this symbol in subsequent iterations
            $batchExclusions[] = $bestToken->id;
        }

        // Return comma-separated list of assigned tokens for logging
        return $this->tokens;
    }

    protected function getFastTrackedSymbolForDirection(string $direction, array $batchExclusions)
    {
        /*
         * Fast-Track Symbol Selection
         *
         * Purpose:
         * Prioritize tokens from recently profitable quick trades.
         * Assumes tokens that closed profitably in <10 minutes still have momentum.
         *
         * Fast-Track Criteria (defined in TradeConfiguration):
         * - Position closed recently (< fast_trade_position_closed_age_seconds, default 3600s = 1 hour)
         * - Position had quick duration (<= fast_trade_position_duration_seconds, default 600s = 10 minutes)
         * - Position is non-active (status: closed, cancelled, failed)
         *
         * Selection Process:
         * 1. Get fast-tracked positions matching the direction
         * 2. Iterate in order (most recent first)
         * 3. Check if symbol is still available and not in batch exclusions
         * 4. Return first match found
         *
         * @param string $direction LONG or SHORT
         * @param array $batchExclusions Symbol IDs already assigned in this batch
         * @return ExchangeSymbol|null Fast-tracked symbol if available, null otherwise
         */

        /*
         * Get Fast-Tracked Positions for Direction
         *
         * fastTrackedPositions() (from HasCollections trait) returns positions where:
         * - closed_at >= now() - fast_trade_position_closed_age_seconds (default: last hour)
         * - duration <= fast_trade_position_duration_seconds (default: 10 minutes)
         * - duration >= 0 (sanity check)
         * - status IN (closed, cancelled, failed)
         *
         * Then filter by matching direction.
         */
        $fastTracked = $this->fastTrackedPositions()->where('direction', $direction);

        if ($fastTracked->isNotEmpty()) {
            /*
             * Iterate Fast-Tracked Positions
             *
             * Check each fast-tracked position to see if its symbol is:
             * 1. Not already assigned in this batch (batch exclusions)
             * 2. Still available in the pool (not in opened positions)
             * 3. Matches the required direction
             *
             * Return the first matching symbol found.
             */
            foreach ($fastTracked as $trackedPosition) {
                /*
                 * Skip if Already Assigned in This Batch
                 *
                 * Prevents assigning the same symbol to multiple positions
                 * in this execution cycle.
                 */
                if (in_array($trackedPosition->exchange_symbol_id, $batchExclusions)) {
                    continue;
                }

                /*
                 * Check if Symbol Still Available
                 *
                 * Filter available symbols by:
                 * - Direction matches (LONG or SHORT)
                 * - Not in batch exclusions
                 * - ID matches the fast-tracked position's exchange_symbol_id
                 *
                 * Returns first match or null.
                 */
                $symbol = $this->availableExchangeSymbols
                    ->where('direction', $direction)
                    ->whereNotIn('id', $batchExclusions)
                    ->first(function ($availableSymbol) use ($trackedPosition) {
                        return $availableSymbol->id === $trackedPosition->exchange_symbol_id;
                    });

                if ($symbol) {
                    // Fast-tracked symbol found and available - return immediately
                    return $symbol;
                }
            }
        }

        // No fast-tracked symbols available for this direction
        return null;
    }

    protected function selectBestTokenByElasticity(string $direction, array $batchExclusions)
    {
        /*
         * ═══════════════════════════════════════════════════════════════════════════════
         * ELASTICITY-BASED TOKEN SELECTION ALGORITHM
         * ═══════════════════════════════════════════════════════════════════════════════
         *
         * Purpose:
         * Select the optimal trading token based on correlation and elasticity metrics
         * across all available timeframes (1h, 4h, 6h, 12h, 1d).
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * SCORING FORMULAS
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * FOR SHORT POSITIONS:
         * score = abs(elasticity_short) × abs(correlation_rolling)
         *
         * Goal: Maximize downside amplification when BTC falls
         * - elasticity_short: How much token falls relative to BTC downward movement
         * - correlation_rolling: Reliability/predictability of movement
         *
         * Example (SQD/USDT):
         *   Timeframe: 1d
         *   elasticity_short = 25.96 (falls 26x harder than BTC)
         *   correlation_rolling = 0.48
         *   score = 25.96 × 0.48 = 12.46
         *
         *   When BTC falls 1%, SQD falls ~26% with 48% correlation reliability.
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * FOR LONG POSITIONS:
         * asymmetry = elasticity_long - elasticity_short
         * score = asymmetry × abs(correlation_rolling)
         *
         * Goal: Maximize upside while minimizing downside (asymmetric risk/reward)
         * - elasticity_long: Upside capture when BTC rises
         * - elasticity_short: Downside risk when BTC falls
         * - asymmetry: The difference (higher = better risk/reward)
         * - correlation_rolling: Reliability/predictability
         *
         * Example (MYX/USDT):
         *   Timeframe: 6h
         *   elasticity_long = 29.3 (rises 29x more than BTC)
         *   elasticity_short = -57.4 (RISES when BTC falls - inverted!)
         *   asymmetry = 29.3 - (-57.4) = 86.7
         *   correlation_rolling = 0.52
         *   score = 86.7 × 0.52 = 45.08
         *
         *   When BTC rises 1%, MYX rises ~29%. When BTC falls 1%, MYX RISES ~57%!
         *   Perfect for longs - profits in both directions.
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * ALGORITHM FLOW
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * 1. Filter candidates:
         *    - ExchangeSymbol.direction matches position direction
         *    - Not in batch exclusions (already assigned)
         *
         * 2. For each candidate symbol:
         *    a) Iterate through ALL timeframes (1h, 4h, 6h, 12h, 1d)
         *    b) Calculate score for each timeframe using formulas above
         *    c) Track the BEST score across all timeframes
         *    d) Store: symbol, best_score, best_timeframe
         *
         * 3. Sort all symbols by best_score (descending)
         *
         * 4. Return symbol with highest score
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         * KEY INSIGHTS
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * - We evaluate EVERY timeframe for EVERY symbol
         * - Symbol's final score is its BEST timeframe score (not average)
         * - This finds tokens with exceptional performance on at least one timeframe
         * - Correlation weighting ensures predictability (high elasticity + low correlation = unreliable)
         *
         * Example Comparison (SHORT):
         *   SQD: Best on 1d (score 12.46) - extreme downside on daily moves
         *   SPX: Best on 6h (score 6.39) - strong downside on 6h moves
         *   ENA: Best on 1d (score 3.39) - moderate downside on daily moves
         *   → SQD wins (highest score)
         *
         * Example Comparison (LONG):
         *   MYX: Best on 6h (score 45.08) - inverted correlation, profits both ways
         *   ZEC: Best on 4h (score 3.85) - good upside, minimal downside
         *   DASH: Best on 1h (score 3.20) - similar to ZEC
         *   → MYX wins (exceptional asymmetry)
         *
         * ─────────────────────────────────────────────────────────────────────────────────
         *
         * @param string $direction LONG or SHORT
         * @param array $batchExclusions Symbol IDs already assigned in this batch
         * @return ExchangeSymbol|null Best symbol for direction, or null if none available
         *
         * ═══════════════════════════════════════════════════════════════════════════════
         */

        /*
         * Step 1: Filter Candidate Symbols
         *
         * Get symbols that:
         * - Match the required direction (LONG or SHORT)
         * - Are not in batch exclusions (prevent duplicates)
         *
         * These are pulled from availableExchangeSymbols which already excludes:
         * - Symbols in opened positions
         * - Symbols without complete metadata
         * - Symbols without elasticity data
         */
        $candidates = $this->availableExchangeSymbols
            ->where('direction', $direction)
            ->whereNotIn('id', $batchExclusions);

        // No candidates available for this direction
        if ($candidates->isEmpty()) {
            return null;
        }

        /*
         * Step 2: Calculate Best Score for Each Symbol Across All Timeframes
         *
         * For each symbol:
         * 1. Loop through all configured timeframes (from TradeConfiguration)
         * 2. Calculate score for each timeframe using direction-specific formula
         * 3. Track the BEST score and which timeframe produced it
         * 4. Return array with symbol, best score, and best timeframe
         */
        $scoredSymbols = $candidates->map(function ($symbol) use ($direction) {
            // Get configured timeframes (e.g., ["1h", "4h", "6h", "12h", "1d"])
            $timeframes = $this->tradeConfiguration->indicator_timeframes;

            // Track best score across all timeframes for this symbol
            $bestScore = 0;
            $bestTimeframe = null;

            /*
             * Iterate Through All Timeframes
             *
             * Goal: Find which timeframe gives this symbol its best score
             */
            foreach ($timeframes as $timeframe) {
                /*
                 * Validate Data Availability
                 *
                 * Skip timeframe if missing any required metrics:
                 * - btc_elasticity_long[timeframe]
                 * - btc_elasticity_short[timeframe]
                 * - btc_correlation_rolling[timeframe]
                 *
                 * Some symbols may not have all timeframes populated yet.
                 */
                if (! isset($symbol->btc_elasticity_long[$timeframe])
                    || ! isset($symbol->btc_elasticity_short[$timeframe])
                    || ! isset($symbol->btc_correlation_rolling[$timeframe])) {
                    continue;
                }

                // Extract metrics for this timeframe
                $elasticityLong = $symbol->btc_elasticity_long[$timeframe];
                $elasticityShort = $symbol->btc_elasticity_short[$timeframe];
                $correlation = $symbol->btc_correlation_rolling[$timeframe];

                /*
                 * Calculate Score Using Direction-Specific Formula
                 */
                if ($direction === 'SHORT') {
                    /*
                     * SHORT Score Formula
                     *
                     * score = abs(elasticity_short) × abs(correlation_rolling)
                     *
                     * Goal: Maximize downside amplification
                     * - Higher elasticity_short = token falls harder when BTC falls
                     * - Higher correlation = more predictable/reliable behavior
                     * - Use abs() to handle negative values correctly
                     *
                     * Example:
                     *   elasticity_short = 25.96 (SQD on 1d)
                     *   correlation = 0.48
                     *   score = 25.96 × 0.48 = 12.46
                     */
                    $score = abs($elasticityShort) * abs($correlation);
                } else {
                    /*
                     * LONG Score Formula
                     *
                     * asymmetry = elasticity_long - elasticity_short
                     * score = asymmetry × abs(correlation_rolling)
                     *
                     * Goal: Maximize upside while minimizing downside
                     * - elasticity_long = upside when BTC rises
                     * - elasticity_short = downside when BTC falls
                     * - asymmetry = difference (higher = better risk/reward)
                     *
                     * Why this works:
                     * - If elasticity_long = 10 and elasticity_short = 2:
                     *   asymmetry = 10 - 2 = 8 (captures 10x upside, only 2x downside)
                     * - If elasticity_short is NEGATIVE (inverted correlation):
                     *   asymmetry = 10 - (-20) = 30 (captures upside AND rises when BTC falls!)
                     *
                     * Example (normal):
                     *   elasticity_long = 5.7 (ZEC on 4h)
                     *   elasticity_short = 1.6
                     *   asymmetry = 5.7 - 1.6 = 4.1
                     *   correlation = 0.68
                     *   score = 4.1 × 0.68 = 2.79
                     *
                     * Example (inverted - best case):
                     *   elasticity_long = 29.3 (MYX on 6h)
                     *   elasticity_short = -57.4 (RISES when BTC falls!)
                     *   asymmetry = 29.3 - (-57.4) = 86.7
                     *   correlation = 0.52
                     *   score = 86.7 × 0.52 = 45.08 ← HUGE SCORE
                     */
                    $asymmetry = $elasticityLong - $elasticityShort;
                    $score = $asymmetry * abs($correlation);
                }

                /*
                 * Track Best Timeframe for This Symbol
                 *
                 * If this timeframe's score beats the current best,
                 * update bestScore and bestTimeframe.
                 *
                 * Result: Each symbol gets scored by its BEST performing timeframe.
                 */
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestTimeframe = $timeframe;
                }
            }

            /*
             * Return Scored Symbol Data
             *
             * Returns associative array with:
             * - symbol: The ExchangeSymbol model
             * - score: Best score across all timeframes
             * - timeframe: Which timeframe produced the best score
             */
            return [
                'symbol' => $symbol,
                'score' => $bestScore,
                'timeframe' => $bestTimeframe,
            ];
        });

        /*
         * Step 3: Sort by Score and Return Best Symbol
         *
         * Sort all scored symbols by score (descending - highest first).
         * Return the ExchangeSymbol with the highest score.
         *
         * Example:
         *   Scored symbols for SHORT:
         *   - SQD: score 12.46 (best on 1d) ← Winner
         *   - SPX: score 6.39 (best on 6h)
         *   - ENA: score 3.39 (best on 1d)
         *
         *   Scored symbols for LONG:
         *   - MYX: score 45.08 (best on 6h) ← Winner
         *   - ZEC: score 3.85 (best on 4h)
         *   - DASH: score 3.20 (best on 1h)
         */
        $best = $scoredSymbols->sortByDesc('score')->first();

        // Return the best symbol, or null if no symbols had valid scores
        return $best ? $best['symbol'] : null;
    }
}
