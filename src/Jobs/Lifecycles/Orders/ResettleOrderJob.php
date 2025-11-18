<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Orders;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Order\PlaceOrderJob;
use Martingalian\Core\Jobs\Models\Order\SyncReferenceDataJob;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class ResettleOrderJob extends BaseQueueableJob
{
    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
    }

    public function compute()
    {
        $uuid = $this->uuid();

        $newOrder = Order::create([
            'position_id' => $this->order->position_id,
            'position_side' => $this->order->position->direction,
            'type' => $this->order->type,
            'side' => $this->order->side,
            'quantity' => $this->order->reference_quantity,
            'price' => $this->order->reference_price,
        ]);

        Step::create([
            'class' => PlaceOrderJob::class,
            'block_uuid' => $this->uuid(),
            'index' => 1,
            'queue' => 'default',
            'arguments' => [
                'orderId' => $newOrder->id,
            ],
        ]);

        // New order gets all the new attributes as reference.
        Step::create([
            'class' => SyncReferenceDataJob::class,
            'block_uuid' => $this->uuid(),
            'index' => 2,
            'queue' => 'default',
            'arguments' => [
                'orderId' => $newOrder->id,
                'attributesToSync' => ['status', 'quantity', 'price'],
            ],
        ]);

        // Previous order syncs the status (to be marked as cancelled).
        Step::create([
            'class' => SyncReferenceDataJob::class,
            'block_uuid' => $this->uuid(),
            'index' => 3,
            'queue' => 'default',
            'arguments' => [
                'orderId' => $this->order->id,
                'attributesToSync' => ['status'],
            ],
        ]);
    }

    public function resolveException(Throwable $e)
    {
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'resettle_order',
            referenceData: [
                'order_id' => $this->order->id,
                'step_id' => $this->step->id,
                'position_id' => $this->order->position->id,
                'job_class' => class_basename(self::class),
                'error_message' => ExceptionParser::with($e)->friendlyMessage(),
            ],
            cacheKey: "resettle_order:{$this->order->id}"
        );
    }
}
