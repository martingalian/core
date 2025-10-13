<?php

namespace Martingalian\Core\Concerns\Account;

use Illuminate\Support\Collection;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Position;

/*
 * Trait HasTokenDiscovery
 *
 * Purpose:
 * - Assigns the most optimal ExchangeSymbol to each "new" position.
 * - Implements a symbol selection strategy based on:
 *   - Reuse of fast-tracked (recently profitable) tokens.
 *   - Indicator timeframes defined by the trade configuration.
 *   - Directional constraints (LONG / SHORT).
 *   - Capacity constraints (max open positions allowed).
 *
 * Usage:
 * - Requires the model to define:
 *   - positions() relationship returning Position models.
 *   - tradeConfiguration property for timeframes and limits.
 *   - availableExchangeSymbols() method returning usable ExchangeSymbols.
 *   - fastTrackedPositions() returning past successful positions.
 *
 * Workflow:
 * 1. Load all available exchange symbols.
 * 2. Generate an internal grouped map: [timeframe][direction] = [symbol_ids].
 * 3. Find all 'new' positions without an assigned exchange symbol.
 * 4. For each, try to:
 *    - Use fast-tracked token matching direction.
 *    - Else pick the best token based on timeframes and randomization.
 *    - Stop early if position limits (LONG/SHORT) are hit.
 */
trait HasTokenDiscovery
{
    /*
     * Internal structured map of available symbols.
     * Format: [timeframe][direction] = [exchange_symbol_ids]
     */
    public $sortedExchangeSymbols = [];

    /*
     * Collection of currently eligible ExchangeSymbols.
     * This is mutated throughout the assignment process.
     */
    public Collection $availableExchangeSymbols;

    public string $tokens = '';

    public Position $positionReference;

    public function assignBestTokenToNewPositions()
    {
        /*
         * Step 1: Load and prepare the exchange symbol pool.
         *         Then generate a direction/timeframe index map.
         */
        $this->availableExchangeSymbols = $this->availableExchangeSymbols();

        $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(function ($symbol) {
            return filled($symbol->min_notional)
                && filled($symbol->tick_size)
                && filled($symbol->price_precision)
                && filled($symbol->quantity_precision);
        });

        $this->generateStructuredDataFromAvailableExchangeSymbols();

        /*
         * Step 2: Load all new positions that haven't been assigned a token.
         */
        $newPositions = $this->positions()
            ->where('positions.status', 'new')
            ->whereNull('exchange_symbol_id')
            ->get();

        /*
         * Step 3: Try to assign each new position a symbol.
         *         Break if LONG and SHORT limits are both reached.
         */
        foreach ($newPositions as $position) {
            // info("Picking best token for Position ID {$position->id}");
            $this->positionReference = $position;

            $bestToken = null;

            if ($this->canOpen('LONG')) {
                $bestToken = $this->selectBestToken('LONG');
            }

            if (! $bestToken && $this->canOpen('SHORT')) {
                $bestToken = $this->selectBestToken('SHORT');
            }

            if (! $bestToken) {
                break;
            }

            if ($bestToken) {
                $this->tokens .= $bestToken->parsed_trading_pair.'-'.$bestToken->direction.' ';

                $position->logApplicationEvent(
                    "Best token {$bestToken->parsed_trading_pair} updated on position ID {$position->id} with direction {$bestToken->direction}",
                    self::class,
                    __FUNCTION__
                );

                $position->updateSaving([
                    'exchange_symbol_id' => $bestToken->id,
                    'direction' => $bestToken->direction,
                ]);

                // Save the parsed trading pair for the exchange.
                $position->updateSaving([
                    'parsed_trading_pair' => $position->getParsedTradingPair(),
                ]);
            }
        }

        return $this->tokens;
    }

    protected function generateStructuredDataFromAvailableExchangeSymbols()
    {
        /*
         * Group available exchange symbols by timeframe and direction.
         * This index is used later to pick best candidates per timeframe.
         */
        $this->sortedExchangeSymbols = [];

        foreach ($this->availableExchangeSymbols as $availableSymbol) {
            $data = data_get(
                $this->sortedExchangeSymbols,
                $availableSymbol->indicators_timeframe.'.'.$availableSymbol->direction,
                []
            );

            $data[] = $availableSymbol->id;

            data_set(
                $this->sortedExchangeSymbols,
                $availableSymbol->indicators_timeframe.'.'.$availableSymbol->direction,
                $data
            );
        }

        return $this->sortedExchangeSymbols;
    }

    protected function selectBestToken(string $direction)
    {
        /*
         * Token selection strategy for a given direction.
         *
         * 1. Try reusing a fast-tracked token that was profitable.
         * 2. If none available, iterate over configured timeframes:
         *    - Randomly pick one token from that group.
         *    - Exclude it from pool to prevent reuse.
         */
        $indexes = $this->tradeConfiguration->indicator_timeframes;

        /*
         * Fast-tracked tokens are preferred if available.
         */
        $fastTrackedSymbol = $this->getFastTrackedSymbolForDirection($direction);
        if ($fastTrackedSymbol) {
            // Remove used symbol from availability pool and regenerate structure.
            $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(
                fn ($symbol) => $symbol->id !== $fastTrackedSymbol->id
            );

            $this->generateStructuredDataFromAvailableExchangeSymbols();

            return $fastTrackedSymbol;
        }

        /*
         * Iterate through indicator timeframes in priority order.
         */
        foreach ($indexes as $index) {
            $ids = data_get($this->sortedExchangeSymbols, $index.'.'.$direction, []);

            if (! empty($ids)) {
                shuffle($ids);

                $exchangeSymbolId = $ids[0];
                $exchangeSymbol = ExchangeSymbol::find($exchangeSymbolId);

                if ($exchangeSymbol) {
                    // Remove selected token from pool and regenerate structure.
                    $this->availableExchangeSymbols = $this->availableExchangeSymbols->filter(
                        fn ($symbol) => $symbol->id !== $exchangeSymbolId
                    );

                    $this->generateStructuredDataFromAvailableExchangeSymbols();

                    return $exchangeSymbol;
                }
            }
        }

        return null;
    }

    protected function canOpen(string $direction)
    {
        /*
         * Determines if we are allowed to open another position
         * in the given direction (LONG or SHORT).
         */
        $totalAllowed = $direction === 'LONG'
            ? $this->total_positions_long
            : $this->total_positions_short;

        $currentPositions = $this->positions()
            ->opened()
            ->where('positions.direction', $direction)
            ->whereNotNull('positions.exchange_symbol_id')
            ->count();

        // info("Position ID: {$this->positionReference->id}. Direction: {$direction}. Total allowed: {$totalAllowed}. Current Positions: {$currentPositions}");

        return $currentPositions < $totalAllowed;
    }

    protected function getFastTrackedSymbolForDirection(string $direction)
    {
        /*
         * Looks up previous fast-tracked positions matching the direction.
         * If one is available again in the pool, return it for reuse.
         */
        $fastTracked = $this->fastTrackedPositions()->where('direction', $direction);

        if ($fastTracked->isNotEmpty()) {
            foreach ($fastTracked as $trackedPosition) {
                $symbol = $this->availableExchangeSymbols->where('direction', $direction)->first(
                    fn ($availableSymbol) => $availableSymbol->id === $trackedPosition->exchange_symbol_id
                );

                if ($symbol) {
                    return $symbol;
                }
            }
        }

        return null;
    }
}
