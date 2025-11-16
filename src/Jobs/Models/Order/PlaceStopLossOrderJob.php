<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\NotificationThrottler;
use RuntimeException;
use Throwable;

final class PlaceStopLossOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $stopLossOrder = null;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->position->account);
    }

    /**
     * Preconditions:
     *  - Market order must exist on the exchange.
     *  - Position must be 'opening'.
     *
     * On failure: inactivate the symbol if present, log, and notify.
     */
    public function startOrFail(): bool
    {
        $ok = $this->position->marketOrder()->exchange_order_id !== null
           && $this->position->status === 'opening';

        if ($ok) {
            return true;
        }

        // Null-guard before touching exchangeSymbol (is_tradeable controlled manually via backoffice)

        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('stop_loss_precondition_failed')
            ->execute(function () {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "{$this->position->parsed_trading_pair} trading deactivated due to an issue on a StartOrFail()",
                    title: '['.class_basename(self::class).'] - startOrFail() returned false',
                    canonical: 'stop_loss_precondition_failed',
                    deliveryGroup: 'exceptions'
                );
            });

        return false;
    }

    public function computeApiable()
    {
        $profitOrder = $this->position->profitOrder();
        $lastLimit = $this->position->lastLimitOrder();

        // Guards: we need both profit order (for qty/side) and an anchor price
        if (! $profitOrder || $profitOrder->quantity === null) {
            throw new RuntimeException('Missing profit order context for stop loss.');
        }

        $stopPct = $this->position->account->stop_market_initial_percentage;
        $exchangeSymbol = $this->position->exchangeSymbol;
        $markPrice = $exchangeSymbol->mark_price;
        $stopSide = $profitOrder->side;

        /**
         * Price is calculated from the current mark price, since it's
         * triggered X minutes after the last limit order was filled.
         */
        $calc = Martingalian::calculateStopLossOrder(
            $this->position->direction,
            $markPrice,
            $stopPct,
            $profitOrder->quantity,
            $exchangeSymbol
        );

        $this->stopLossOrder = $this->position->orders()->create([
            'type' => 'STOP-MARKET',
            'status' => 'NEW',
            'side' => $stopSide,
            'client_order_id' => (string) Str::uuid(),
            'position_side' => $this->position->direction,
            'quantity' => (string) $calc['quantity'],
            'price' => (string) $calc['price'],
        ]);

        $this->stopLossOrder->apiPlace();

        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('stop_loss_placed_successfully')
            ->execute(function () use ($calc) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "{$this->position->parsed_trading_pair} Stop Loss Order successfully placed. ID [{$this->stopLossOrder->id}] Qty: {$calc['quantity']}, Price: {$calc['price']}",
                    title: '['.class_basename(self::class).'] - Stop Loss placed',
                    canonical: 'stop_loss_placed_successfully',
                    deliveryGroup: 'exceptions'
                );
            });

        return ['order' => format_model_attributes($this->stopLossOrder)];
    }

    public function doubleCheck(): bool
    {
        if (! $this->stopLossOrder) {
            $this->stopLossOrder = $this->position->stopMarketOrder();
        }

        $this->stopLossOrder->apiSync();

        return (bool) $this->stopLossOrder->exchange_order_id;
    }

    public function complete()
    {
        if (! $this->stopLossOrder) {
            $this->stopLossOrder = $this->position->stopMarketOrder();
        }

        $this->stopLossOrder->updateSaving([
            'reference_price' => $this->stopLossOrder->price,
            'reference_quantity' => $this->stopLossOrder->quantity,
            'reference_status' => $this->stopLossOrder->status,
        ]);
    }

    public function resolveException(Throwable $e)
    {
        $id = $this->stopLossOrder?->id ?? 'unknown';

        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('stop_loss_placement_error')
            ->execute(function () use ($e, $id) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$id}] STOP-MARKET order place error - {$e->getMessage()}",
                    title: '['.class_basename(self::class).'] - Error',
                    canonical: 'stop_loss_placement_error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
