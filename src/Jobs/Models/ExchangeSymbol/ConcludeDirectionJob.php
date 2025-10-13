<?php

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Debuggable;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\TradeConfiguration;

class ConcludeDirectionJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public TradeConfiguration $tradeConfiguration;

    public string $timeframe;

    public bool $shouldCleanIndicatorData;

    public function __construct(int $exchangeSymbolId, bool $shouldCleanIndicatorData = true)
    {
        $this->shouldCleanIndicatorData = $shouldCleanIndicatorData;
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->tradeConfiguration = TradeConfiguration::default()->first();
        $this->retries = 20;
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
        $previousJobQueue = $this->step->getPrevious()->first();
        $indicatorData = collect($previousJobQueue->response['data'])->keyBy('id')->toArray();
        $this->timeframe = $previousJobQueue->arguments['timeframe'];

        $directions = [];
        $result = '';

        foreach (Indicator::active()->apiable()->where('type', 'refresh-data')->get() as $indicatorModel) {
            $indicatorClass = $indicatorModel->class;
            $indicator = new $indicatorClass($this->exchangeSymbol, ['interval' => $this->timeframe]);
            $indicator->symbol = $this->exchangeSymbol->symbol->token;
            $continue = true;

            if (! array_key_exists($indicatorModel->canonical, $indicatorData)) {
                $indicator->load($indicatorData);
            } else {
                $indicator->load($indicatorData[$indicatorModel->canonical]['result']);
            }

            $conclusion = $indicator->conclusion();

            Debuggable::debug(
                $this->exchangeSymbol,
                "Indicator {$indicatorModel->canonical} on timeframe {$this->timeframe} for symbol {$indicator->symbol} conclusion was ".bool_str($conclusion),
                $this->exchangeSymbol->symbol->token
            );

            if (is_bool($conclusion) && $conclusion === false) {
                $result = 'Indicator conclusion returned false';
                $continue = false;
            }

            if (is_string($conclusion)) {
                if ($conclusion === 'LONG' || $conclusion === 'SHORT') {
                    $directions[] = $conclusion;
                } else {
                    $continue = false;
                    $result = 'Indicator conclusion not LONG neither SHORT';
                }
            }

            if (is_null($conclusion)) {
                $result = 'Indicator conclusion is NULL';
                $continue = false;
            }

            if (! $continue) {
                $this->processNextTimeFrameOrConclude();

                $this->step->update(['response' => "Timeframe for {$this->exchangeSymbol->parsed_trading_pair} ({$this->exchangeSymbol->apiSystem->name}) not concluded because: ".$result]);

                return;
            }
        }

        Debuggable::debug(
            $this->exchangeSymbol,
            'Conclusion directions:'.json_encode($directions),
            $this->exchangeSymbol->symbol->token
        );

        if (count($directions) > 0 && count(array_unique($directions)) === 1) {
            $newSide = $directions[0];

            $this->exchangeSymbol->logApplicationEvent(
                "Indicators concluded {$newSide} on timeframe {$this->timeframe}. Current: {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe}",
                self::class,
                __FUNCTION__
            );

            if (! is_null($this->exchangeSymbol->direction) && $this->exchangeSymbol->direction !== $newSide) {
                $timeframes = $this->tradeConfiguration->indicator_timeframes;
                $leastTimeFrameIndex = $this->tradeConfiguration->least_timeframe_index_to_change_indicator;
                $currentTimeFrameIndex = array_search($this->timeframe, $timeframes, true);

                $this->exchangeSymbol->logApplicationEvent(
                    "{$this->exchangeSymbol->parsed_trading_pair} current direction is {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe} and should change to {$newSide} at least on timeframe {$timeframes[$leastTimeFrameIndex]}",
                    self::class,
                    __FUNCTION__
                );

                Debuggable::debug(
                    $this->exchangeSymbol,
                    "{$this->exchangeSymbol->symbol->token} current direction is {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe} and should change to {$newSide} at least on timeframe {$timeframes[$leastTimeFrameIndex]}",
                    $this->exchangeSymbol->symbol->token
                );

                if ($leastTimeFrameIndex > $currentTimeFrameIndex) {
                    $this->shouldCleanIndicatorData = false;

                    $this->exchangeSymbol->logApplicationEvent(
                        "{$this->exchangeSymbol->parsed_trading_pair} indicator concluded, but not on the minimum timeframe that is {$timeframes[$leastTimeFrameIndex]}",
                        self::class,
                        __FUNCTION__
                    );

                    $this->step->update(['response' => "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED but not on the minimum timeframe {$timeframes[$leastTimeFrameIndex]}. Proceeding."]);

                    Debuggable::debug(
                        $this->exchangeSymbol,
                        "Symbol {$this->exchangeSymbol->symbol->token} didnt change direction because needed a timeframe of {$timeframes[$leastTimeFrameIndex]} and it got a timeframe of {$this->timeframe}",
                        $this->exchangeSymbol->symbol->token
                    );

                    $this->processNextTimeFrameOrConclude();

                    return;
                }

                $previousDirection = $this->exchangeSymbol->direction ?? 'N/A';
                $previousTimeframe = $this->exchangeSymbol->indicators_timeframe ?? 'N/A';

                $message = "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED as {$newSide} on timeframe {$this->timeframe} (previously was {$previousDirection} on timeframe {$previousTimeframe})";

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
                    'indicators_timeframe' => $this->timeframe,
                    'indicators_values' => $indicatorData,
                    'indicators_synced_at' => now(),
                    'is_active' => false,
                ]);

                return;
            }

            if (is_null($this->exchangeSymbol->direction)) {
                $this->exchangeSymbol->logApplicationEvent(
                    "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED as {$newSide} on timeframe {$this->timeframe} (previously was {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe}",
                    self::class,
                    __FUNCTION__
                );

                $this->step->update(['response' => "{$this->exchangeSymbol->parsed_trading_pair} indicator CONCLUDED as {$newSide} on timeframe {$this->timeframe} (previously was {$this->exchangeSymbol->direction} on timeframe {$this->exchangeSymbol->indicators_timeframe}"]);

                $this->updateIndicatorDataAndConclude([
                    'direction' => $newSide,
                    'indicators_timeframe' => $this->timeframe,
                    'indicators_values' => $indicatorData,
                    'indicators_synced_at' => now(),
                    'is_active' => false,
                ]);

                return;
            }
        } else {
            $this->processNextTimeFrameOrConclude();
        }
    }

    protected function processNextTimeFrameOrConclude(): void
    {
        $timeframes = $this->tradeConfiguration->indicator_timeframes;
        $currentTimeFrameIndex = array_search($this->timeframe, $timeframes, true);

        if (isset($timeframes[$currentTimeFrameIndex + 1])) {
            $nextTimeFrame = $timeframes[$currentTimeFrameIndex + 1];
            $blockUuid = $this->uuid();

            Step::create([
                'class' => QueryIndicatorJob::class,
                'queue' => 'indicators',
                'arguments' => [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                    'timeframe' => $nextTimeFrame,
                ],
                'index' => 1,
                'block_uuid' => $blockUuid,
            ]);

            Step::create([
                'class' => ConcludeDirectionJob::class,
                'queue' => 'indicators',
                'arguments' => [
                    'exchangeSymbolId' => $this->exchangeSymbol->id,
                    'shouldCleanIndicatorData' => $this->shouldCleanIndicatorData,
                ],
                'index' => 2,
                'block_uuid' => $blockUuid,
            ]);
        } else {
            if ($this->shouldCleanIndicatorData) {
                $this->exchangeSymbol->logApplicationEvent(
                    "{$this->exchangeSymbol->parsed_trading_pair} indicator completed timesframes without conclusion. Removing direction (if applicable)",
                    self::class,
                    __FUNCTION__
                );

                $this->step->update(['response' => 'Exchange Symbol WITHOUT CONCLUSION. Removing indicators data']);

                Debuggable::debug(
                    $this->exchangeSymbol,
                    "Symbol {$this->exchangeSymbol->symbol->token}/{$this->exchangeSymbol->quote->canonical} didnt get any direction conclusion, so we are cleaning its indicator data",
                    $this->exchangeSymbol->symbol->token
                );

                $this->updateIndicatorDataAndConclude([
                    'direction' => null,
                    'indicators_values' => null,
                    'indicators_timeframe' => null,
                    'indicators_synced_at' => null,
                    'is_active' => false,
                ]);
            }
        }
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

    protected function checkAndNotifyTimeFrameChange(): void
    {
        $currentTimeFrame = $this->exchangeSymbol->indicators_timeframe;
        $newTimeFrame = $this->timeframe;

        if ($currentTimeFrame && $currentTimeFrame !== $newTimeFrame) {
            $this->exchangeSymbol->updateSaving(['indicators_timeframe' => $newTimeFrame]);
        }
    }
}
