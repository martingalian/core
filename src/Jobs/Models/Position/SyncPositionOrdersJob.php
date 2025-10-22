<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

/**
 * Class SyncPositionOrdersJob.
 *
 * Syncs orders for a given position using APIable job logic. This job
 * attaches a rate limiter and exception handler based on the account's
 * API system. The result of the sync is returned from the API call.
 */
final class SyncPositionOrdersJob extends BaseApiableJob
{
    public Position $position;

    public bool $syncReferenceData;

    public function __construct(int $positionId, bool $syncReferenceData = false)
    {
        $this->position = Position::findOrFail($positionId);
        $this->syncReferenceData = $syncReferenceData;
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

    public function computeApiable()
    {
        $response = $this->position->syncOrders();

        if ($this->syncReferenceData) {
            info_if('Syncing reference data...');
            foreach ($this->position->orders as $order) {
                $order->reference_status = $order->status;
                $order->reference_price = $order->price;
                $order->reference_quantity = $order->quantity;
                $order->save();
                info_if("Order id {$order->id} reference data synced!");
            }
        }

        return ['message' => 'Position orders synced'];
    }
}
