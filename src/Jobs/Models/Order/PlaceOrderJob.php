<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\NotificationThrottler;
use Throwable;

final class PlaceOrderJob extends BaseApiableJob
{
    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
    }

    public function startOrFail()
    {
        return $this->order->status === 'NEW' &&
               is_null($this->order->exchange_order_id);
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
        $this->order->apiPlace();
        $this->order->refresh();

        $this->order->updateSaving([
            'reference_price' => $this->order->price,
            'reference_quantity' => $this->order->quantity,
            'reference_status' => $this->order->status,
        ]);

        return ['order' => format_model_attributes($this->order)];
    }

    public function doubleCheck()
    {
        if (is_null($this->order->exchange_order_id)) {
            $this->order->apiSync();

            // Double check again.
            return false;
        }

        // Double check okay.
        return true;
    }

    public function resolveException(Throwable $e)
    {
        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('place_order')
            ->execute(function () {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$this->order->id}] Order {$this->order->type} {$this->order->side} place error - {$e->getMessage()}",
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
