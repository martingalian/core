<?php

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\User;
use Martingalian\Core\Support\Martingalian;
use Illuminate\Support\Str;

class PlaceStopLossOrderJob extends BaseApiableJob
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

        // Null-guard before touching exchangeSymbol
        if ($this->position->exchange_symbol_id && $this->position->exchangeSymbol) {
            $this->position->exchangeSymbol->updateSaving(['is_tradeable' => false]);
        }

        $this->position->logApplicationEvent(
            '[StartOrFail] Start-or-fail FALSE. Exchange Symbol set to is_tradeable=false (if present).',
            self::class,
            __FUNCTION__
        );

        User::notifyAdminsViaPushover(
            "{$this->position->parsed_trading_pair} trading deactivated due to an issue on a StartOrFail()",
            '['.class_basename(static::class).'] - startOrFail() returned false',
            'nidavellir_warnings'
        );

        return false;
    }

    public function computeApiable()
    {
        $profitOrder = $this->position->profitOrder();
        $lastLimit = $this->position->lastLimitOrder();

        // Guards: we need both profit order (for qty/side) and an anchor price
        if (! $profitOrder || $profitOrder->quantity === null) {
            $this->position->logApplicationEvent(
                '[Attempting] Aborting STOP-MARKET: missing profit order or quantity.',
                self::class,
                __FUNCTION__
            );
            throw new \RuntimeException('Missing profit order context for stop loss.');
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

        $this->position->logApplicationEvent(
            "[Attempting] STOP-MARKET order [{$this->stopLossOrder->id}] Qty: {$calc['quantity']}, Price: {$calc['price']}",
            self::class,
            __FUNCTION__
        );

        $this->stopLossOrder->apiPlace();

        $this->position->logApplicationEvent(
            "[Placed] STOP-MARKET order [{$this->stopLossOrder->id}] Qty: {$calc['quantity']}, Price: {$calc['price']}",
            self::class,
            __FUNCTION__
        );

        User::notifyAdminsViaPushover(
            "{$this->position->parsed_trading_pair} Stop Loss Order successfully placed. ID [{$this->stopLossOrder->id}] Qty: {$calc['quantity']}, Price: {$calc['price']}",
            '['.class_basename(static::class).'] - Stop Loss placed',
            'nidavellir_positions'
        );

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

        $this->position->logApplicationEvent(
            "[Completed] STOP-MARKET order [{$this->stopLossOrder->id}] successfully placed (Price: {$this->stopLossOrder->price}, Qty: {$this->stopLossOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        $this->stopLossOrder->logApplicationEvent(
            "Order [{$this->stopLossOrder->id}] successfully placed (Price: {$this->stopLossOrder->price}, Qty: {$this->stopLossOrder->quantity}).",
            self::class,
            __FUNCTION__
        );
    }

    public function resolveException(\Throwable $e)
    {
        $id = $this->stopLossOrder?->id ?? 'unknown';

        User::notifyAdminsViaPushover(
            "[{$id}] STOP-MARKET order place error - {$e->getMessage()}",
            '['.class_basename(static::class).'] - Error',
            'nidavellir_errors'
        );
    }
}
