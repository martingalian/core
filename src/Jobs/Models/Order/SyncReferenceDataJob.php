<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Order;

/**
 * Syncs reference data, meaning it will COPY the data from the non reference
 * to the reference.
 *
 * As example: $attributesToSync['quantity'] then it will copy the quantity
 * to the reference_quantity.
 */
final class SyncReferenceDataJob extends BaseQueueableJob
{
    public Order $order;

    public array $attributesToSync;

    public function __construct(int $orderId, array $attributesToSync)
    {
        $this->order = Order::findOrFail($orderId);
        $this->attributesToSync = $attributesToSync;
    }

    public function relatable()
    {
        return $this->order;
    }

    public function compute()
    {
        foreach ($this->attributesToSync as $attribute) {
            $ref = "reference_{$attribute}";
            info_if("Copying Order.{$this->order->id}.{$attribute} to {$ref}");
            $this->order->$ref = $this->order->$attribute;
        }

        $this->order->save();

        return ['order' => format_model_attributes($this->order)];
    }
}
