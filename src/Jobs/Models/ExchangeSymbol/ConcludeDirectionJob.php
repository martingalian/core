<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Debuggable;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Models\TradeConfiguration;

final class ConcludeDirectionJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public TradeConfiguration $tradeConfiguration;

    public bool $shouldCleanIndicatorData;

    public function __construct(int $exchangeSymbolId, bool $shouldCleanIndicatorData = true)
    {
        info_if('[ConcludeDirectionJob] __construct() starting...');
        info_if('[ConcludeDirectionJob] Exchange Symbol ID: '.$exchangeSymbolId);
        info_if('[ConcludeDirectionJob] Should Clean Indicator Data: '.($shouldCleanIndicatorData ? 'true' : 'false'));

        $this->shouldCleanIndicatorData = $shouldCleanIndicatorData;
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->tradeConfiguration = TradeConfiguration::default()->first();
        $this->retries = 20;

        info_if('[ConcludeDirectionJob] Loaded exchange symbol: '.$this->exchangeSymbol->parsed_trading_pair);
        info_if('[ConcludeDirectionJob] __construct() completed');
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable()
    {
        info_if('[ConcludeDirectionJob] computeApiable() starting...');
        info_if('[ConcludeDirectionJob] Exchange Symbol: '.$this->exchangeSymbol->parsed_trading_pair.' (ID: '.$this->exchangeSymbol->id.')');

        // Set is_active = 0 immediately to prevent this symbol from being picked for trading while being evaluated
        $this->exchangeSymbol->update(['is_active' => false]);
        info_if('[ConcludeDirectionJob] Set is_active=0 to prevent symbol from being used during evaluation');

        // Get all timeframes from trade configuration
        $timeframes = $this->tradeConfiguration->indicator_timeframes;
        info_if('[ConcludeDirectionJob] Will check timeframes in order: '.json_encode($timeframes));

        // Track all conclusions for debugging when no direction is concluded
        $allConclusionsData = [];

        // Track each timeframe's conclusion for path consistency validation
        $timeframeConclusions = [];

        // Iterate through timeframes until we find a conclusion
        foreach ($timeframes as $timeframeIndex => $timeframe) {
            info_if('[ConcludeDirectionJob] Checking timeframe: '.$timeframe.' (index: '.$timeframeIndex.')');

            // Query indicator_histories for this symbol + timeframe
            // Get the NEWEST entry for EACH indicator separately
            // Only include indicators that are type='refresh-data' and is_active=1
            info_if('[ConcludeDirectionJob] Querying indicator_histories for timeframe '.$timeframe.'...');

            // Get the latest timestamp for each indicator at this timeframe
            // Filter to only refresh-data indicators that are active
            $latestPerIndicator = IndicatorHistory::query()
                ->join('indicators', 'indicator_histories.indicator_id', '=', 'indicators.id')
                ->where('indicator_histories.exchange_symbol_id', $this->exchangeSymbol->id)
                ->where('indicator_histories.timeframe', $timeframe)
                ->where('indicators.type', 'refresh-data')
                ->where('indicators.is_active', 1)
                ->selectRaw('indicator_histories.indicator_id, MAX(indicator_histories.timestamp) as max_timestamp')
                ->groupBy('indicator_histories.indicator_id')
                ->get();

            if ($latestPerIndicator->isEmpty()) {
                info_if('[ConcludeDirectionJob] No indicator data for timeframe '.$timeframe.', trying next timeframe');
                continue;
            }

            info_if('[ConcludeDirectionJob] Found latest timestamps for '.count($latestPerIndicator).' refresh-data indicators');

            // Now get the actual records at those timestamps
            $histories = collect();
            foreach ($latestPerIndicator as $item) {
                $record = IndicatorHistory::query()
                    ->join('indicators', 'indicator_histories.indicator_id', '=', 'indicators.id')
                    ->where('indicator_histories.exchange_symbol_id', $this->exchangeSymbol->id)
                    ->where('indicator_histories.timeframe', $timeframe)
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

            info_if('[ConcludeDirectionJob] Found '.$histories->count().' indicator history records (newest for each indicator) for timeframe '.$timeframe);

            // Build indicatorData for later use in updateIndicatorDataAndConclude
            $indicatorData = [];
            foreach ($histories as $history) {
                if ($history->indicator) {
                    $indicatorData[$history->indicator->canonical] = [
                        'result' => $history->data,
                    ];
                }
            }

            $directions = [];
            $validationsPassed = true;
            $allIndicatorsProcessed = true;

            info_if('[ConcludeDirectionJob] Processing indicators from indicator_histories...');
            foreach ($histories as $history) {
                if (! $history->indicator) {
                    continue;
                }

                $indicatorCanonical = $history->indicator->canonical;
                $conclusion = $history->conclusion;

                info_if('[ConcludeDirectionJob] Indicator '.$indicatorCanonical.' conclusion from DB: '.json_encode($conclusion));

                // Store conclusion data for debugging
                if (! isset($allConclusionsData[$timeframe])) {
                    $allConclusionsData[$timeframe] = [];
                }
                $allConclusionsData[$timeframe][$history->indicator_id] = [
                    'canonical' => $indicatorCanonical,
                    'conclusion' => $conclusion,
                    'data' => is_string($history->data) ? json_decode($history->data, true) : $history->data,
                    'taapi_construct_id' => $history->taapi_construct_id,
                ];

                // Determine indicator type by checking conclusion value:
                // - "LONG" or "SHORT" = direction indicator
                // - "0" or "1" (or 0/1 as int/bool) = validation indicator

                if (is_string($conclusion) && ($conclusion === 'LONG' || $conclusion === 'SHORT')) {
                    // Direction indicator
                    $directions[] = $conclusion;
                    info_if('[ConcludeDirectionJob] Direction indicator '.$indicatorCanonical.' returned: '.$conclusion);
                } elseif ($conclusion === '0' || $conclusion === 0 || $conclusion === false) {
                    // Validation indicator returned 0 - immediately invalidate
                    info_if('[ConcludeDirectionJob] Validation indicator '.$indicatorCanonical.' returned 0 - INVALIDATED');
                    $validationsPassed = false;
                    $allIndicatorsProcessed = false;
                    break;
                } elseif ($conclusion === '1' || $conclusion === 1 || $conclusion === true) {
                    // Validation indicator returned 1 - passed
                    info_if('[ConcludeDirectionJob] Validation indicator '.$indicatorCanonical.' returned 1 - VALIDATED');
                    // Continue processing other indicators
                } else {
                    // Unexpected conclusion value
                    info_if('[ConcludeDirectionJob] Indicator '.$indicatorCanonical.' returned unexpected conclusion: '.json_encode($conclusion));
                    $allIndicatorsProcessed = false;
                    break;
                }
            }

        info_if('[ConcludeDirectionJob] Finished processing indicators for timeframe '.$timeframe);

        // If not all indicators processed successfully, mark as INCONCLUSIVE
        if (! $allIndicatorsProcessed) {
            info_if('[ConcludeDirectionJob] Not all indicators processed for timeframe '.$timeframe.' - marked as INCONCLUSIVE');
            $timeframeConclusions[$timeframe] = 'INCONCLUSIVE';
            continue;  // Try next timeframe
        }

            info_if('[ConcludeDirectionJob] All indicators processed for timeframe '.$timeframe);
            info_if('[ConcludeDirectionJob] Validations passed: '.($validationsPassed ? 'YES' : 'NO'));
            info_if('[ConcludeDirectionJob] Directions collected: '.json_encode($directions));
            info_if('[ConcludeDirectionJob] Unique directions: '.count(array_unique($directions)));

            Debuggable::debug(
                $this->exchangeSymbol,
                'Conclusion directions for timeframe '.$timeframe.': '.json_encode($directions),
                $this->exchangeSymbol->symbol->token
            );

            // Check if we have a valid conclusion:
            // 1. At least one direction indicator must exist
            // 2. All direction indicators must agree (only 1 unique direction)
            // 3. All validation indicators must have passed (already checked above)
            if (count($directions) > 0 && count(array_unique($directions)) === 1) {
                $newSide = $directions[0];
                info_if('[ConcludeDirectionJob] All indicators AGREE on direction: '.$newSide.' for timeframe '.$timeframe);

                // Store this timeframe's conclusion
                $timeframeConclusions[$timeframe] = $newSide;

                $this->exchangeSymbol->logApplicationEvent(
                    "Indicators concluded {$newSide} on timeframe {$timeframe}. Current: {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe}",
                    self::class,
                    __FUNCTION__
                );

                if (! is_null($this->exchangeSymbol->direction) && $this->exchangeSymbol->direction !== $newSide) {
                    // Direction change detected
                    $leastTimeFrameIndex = $this->tradeConfiguration->least_timeframe_index_to_change_indicator;
                    $currentTimeFrameIndex = $timeframeIndex;
                    $oldDirection = $this->exchangeSymbol->direction;

                    $this->exchangeSymbol->logApplicationEvent(
                        "{$this->exchangeSymbol->parsed_trading_pair} current direction is {$oldDirection} on timeframe {$this->exchangeSymbol->indicators_timeframe} and wants to change to {$newSide} at timeframe {$timeframe} (index {$currentTimeFrameIndex})",
                        self::class,
                        __FUNCTION__
                    );

                    info_if('[ConcludeDirectionJob] Direction change: '.$oldDirection.' -> '.$newSide.' at timeframe '.$timeframe.' (index '.$currentTimeFrameIndex.')');

                    // Check if we've reached the minimum timeframe index
                    if ($currentTimeFrameIndex < $leastTimeFrameIndex) {
                        $this->shouldCleanIndicatorData = false;

                        $this->exchangeSymbol->logApplicationEvent(
                            "{$this->exchangeSymbol->parsed_trading_pair} wants to change direction, but current index {$currentTimeFrameIndex} < minimum index {$leastTimeFrameIndex} ({$timeframes[$leastTimeFrameIndex]})",
                            self::class,
                            __FUNCTION__
                        );

                        info_if('[ConcludeDirectionJob] Current index '.$currentTimeFrameIndex.' < minimum index '.$leastTimeFrameIndex.', trying next timeframe');
                        continue;  // Try next timeframe
                    }

                    // Validate path consistency: all previous timeframes must be either NEW direction or INCONCLUSIVE
                    info_if('[ConcludeDirectionJob] Checking path consistency from index 0 to '.$currentTimeFrameIndex.'...');
                    $pathValid = true;
                    $pathDetails = [];

                    for ($i = 0; $i <= $currentTimeFrameIndex; $i++) {
                        $tfName = $timeframes[$i];
                        $tfConclusion = $timeframeConclusions[$tfName] ?? 'INCONCLUSIVE';
                        $pathDetails[] = "{$tfName}={$tfConclusion}";

                        // Path is valid if each timeframe is either:
                        // 1. The NEW direction
                        // 2. INCONCLUSIVE
                        if ($tfConclusion !== $newSide && $tfConclusion !== 'INCONCLUSIVE') {
                            info_if('[ConcludeDirectionJob] Path INVALID at '.$tfName.': expected '.$newSide.' or INCONCLUSIVE, got '.$tfConclusion);
                            $pathValid = false;
                            break;
                        }
                    }

                    info_if('[ConcludeDirectionJob] Path: '.implode(' -> ', $pathDetails).' | Valid: '.($pathValid ? 'YES' : 'NO'));

                    if (! $pathValid) {
                        // Path has contradictions - INVALIDATE exchange symbol
                        $this->exchangeSymbol->logApplicationEvent(
                            "{$this->exchangeSymbol->parsed_trading_pair} direction change REJECTED due to path inconsistency. Path: ".implode(' -> ', $pathDetails),
                            self::class,
                            __FUNCTION__
                        );

                        Debuggable::debug(
                            $this->exchangeSymbol,
                            "Symbol {$this->exchangeSymbol->symbol->token} direction change rejected due to path inconsistency",
                            $this->exchangeSymbol->symbol->token
                        );

                        $this->step->update(['response' => 'Direction change REJECTED due to path inconsistency. Path: '.implode(' -> ', $pathDetails)]);

                        // Clean indicator data
                        $this->updateIndicatorDataAndConclude([
                            'direction' => null,
                            'indicators_values' => null,
                            'indicators_timeframe' => null,
                            'indicators_synced_at' => null,
                            'is_active' => false,
                        ]);

                        info_if('[ConcludeDirectionJob] Exchange symbol INVALIDATED due to path inconsistency');
                        return;
                    }

                    // Path is valid - proceed with direction change

                $previousDirection = $this->exchangeSymbol->direction ?? 'N/A';
                $previousTimeframe = $this->exchangeSymbol->indicators_timeframe ?? 'N/A';

                    $message = "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED as {$newSide} on timeframe {$timeframe} (previously was {$previousDirection} on timeframe {$previousTimeframe})";

                    $this->exchangeSymbol->logApplicationEvent(
                        $message,
                        self::class,
                        __FUNCTION__
                    );

                    $this->step->update(['response' => $message]);

                    Debuggable::debug(
                        $this->exchangeSymbol,
                        $message,
                        $this->exchangeSymbol->symbol->token
                    );

                    $this->updateIndicatorDataAndConclude([
                        'direction' => $newSide,
                        'indicators_timeframe' => $timeframe,
                        'indicators_values' => $indicatorData,
                        'indicators_synced_at' => now(),
                        'is_active' => true,
                    ]);

                    info_if('[ConcludeDirectionJob] Direction changed successfully, is_active set to 1');
                    return;
                }

                if (is_null($this->exchangeSymbol->direction)) {
                    $this->exchangeSymbol->logApplicationEvent(
                        "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED as {$newSide} on timeframe {$timeframe} (previously was {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe}",
                        self::class,
                        __FUNCTION__
                    );

                    $this->step->update(['response' => "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED as {$newSide} on timeframe {$timeframe} (previously was {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe})"]);

                    $this->updateIndicatorDataAndConclude([
                        'direction' => $newSide,
                        'indicators_timeframe' => $timeframe,
                        'indicators_values' => $indicatorData,
                        'indicators_synced_at' => now(),
                        'is_active' => true,
                    ]);

                    info_if('[ConcludeDirectionJob] Direction set for first time, is_active set to 1');
                    return;
                }

                // Direction same as before - update indicators data and keep is_active = true
                $this->updateIndicatorDataAndConclude([
                    'direction' => $newSide,
                    'indicators_timeframe' => $timeframe,
                    'indicators_values' => $indicatorData,
                    'indicators_synced_at' => now(),
                    'is_active' => true,
                ]);

                info_if('[ConcludeDirectionJob] Direction same as before, updated indicators data, is_active remains 1');
                return;
            } else {
                // Indicators did not agree - mark as INCONCLUSIVE
                info_if('[ConcludeDirectionJob] Indicators did NOT agree for timeframe '.$timeframe.'. Directions: '.json_encode($directions).'. Marked as INCONCLUSIVE.');
                $timeframeConclusions[$timeframe] = 'INCONCLUSIVE';
                // Continue to next timeframe
            }
        }  // End of timeframe foreach loop

        // If we reach here, no conclusion was reached on any timeframe
        info_if('[ConcludeDirectionJob] No conclusion reached on any timeframe.');

        // Build path summary
        $pathSummary = [];
        foreach ($timeframes as $tf) {
            $pathSummary[] = $tf.'='.($timeframeConclusions[$tf] ?? 'INCONCLUSIVE');
        }
        info_if('[ConcludeDirectionJob] Timeframe path: '.implode(' -> ', $pathSummary));

        if ($this->shouldCleanIndicatorData) {
            info_if('[ConcludeDirectionJob] Cleaning indicator data (no conclusion reached)');
            $this->exchangeSymbol->logApplicationEvent(
                "{$this->exchangeSymbol->parsed_trading_pair} indicator completed all timeframes without conclusion. Path: ".implode(' -> ', $pathSummary),
                self::class,
                __FUNCTION__
            );

            // Prepare detailed response with all conclusions data
            $responseData = [
                'result' => 'not concluded',
                'symbol' => $this->exchangeSymbol->parsed_trading_pair,
                'path' => implode(' -> ', $pathSummary),
                'conclusions' => $allConclusionsData,
            ];

            $this->step->update(['response' => json_encode($responseData)]);

            Debuggable::debug(
                $this->exchangeSymbol,
                "Symbol {$this->exchangeSymbol->symbol->token}/{$this->exchangeSymbol->quote->canonical} didnt get any direction conclusion on any timeframe, so we are cleaning its indicator data",
                $this->exchangeSymbol->symbol->token
            );

            $this->updateIndicatorDataAndConclude([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            info_if('[ConcludeDirectionJob] Indicator data cleaned');
        } else {
            info_if('[ConcludeDirectionJob] NOT cleaning indicator data (shouldCleanIndicatorData=false)');
        }

        info_if('[ConcludeDirectionJob] computeApiable() completed');
    }

    protected function updateIndicatorDataAndConclude($parameters)
    {
        $this->exchangeSymbol->updateSaving($parameters);

        /**
         * In case the direction is filled, trigger the last part of the
         * lifecycle to confirm that the price follows the conclusion
         * direction. If not, the indicator data will be deleted.
         */
        $this->exchangeSymbol->refresh();
    }
}
