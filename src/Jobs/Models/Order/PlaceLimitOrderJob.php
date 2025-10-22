<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\User;
use Throwable;

final class PlaceLimitOrderJob extends BaseApiableJob
{
    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
    }

    public function relatable()
    {
        return $this->order->position;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->order->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->order->position->account);
    }

    public function startOrFail()
    {
        $result = $this->order->position->marketOrder()->exchange_order_id !== null &&
                   $this->order->position->status === 'opening';

        if ($result === false) {
            $reason = '';

            if (is_null($this->order->position->marketOrder()->exchange_order_id)) {
                $reason = 'Market order exchange order id is null.';
            }

            if ($this->order->position->status !== 'opening') {
                $reason .= 'Position status not in opening';
            }

            $this->order->position->logApplicationEvent(
                '[StartOrFail] Start-or-fail FALSE. Reason: '.$reason,
                self::class,
                __FUNCTION__
            );

            User::notifyAdminsViaPushover(
                "{$this->order->position->parsed_trading_pair} StartOrFail() failed. Reason: {$reason}",
                '['.class_basename(self::class).'] - startOrFail() returned false',
                'nidavellir_warnings'
            );

            return $result;
        }
    }

    public function computeApiable()
    {
        $this->order->position->logApplicationEvent(
            "[Attempting] LIMIT order [{$this->order->id}] Qty: {$this->order->quantity}, Price: {$this->order->price}",
            self::class,
            __FUNCTION__
        );

        $this->order->apiPlace();

        return ['order' => format_model_attributes($this->order)];
    }

    public function doubleCheck(): bool
    {
        if (! $this->order->exchange_order_id) {
            return false;
        }

        $this->order->apiSync();

        if ($this->order->status !== 'NEW') {
            return false;
        }

        return true;
    }

    public function complete()
    {
        $this->order->updateSaving([
            'reference_price' => $this->order->price,
            'reference_quantity' => $this->order->quantity,
            'reference_status' => $this->order->status,
        ]);

        $this->order->position->logApplicationEvent(
            "[Completed] LIMIT order [{$this->order->id}] successfully placed (Price: {$this->order->price}, Qty: {$this->order->quantity})",
            self::class,
            __FUNCTION__
        );

        $this->order->logApplicationEvent(
            "Order [{$this->order->id}] successfully placed (Price: {$this->order->price}, Qty: {$this->order->quantity})",
            self::class,
            __FUNCTION__
        );
    }

    public function resolveException(Throwable $e)
    {
        User::notifyAdminsViaPushover(
            "[S:{$this->step->id} P:{$this->order->position->id} O:{$this->order->id}] Order {$this->order->type} {$this->order->side} LIMIT place error - {$e->getMessage()}",
            '['.class_basename(self::class).'] - Error',
            'nidavellir_errors'
        );
    }
}
