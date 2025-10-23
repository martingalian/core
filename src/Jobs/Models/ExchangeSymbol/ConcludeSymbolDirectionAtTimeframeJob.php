<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Debuggable;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\StepsDispatcher;
use Martingalian\Core\Models\TradeConfiguration;
use Str;

/**
 * ConcludeSymbolDirectionAtTimeframeJob
 *
 * Concludes trading direction for a single symbol at a single timeframe.
 * Part of atomic per-symbol workflow for progressive indicator analysis.
 *
 * If concluded: Updates symbol and enables trading.
 * If inconclusive: Spawns child workflow for next timeframe.
 * If last timeframe inconclusive: Invalidates symbol.
 */
final class ConcludeSymbolDirectionAtTimeframeJob extends BaseApiableJob
{
    public int $exchangeSymbolId;

    public string $timeframe;

    public array $previousConclusions;

    /**
     * @param  int  $exchangeSymbolId  Symbol to conclude
     * @param  string  $timeframe  Current timeframe being evaluated
     * @param  array  $previousConclusions  Map of previous timeframe conclusions (e.g., ['1h' => 'INCONCLUSIVE'])
     */
    public function __construct(int $exchangeSymbolId, string $timeframe, array $previousConclusions = [])
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
        $this->timeframe = $timeframe;
        $this->previousConclusions = $previousConclusions;
        $this->retries = 20;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function computeApiable()
    {
        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);
        $tradeConfig = TradeConfiguration::getDefault();
        $allTimeframes = $tradeConfig->indicator_timeframes;

        // Query indicator_histories for this symbol + current timeframe
        // Get the latest timestamp for each indicator at this timeframe
        $latestPerIndicator = IndicatorHistory::query()
            ->join('indicators', 'indicator_histories.indicator_id', '=', 'indicators.id')
            ->where('indicator_histories.exchange_symbol_id', $exchangeSymbol->id)
            ->where('indicator_histories.timeframe', $this->timeframe)
            ->where('indicators.type', 'refresh-data')
            ->where('indicators.is_active', 1)
            ->selectRaw('indicator_histories.indicator_id, MAX(indicator_histories.timestamp) as max_timestamp')
            ->groupBy('indicator_histories.indicator_id')
            ->get();

        if ($latestPerIndicator->isEmpty()) {
            // No indicator data found - this shouldn't happen if QuerySymbolIndicatorsJob ran properly
            return [
                'result' => 'error',
                'message' => "No indicator data found for timeframe {$this->timeframe}",
            ];
        }

        // Now get the actual records at those timestamps
        $histories = collect();
        foreach ($latestPerIndicator as $item) {
            $record = IndicatorHistory::query()
                ->join('indicators', 'indicator_histories.indicator_id', '=', 'indicators.id')
                ->where('indicator_histories.exchange_symbol_id', $exchangeSymbol->id)
                ->where('indicator_histories.timeframe', $this->timeframe)
                ->where('indicator_histories.indicator_id', $item->indicator_id)
                ->where('indicator_histories.timestamp', $item->max_timestamp)
                ->where('indicators.type', 'refresh-data')
                ->where('indicators.is_active', 1)
                ->with('indicator')
                ->select('indicator_histories.*')
                ->first();

            if ($record) {
                $histories->push($record);
            }
        }

        // Build indicatorData for later use
        $indicatorData = [];
        foreach ($histories as $history) {
            if ($history->indicator) {
                $indicatorData[$history->indicator->canonical] = [
                    'result' => $history->data,
                ];
            }
        }

        // Process indicators to determine conclusion
        $directions = [];
        $validationsPassed = true;

        foreach ($histories as $history) {
            if (! $history->indicator) {
                continue;
            }

            $conclusion = $history->conclusion;

            // Determine indicator type by checking conclusion value:
            // - "LONG" or "SHORT" = direction indicator
            // - "0" or "1" = validation indicator
            if (is_string($conclusion) && ($conclusion === 'LONG' || $conclusion === 'SHORT')) {
                // Direction indicator
                $directions[] = $conclusion;
            } elseif ($conclusion === '0' || $conclusion === 0 || $conclusion === false) {
                // Validation indicator returned 0 - immediately invalidate this timeframe
                $validationsPassed = false;
                break;
            }
            // Validation passed (1/true) - continue
        }

        // Check if we have a valid conclusion at this timeframe
        if (! $validationsPassed || count($directions) === 0 || count(array_unique($directions)) !== 1) {
            // INCONCLUSIVE at this timeframe
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
        }

        // All indicators agree on a direction
        $newDirection = $directions[0];

        // Build current conclusions including this timeframe
        $currentConclusions = array_merge($this->previousConclusions, [$this->timeframe => $newDirection]);

        // Determine if this is a direction change
        $oldDirection = $exchangeSymbol->direction;

        if (! is_null($oldDirection) && $oldDirection !== $newDirection) {
            // Direction change detected - apply path consistency rule
            return $this->handleDirectionChange(
                $exchangeSymbol,
                $oldDirection,
                $newDirection,
                $currentConclusions,
                $allTimeframes,
                $indicatorData
            );
        }

        // No direction change (first-time or same direction) - update symbol
        $this->updateSymbol($exchangeSymbol, $newDirection, $indicatorData);

