<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

/**
 * SyncPositionOrdersJob
 *
 * Syncs all syncable orders for a given position.
 * Syncable orders = non-MARKET orders with an exchange_order_id.
 *
 * This job calls apiSync() on each order, which updates:
 * - status
 * - quantity
 * - price
 *
 * The Order Observer detects changes and triggers appropriate workflows
 * (e.g., ClosePositionJob when profit/stop order is FILLED).
 */
class SyncPositionOrdersJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Verify the position can have orders synced.
     */
    public function startOrFail(): bool
    {
        // Position must be in an "opened" status
        if (! in_array($this->position->status, $this->position->openedStatuses(), true)) {
            return false;
        }

        // Must have at least one syncable order
        return $this->position->orders()->syncable()->exists();
    }

    public function computeApiable()
    {
        $syncedOrders = [];
        $failedOrders = [];

        // Get all syncable orders (non-MARKET with exchange_order_id)
        $orders = $this->position->orders()->syncable()->get();

        foreach ($orders as $order) {
            try {
                $order->apiSync();
                $syncedOrders[] = [
                    'id' => $order->id,
                    'type' => $order->type,
                    'status' => $order->status,
                ];
            } catch (\Throwable $e) {
                $failedOrders[] = [
                    'id' => $order->id,
                    'type' => $order->type,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'position_id' => $this->position->id,
            'synced_count' => count($syncedOrders),
            'failed_count' => count($failedOrders),
            'synced_orders' => $syncedOrders,
            'failed_orders' => $failedOrders,
            'message' => 'Position orders synced',
        ];
    }
}
