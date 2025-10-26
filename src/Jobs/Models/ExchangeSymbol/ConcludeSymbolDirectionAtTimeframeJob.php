<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols\ConfirmPriceAlignmentWithDirectionJob;
use Martingalian\Core\Jobs\Models\Indicator\QuerySymbolIndicatorsJob;
use Martingalian\Core\Models\Debuggable;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\TradeConfiguration;
use Martingalian\Core\Support\Martingalian;
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
final class ConcludeSymbolDirectionAtTimeframeJob extends BaseQueueableJob
{
    public int $exchangeSymbolId;

    public string $timeframe;

    public array $previousConclusions;

    public bool $shouldCleanup;

    /**
     * @param  int  $exchangeSymbolId  Symbol to conclude
     * @param  string  $timeframe  Current timeframe being evaluated
     * @param  array  $previousConclusions  Map of previous timeframe conclusions (e.g., ['1h' => 'INCONCLUSIVE'])
     * @param  bool  $shouldCleanup  Whether to clean up indicator histories after completion
     */
    public function __construct(int $exchangeSymbolId, string $timeframe, array $previousConclusions = [], bool $shouldCleanup = true)
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
        $this->timeframe = $timeframe;
        $this->previousConclusions = $previousConclusions;
        $this->shouldCleanup = $shouldCleanup;
        $this->retries = 20;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function compute()
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
            $response = [
                'result' => 'error',
                'message' => "No indicator data found for timeframe {$this->timeframe}",
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Check if we have data for all expected indicators
        $expectedIndicatorCount = \Martingalian\Core\Models\Indicator::query()
            ->where('is_active', true)
            ->where('type', 'refresh-data')
            ->count();

        if ($latestPerIndicator->count() < $expectedIndicatorCount) {
            // Missing some indicator data - treat as inconclusive
            return $this->handleInconclusiveTimeframe($exchangeSymbol, $allTimeframes);
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

        // Check if we're concluding on the same data we already have
        if ($this->isSameIndicatorData($exchangeSymbol, $indicatorData, $this->timeframe)) {
            $response = [
                'result' => 'skipped',
                'reason' => 'same_indicator_data',
                'message' => "Indicator data unchanged for timeframe {$this->timeframe}",
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Process indicators to determine conclusion
        $directions = [];
        $validationsPassed = true;

        foreach ($histories as $history) {
            if (! $history->indicator) {
                continue;
            }

            $indicatorClass = $history->indicator->class;
            $conclusion = $history->conclusion;

            // Determine indicator type by checking if the class implements the appropriate interface
            if (is_subclass_of($indicatorClass, \Martingalian\Core\Contracts\Indicators\DirectionIndicator::class)) {
                // Direction indicator
                if ($conclusion === 'LONG' || $conclusion === 'SHORT') {
                    $directions[] = $conclusion;
                }
            } elseif (is_subclass_of($indicatorClass, \Martingalian\Core\Contracts\Indicators\ValidationIndicator::class)) {
                // Validation indicator
                if ($conclusion === '0' || $conclusion === 0 || $conclusion === false) {
                    // Validation failed - immediately invalidate this timeframe
                    $validationsPassed = false;
                    break;
                }
                // Validation passed (1/true) - continue
            }
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

        // Create confirmation and cleanup steps now that we've concluded
        $this->createConfirmationAndCleanupSteps($exchangeSymbol->id, $this->shouldCleanup);

        $response = [
            'result' => 'concluded',
            'direction' => $newDirection,
            'timeframe' => $this->timeframe,
            'is_change' => is_null($oldDirection) ? 'first_time' : 'same_direction',
        ];
        $this->step->update(['response' => $response]);

        return $response;
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
            $response = ['result' => 'error', 'message' => "Invalid timeframe: {$this->timeframe}"];
            $this->step->update(['response' => $response]);

            return $response;
        }

        $nextIndex = $currentIndex + 1;
        if ($nextIndex >= count($allTimeframes)) {
            // No more timeframes - invalidate symbol
            $hadDirection = ! is_null($exchangeSymbol->direction);
            $previousDirection = $exchangeSymbol->direction;

            $exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            // Notify admin when direction is invalidated after exhausting all timeframes
            if ($hadDirection) {
                $message = "[ES:{$exchangeSymbol->id}] Symbol {$exchangeSymbol->parsed_trading_pair} direction invalidated (was {$previousDirection}, all timeframes exhausted)";
                $title = 'Direction Invalidated ('.ucfirst($exchangeSymbol->apiSystem->canonical).')';

                Martingalian::notifyAdmins(
                    message: $message,
                    title: $title,
                    deliveryGroup: 'indicators'
                );
            }

            $response = [
                'result' => 'not_concluded',
                'message' => 'All timeframes exhausted without conclusion',
                'path' => $this->buildPathString($currentConclusions, $allTimeframes),
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Spawn child workflow for next timeframe
        $nextTimeframe = $allTimeframes[$nextIndex];
        $this->spawnNextTimeframeWorkflow($exchangeSymbol->id, $nextTimeframe, $currentConclusions, $this->shouldCleanup);

        $response = [
            'result' => 'inconclusive',
            'next_timeframe' => $nextTimeframe,
            'path' => $this->buildPathString($currentConclusions, $allTimeframes),
        ];
        $this->step->update(['response' => $response]);

        return $response;
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

            // Notify admin when direction is invalidated due to path inconsistency
            $message = "[ES:{$exchangeSymbol->id}] Symbol {$exchangeSymbol->parsed_trading_pair} direction invalidated (was {$oldDirection}, path inconsistency detected)";
            $title = 'Direction Invalidated ('.ucfirst($exchangeSymbol->apiSystem->canonical).')';

            Martingalian::notifyAdmins(
                message: $message,
                title: $title,
                deliveryGroup: 'indicators'
            );

            $response = [
                'result' => 'rejected',
                'reason' => 'path_inconsistency',
                'old_direction' => $oldDirection,
                'new_direction' => $newDirection,
                'path' => $this->buildPathString($currentConclusions, $allTimeframes),
            ];
            $this->step->update(['response' => $response]);

            return $response;
        }

        // Path valid - update symbol with new direction
        $this->updateSymbol($exchangeSymbol, $newDirection, $indicatorData);

        // Create confirmation and cleanup steps now that we've concluded
        $this->createConfirmationAndCleanupSteps($exchangeSymbol->id, $this->shouldCleanup);

        $response = [
            'result' => 'concluded',
            'direction' => $newDirection,
            'timeframe' => $this->timeframe,
            'is_change' => 'direction_changed',
            'old_direction' => $oldDirection,
        ];
        $this->step->update(['response' => $response]);

        return $response;
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
            'indicators_synced_at' => Carbon::now(),
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
     * Only creates Query and Conclude steps upfront.
     * ConfirmPriceAlignment and Cleanup steps will be created if child workflow concludes.
     */
    private function spawnNextTimeframeWorkflow(int $symbolId, string $nextTimeframe, array $conclusions, bool $shouldCleanup): void
    {
        $childBlockUuid = Str::uuid()->toString();
        $group = $this->step->group;
        $now = Carbon::now();

        // Only create Query and Conclude steps for child workflow
        Step::create([
            'class' => QuerySymbolIndicatorsJob::class,
            'queue' => 'indicators',
            'block_uuid' => $childBlockUuid,
            'group' => $group,
            'index' => 1,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $nextTimeframe,
                'previousConclusions' => $conclusions,
            ],
        ]);

        Step::create([
            'class' => self::class,
            'queue' => 'indicators',
            'block_uuid' => $childBlockUuid,
            'group' => $group,
            'index' => 2,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $nextTimeframe,
                'previousConclusions' => $conclusions,
                'shouldCleanup' => $shouldCleanup,
            ],
        ]);

        // Steps 3 and 4 (ConfirmPriceAlignment and Cleanup) will be created dynamically
        // by the child workflow's ConcludeSymbolDirectionAtTimeframeJob if it concludes

        // Link child workflow to current step
        $this->step->update(['child_block_uuid' => $childBlockUuid]);
    }

    /**
     * Create confirmation and cleanup steps after successful conclusion.
     * These should only be created in the workflow that actually concludes.
     */
    private function createConfirmationAndCleanupSteps(int $symbolId, bool $shouldCleanup): void
    {
        $blockUuid = $this->step->block_uuid;
        $group = $this->step->group;

        // Find the highest index in this block to append after
        $maxIndex = Step::where('block_uuid', $blockUuid)->max('index');

        Step::create([
            'class' => ConfirmPriceAlignmentWithDirectionJob::class,
            'queue' => 'indicators',
            'block_uuid' => $blockUuid,
            'group' => $group,
            'index' => $maxIndex + 1,
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
            ],
        ]);

        if ($shouldCleanup) {
            Step::create([
                'class' => CleanupIndicatorHistoriesJob::class,
                'queue' => 'indicators',
                'block_uuid' => $blockUuid,
                'group' => $group,
                'index' => $maxIndex + 2,
                'arguments' => [
                    'exchangeSymbolId' => $symbolId,
                ],
            ]);
        }
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

    /**
     * Check if the new indicator data is the same as what's already stored on the exchange symbol.
     * Compares candle timestamps from candle-comparison indicator to determine if data has changed.
     */
    private function isSameIndicatorData(ExchangeSymbol $exchangeSymbol, array $newIndicatorData, string $timeframe): bool
    {
        // If symbol has no existing indicators_values, data is new
        if (! $exchangeSymbol->indicators_values || ! $exchangeSymbol->indicators_timeframe) {
            return false;
        }

        // If the stored timeframe is different, data is new
        if ($exchangeSymbol->indicators_timeframe !== $timeframe) {
            return false;
        }

        // Extract candle timestamps from stored data
        $storedData = $exchangeSymbol->indicators_values;
        $storedCandleData = $storedData['candle-comparison']['result'] ?? null;
        $storedTimestamps = $storedCandleData['timestamp'] ?? null;

        // Extract candle timestamps from new data
        $newCandleData = $newIndicatorData['candle-comparison']['result'] ?? null;
        $newTimestamps = $newCandleData['timestamp'] ?? null;

        // If we can't find timestamps in either dataset, consider them different (to be safe)
        if (! $storedTimestamps || ! $newTimestamps) {
            return false;
        }

        // Compare the timestamp arrays
        // We compare the last timestamp as it represents the most recent candle
        $storedLastTimestamp = is_array($storedTimestamps) ? end($storedTimestamps) : $storedTimestamps;
        $newLastTimestamp = is_array($newTimestamps) ? end($newTimestamps) : $newTimestamps;

        return $storedLastTimestamp === $newLastTimestamp;
    }
}
