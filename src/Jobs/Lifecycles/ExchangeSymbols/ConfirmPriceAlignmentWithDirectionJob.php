<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Exception;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Models\User;
use Throwable;

final class ConfirmPriceAlignmentWithDirectionJob extends BaseQueueableJob
{
    public ?ExchangeSymbol $exchangeSymbol = null;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function compute()
    {
        // Get the candle-comparison indicator
        $indicator = Indicator::firstWhere('canonical', 'candle-comparison');

        if (! $indicator) {
            throw new Exception('Indicator "candle-comparison" not found');
        }

        // Fetch the most recent indicator history for this symbol
        $history = IndicatorHistory::query()
            ->where('exchange_symbol_id', $this->exchangeSymbol->id)
            ->where('indicator_id', $indicator->id)
            ->where('timeframe', $this->exchangeSymbol->indicators_timeframe)
            ->latest('timestamp')
            ->first();

        if (! $history) {
            throw new Exception("No indicator history found for exchange symbol {$this->exchangeSymbol->id}");
        }

        // Extract data from stored JSON
        $data = $history->data;

        if (! isset($data['close']) || count($data['close']) < 2) {
            throw new Exception("Invalid indicator data format for exchange symbol {$this->exchangeSymbol->id}");
        }

        $first = $data['close'][0];
        $last = $data['close'][1];
        $direction = $this->exchangeSymbol->direction;
        $timeframe = $this->exchangeSymbol->indicators_timeframe;

        if (($direction === 'LONG' && $last <= $first) ||
        ($direction === 'SHORT' && $last >= $first)
        ) {
            $this->exchangeSymbol->logApplicationEvent(
                "{$this->exchangeSymbol->parsed_trading_pair} indicator {$direction} data CLEANED due to price misalignment (Last: {$data['close'][1]} Previous: {$data['close'][0]}, timeframe: {$timeframe})",
                self::class,
                __FUNCTION__
            );

            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} REMOVED due to price misalignment (Last: {$data['close'][1]} Previous: {$data['close'][0]}, timeframe: {$timeframe})"];
        }

        $this->exchangeSymbol->logApplicationEvent(
            "Price alignment CONFIRMED (Last: {$data['close'][1]} Previous: {$data['close'][0]}, timeframe: {$timeframe})",
            self::class,
            __FUNCTION__
        );

        // Last step: activate exchange symbol for trading.
        $this->exchangeSymbol->updateSaving(['is_active' => true]);

        return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} CONFIRMED (Last: {$data['close'][1]} Previous: {$data['close'][0]}, timeframe: {$timeframe})"];
    }

    public function resolveException(Throwable $e)
    {
        User::notifyAdminsViaPushover(
            "[{$this->exchangeSymbol->id}] - ExchangeSymbol price alignment error - ".ExceptionParser::with($e)->friendlyMessage(),
            "[S:{$this->step->id} ES:{$this->exchangeSymbol->id}] ".class_basename(self::class).' - Error',
            'nidavellir_errors'
        );
    }
}
