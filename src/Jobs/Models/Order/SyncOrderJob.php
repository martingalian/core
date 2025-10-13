<?php

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\User;

class SyncOrderJob extends BaseApiableJob
{
    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
    }

    public function startOrFail()
    {
        return ! is_null($this->order->exchange_order_id);
    }

    public function relatable()
    {
        return $this->order;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->order->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->order->position->account);
    }

    public function computeApiable()
    {
        $this->order->apiSync();
    }

    public function resolveException(\Throwable $e)
    {
        User::notifyAdminsViaPushover(
            "[{$this->order->id}] Order {$this->order->type} {$this->order->side} synchronization error - {$e->getMessage()}",
            '['.class_basename(static::class).'] - Error',
            'nidavellir_errors'
        );
    }
}