        return [
            'result' => 'concluded',
            'direction' => $newDirection,
            'timeframe' => $this->timeframe,
            'is_change' => is_null($oldDirection) ? 'first_time' : 'same_direction',
        ];
    }

    /**
     * Handle inconclusive timeframe - spawn child workflow for next timeframe or invalidate if last.
     */
    private function handleInconclusiveTimeframe(ExchangeSymbol $exchangeSymbol, array $allTimeframes): array
    {
        // Add current timeframe as INCONCLUSIVE to conclusions
        $currentConclusions = array_merge($this->previousConclusions, [$this->timeframe => 'INCONCLUSIVE']);

        // Find next timeframe
        $currentIndex = array_search($this->timeframe, $allTimeframes);
        if ($currentIndex === false) {
            return ['result' => 'error', 'message' => "Invalid timeframe: {$this->timeframe}"];
        }

        $nextIndex = $currentIndex + 1;
        if ($nextIndex >= count($allTimeframes)) {
            // No more timeframes - invalidate symbol
            $exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            return [
                'result' => 'not_concluded',
                'message' => 'All timeframes exhausted without conclusion',
                'path' => $this->buildPathString($currentConclusions, $allTimeframes),
            ];
        }

        // Spawn child workflow for next timeframe
        $nextTimeframe = $allTimeframes[$nextIndex];
        $this->spawnNextTimeframeWorkflow($exchangeSymbol->id, $nextTimeframe, $currentConclusions);

        return [
            'result' => 'inconclusive',
            'next_timeframe' => $nextTimeframe,
            'path' => $this->buildPathString($currentConclusions, $allTimeframes),
        ];
    }

    /**
     * Handle direction change with path consistency validation.
     */
    private function handleDirectionChange(
        ExchangeSymbol $exchangeSymbol,
        string $oldDirection,
        string $newDirection,
        array $currentConclusions,
        array $allTimeframes,
        array $indicatorData
    ): array {
        $tradeConfig = TradeConfiguration::getDefault();
        $leastTimeframeIndex = $tradeConfig->least_timeframe_index_to_change_indicator;
        $currentIndex = array_search($this->timeframe, $allTimeframes);

        // Check if we've reached minimum timeframe index for direction changes
        if ($currentIndex < $leastTimeframeIndex) {
            // Too early to change - try next timeframe
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
        }

        // Validate path consistency: all previous timeframes must be either NEW direction or INCONCLUSIVE
        $pathValid = true;
        for ($i = 0; $i <= $currentIndex; $i++) {
            $tf = $allTimeframes[$i];
            $tfConclusion = $currentConclusions[$tf] ?? 'INCONCLUSIVE';

            if ($tfConclusion !== $newDirection && $tfConclusion !== 'INCONCLUSIVE') {
                $pathValid = false;
                break;
            }
        }

        if (! $pathValid) {
            // Path invalid - invalidate symbol
            $exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            Debuggable::debug(
                $exchangeSymbol,
                "Direction change rejected due to path inconsistency: {$oldDirection} -> {$newDirection}",
                $exchangeSymbol->symbol->token
            );

            return [
                'result' => 'rejected',
                'reason' => 'path_inconsistency',
                'old_direction' => $oldDirection,
                'new_direction' => $newDirection,
                'path' => $this->buildPathString($currentConclusions, $allTimeframes),
            ];
        }

        // Path valid - update symbol with new direction
        $this->updateSymbol($exchangeSymbol, $newDirection, $indicatorData);

        return [
            'result' => 'concluded',
            'direction' => $newDirection,
            'timeframe' => $this->timeframe,
            'is_change' => 'direction_changed',
            'old_direction' => $oldDirection,
        ];
    }

    /**
     * Update exchange symbol with concluded direction.
     */
    private function updateSymbol(ExchangeSymbol $exchangeSymbol, string $direction, array $indicatorData): void
    {
        $exchangeSymbol->updateSaving([
            'direction' => $direction,
            'indicators_timeframe' => $this->timeframe,
            'indicators_values' => $indicatorData,
            'indicators_synced_at' => now(),
            'is_active' => true,
        ]);

        $exchangeSymbol->logApplicationEvent(
            "{$exchangeSymbol->parsed_trading_pair} concluded as {$direction} on timeframe {$this->timeframe}",
            self::class,
            __FUNCTION__
        );
    }

    /**
     * Spawn child workflow for next timeframe.
     */
    private function spawnNextTimeframeWorkflow(int $symbolId, string $nextTimeframe, array $conclusions): void
    {
        $childBlockUuid = Str::uuid()->toString();
        $group = StepsDispatcher::getDispatchGroup();
        $now = now();

        // Create child workflow: Query + Conclude for next timeframe
        Step::insert([
            [
                'class' => QuerySymbolIndicatorsJob::class,
                'queue' => 'indicators',
                'block_uuid' => $childBlockUuid,
                'group' => $group,
                'state' => \Martingalian\Core\States\Pending::class,
                'index' => 1,
                'arguments' => json_encode([
                    'exchangeSymbolId' => $symbolId,
                    'timeframe' => $nextTimeframe,
                    'previousConclusions' => $conclusions,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'class' => self::class,
                'queue' => 'indicators',
                'block_uuid' => $childBlockUuid,
                'group' => $group,
                'state' => \Martingalian\Core\States\Pending::class,
                'index' => 2,
                'arguments' => json_encode([
                    'exchangeSymbolId' => $symbolId,
                    'timeframe' => $nextTimeframe,
                    'previousConclusions' => $conclusions,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Link child workflow to current step
        $this->step->update(['child_block_uuid' => $childBlockUuid]);
    }

    /**
     * Build readable path string for logging.
     */
    private function buildPathString(array $conclusions, array $allTimeframes): string
    {
        $path = [];
        foreach ($allTimeframes as $tf) {
            if (isset($conclusions[$tf])) {
                $path[] = "{$tf}={$conclusions[$tf]}";
            }
        }

        return implode(' -> ', $path);
    }
}
