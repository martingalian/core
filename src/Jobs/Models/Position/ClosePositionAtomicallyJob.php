<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;

final class ClosePositionAtomicallyJob extends BaseApiableJob
{
    /**
     * Cool-down threshold in percent on the 1D candle (current vs latest 1D close).
     * If current >= close * (1 + THRESHOLD), set tradeable_at 72h in the future.
     */
    protected const PUMP_THRESHOLD_PCT_1D = 15.0;

    /**
     * Target position to be closed.
     */
    public Position $position;

    /**
     * Whether to verify price against expected.
     */
    public bool $verifyPrice;

    public function __construct(int $positionId, bool $verifyPrice = false)
    {
        $this->position = Position::findOrFail($positionId);
        $this->verifyPrice = $verifyPrice;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make($this->position->account->apiSystem->canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        // If configured, immediately disable symbol when a position is about to close negative.
        if ($this->position->account->tradeConfiguration->disable_exchange_symbol_from_negative_pnl_position) {
            if (($this->position->direction === 'SHORT' && $this->position->opening_price < $this->position->exchangeSymbol->mark_price) ||
                ($this->position->direction === 'LONG' && $this->position->opening_price > $this->position->exchangeSymbol->mark_price)
            ) {
                Throttler::using(NotificationService::class)
                    ->withCanonical('position_closing_negative_pnl')
                    ->execute(function () {
                        NotificationService::send(
                            user: Martingalian::admin(),
                            message: "Position {$this->position->parsed_trading_pair} is possibly closing with a negative PnL. Exchange symbol disabled. Please check!",
                            title: "Position {$this->position->parsed_trading_pair} possible closed with negative PnL",
                            canonical: 'position_closing_negative_pnl',
                            deliveryGroup: 'exceptions'
                        );
                    });

                $this->position->exchangeSymbol->is_tradeable = false;
                $this->position->exchangeSymbol->save();
            }
        }

        /**
         * Big pump cool-down (1D):
         * - Read latest 1D "candle" (type "dashboard") close from IndicatorHistory.
         * - Compare current mark price vs that close.
         * - If change >= +15%, set tradeable_at to now()+72h to cool down the symbol
         *   (avoid re-opening LONGs into a likely mean-reversion).
         *
         * We do this check right before calling apiClose(); it does not block closing
         * and only schedules a cool-down window on the ExchangeSymbol.
         */
        $ex = $this->position->exchangeSymbol;
        if ($ex) {
            // Resolve indicator id once (canonical 'candle', type 'dashboard').
            $indicatorId = Indicator::query()
                ->where('canonical', 'candle')
                ->where('type', 'dashboard')
                ->value('id');

            if ($indicatorId) {
                // Latest 1D history row for this symbol.
                $indicator = IndicatorHistory::query()
                    ->where('indicator_id', $indicatorId)
                    ->where('exchange_symbol_id', $ex->id)
                    ->where('timeframe', '1d')
                    ->orderByDesc('timestamp')
                    ->first();

                // Extract latest CLOSE from the row (supports scalar or array shape).
                $latestClose = $this->readLatestClose($indicator);

                // Prefer a live price if available; fallback to mark price.
                $current = null;
                if ($this->position->current_price !== null && $this->position->current_price !== '') {
                    $current = (float) $this->position->current_price;
                } elseif ($ex->mark_price !== null && $ex->mark_price !== '') {
                    $current = (float) $ex->mark_price;
                }

                if ($latestClose !== null && $latestClose > 0.0 && $current !== null) {
                    $pct = (($current - $latestClose) / $latestClose) * 100.0;

                    if (is_finite($pct) && $pct >= $this->position->exchangeSymbol->disable_on_price_spike_percentage) {
                        $until = Carbon::now()->addHours($this->position->exchangeSymbol->price_spike_cooldown_hours);

                        // Set a cool-down window via tradeable_at (do not flip is_tradeable here).
                        // updateSaving(...) is used per your convention for audited saves.
                        $ex->updateSaving([
                            'tradeable_at' => $until, // Eloquent will cast Carbon to datetime
                        ]);

                        // Notify admins so it's visible in ops.
                        Throttler::using(NotificationService::class)
                            ->withCanonical('position_price_spike_cooldown_set')
                            ->execute(function () use ($pct, $until) {
                                NotificationService::send(
                                    user: Martingalian::admin(),
                                    message: "Cooldown set for {$this->position->parsed_trading_pair}: +".number_format($pct, 2).'% vs latest 1D close. Tradeable again at '.$until->format('Y-m-d H:i').'.',
                                    title: 'Price Spike Detected',
                                    canonical: 'position_price_spike_cooldown_set',
                                    deliveryGroup: 'nidavellir_warnings'
                                );
                            });
                    }
                }
            }
        }

        // Proceed to close on the exchange.
        $response = $this->position->apiClose();

        // Count how many limit orders were filled for this side.
        $totalFilledLimitOrders = $this->position
            ->orders()
            ->where('orders.status', 'FILLED')
            ->where('orders.position_side', $this->position->direction)
            ->where('orders.type', 'LIMIT')
            ->count();

        // Notify user in case of a high profit trade inside notification parameters.
        if ($totalFilledLimitOrders >= $this->position->account->total_limit_orders_filled_to_notify
            && $totalFilledLimitOrders >= 1
        ) {
            $this->position->account->user->notifyViaPushover(
                "Position {$this->position->parsed_trading_pair} was closed with higher profit [Limit orders: {$totalFilledLimitOrders}]",
                '['.class_basename(self::class).'] - High profit position closed',
                'nidavellir_positions'
            );
        }

        // Mark historical data to be deleted in 24h (kept disabled).
        /*
        Step::create([
            'class' => DeletePositionHistoryDataJob::class,
            'queue' => 'positions',
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'dispatch_after' => now()->addHours(24),
        ]);
        */

        return $response->result;
    }

    /**
     * Extract latest CLOSE from an IndicatorHistory row.
     * Supports shapes:
     * - data['close'] = scalar number
     * - data['close'] = [ ..., lastClose ]
     */
    public function readLatestClose(?IndicatorHistory $row): ?float
    {
        if (! $row || ! is_array($row->data) || ! array_key_exists('close', $row->data)) {
            return null;
        }

        $close = $row->data['close'];

        if (is_array($close)) {
            if (empty($close)) {
                return null;
            }
            $last = end($close);

            return is_numeric($last) ? (float) $last : null;
        }

        return is_numeric($close) ? (float) $close : null;
    }
}
