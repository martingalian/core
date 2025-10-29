<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Exception;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\Martingalian;
use Throwable;

final class ConfirmPriceAlignmentWithDirectionJob extends BaseQueueableJob
{
    public ?ExchangeSymbol $exchangeSymbol = null;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::with(['symbol', 'quote', 'apiSystem'])->findOrFail($exchangeSymbolId);
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
            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair} REMOVED due to missing indicator history"];
        }

        // Extract data from stored JSON
        $data = $history->data;

        if (! isset($data['close']) || count($data['close']) < 2) {
            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair} REMOVED due to invalid indicator data format"];
        }

        $first = $data['close'][0];
        $last = $data['close'][1];
        $direction = $this->exchangeSymbol->direction;
        $timeframe = $this->exchangeSymbol->indicators_timeframe;

        if (($direction === 'LONG' && $last <= $first) ||
        ($direction === 'SHORT' && $last >= $first)
        ) {
            $this->exchangeSymbol->updateSaving([
                'direction' => null,
                'indicators_values' => null,
                'indicators_timeframe' => null,
                'indicators_synced_at' => null,
                'is_active' => false,
            ]);

            return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} REMOVED due to price misalignment (Last: {$data['close'][1]} Previous: {$data['close'][0]}, timeframe: {$timeframe})"];
        }

        // Last step: activate exchange symbol for trading.
        $this->exchangeSymbol->updateSaving(['is_active' => true]);

        // Send notification based on direction change status from previous step
        $this->sendDirectionNotification($direction, $timeframe);

        return ['response' => "Price alignment for {$this->exchangeSymbol->parsed_trading_pair}-{$direction} CONFIRMED (Last: {$data['close'][1]} Previous: {$data['close'][0]}, timeframe: {$timeframe})"];
    }

    public function resolveException(Throwable $e)
    {
        // Martingalian::notifyAdmins(
        //     message: "[{$this->exchangeSymbol->id}] - ExchangeSymbol price alignment error - ".ExceptionParser::with($e)->friendlyMessage(),
        //     title: "[S:{$this->step->id} ES:{$this->exchangeSymbol->id}] ".class_basename(self::class).' - Error',
        //     deliveryGroup: 'exceptions'
        // );
    }

    /**
     * Send appropriate notification based on direction change status from previous step.
     */
    private function sendDirectionNotification(string $direction, string $timeframe): void
    {
        // Guard clause: step might not be initialized in tests
        if (! isset($this->step)) {
            return;
        }

        // Find the previous step (ConcludeSymbolDirectionAtTimeframeJob) in the same block
        $previousStep = Step::query()
            ->where('block_uuid', $this->step->block_uuid)
            ->where('class', \Martingalian\Core\Jobs\Models\ExchangeSymbol\ConcludeSymbolDirectionAtTimeframeJob::class)
            ->first();

        if (! $previousStep || ! $previousStep->response) {
            return;
        }

        $response = $previousStep->response;
        $isChange = $response['is_change'] ?? 'unknown';
        $exchangeName = ucfirst($this->exchangeSymbol->apiSystem->canonical);

        // Send notification based on change status
        if ($isChange === 'first_time') {
            $message = "[ES:{$this->exchangeSymbol->id}] Symbol {$this->exchangeSymbol->parsed_trading_pair} now has direction: {$direction} (timeframe: {$timeframe})";
            $title = "Direction Set ({$exchangeName})";

            // Martingalian::notifyAdmins(
            //     message: $message,
            //     title: $title,
            //     deliveryGroup: 'indicators'
            // );
        } elseif ($isChange === 'direction_changed') {
            $oldDirection = $response['old_direction'] ?? 'unknown';
            $message = "[ES:{$this->exchangeSymbol->id}] Symbol {$this->exchangeSymbol->parsed_trading_pair} direction changed: {$oldDirection} → {$direction} (timeframe: {$timeframe})";
            $title = "Direction Changed ({$exchangeName})";

            // Martingalian::notifyAdmins(
            //     message: $message,
            //     title: $title,
            //     deliveryGroup: 'indicators'
            // );
        }
        // No notification for 'same_direction' case
    }
}
