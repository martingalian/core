<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;
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

            Throttler::using(NotificationService::class)
                ->withCanonical('place_limit_order')
                ->execute(function () {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "{$this->order->position->parsed_trading_pair} StartOrFail() failed. Reason: {$reason}",
                        title: '['.class_basename(self::class).'] - startOrFail() returned false',
                        deliveryGroup: 'exceptions'
                    );
                });

            return $result;
        }
    }

    public function computeApiable()
    {
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
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('limit_order_placement_error')
            ->execute(function () use ($e) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[S:{$this->step->id} P:{$this->order->position->id} O:{$this->order->id}] Order {$this->order->type} {$this->order->side} LIMIT place error - {$e->getMessage()}",
                    title: '['.class_basename(self::class).'] - Error',
                    canonical: 'limit_order_placement_error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
