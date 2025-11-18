<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class ModifyOrderJob extends BaseApiableJob
{
    public Order $order;

    public ?float $price = null;

    public ?float $quantity = null;

    public function __construct(int $orderId, ?float $price = null, ?float $quantity = null)
    {
        $this->order = Order::findOrFail($orderId);
        $this->price = $price;
        $this->quantity = $quantity;
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
        $this->order->apiModify($this->quantity, $this->price);

        return ['response' => "Order modified to quantity: {$this->quantity}, price: {$this->price}"];
    }

    public function resolveException(Throwable $e)
    {
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'modify_order',
            referenceData: [
                'order_id' => $this->order->id,
                'order_type' => $this->order->type,
                'order_side' => $this->order->side,
                'job_class' => class_basename(self::class),
                'error_message' => $e->getMessage(),
            ],
            cacheKey: "modify_order:{$this->order->id}"
        );
    }
}
