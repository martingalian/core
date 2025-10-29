<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\Positions\ApplyWAPJob;
use Martingalian\Core\Jobs\Lifecycles\Positions\ClosePositionJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Step;

final class ProcessOrderChangesJob extends BaseQueueableJob
{
    private const OPEN_REFERENCE_STATUSES = ['NEW', 'PARTIALLY_FILLED'];

    public Order $order;

    public function __construct(int $orderId)
    {
        $this->order = Order::findOrFail($orderId);
    }

    public function relatable(): Order
    {
        return $this->order;
    }

    public function compute(): void
    {
        info("[ProcessOrderChangesJob] Started for order ID {$this->order->id}");

        if (! $this->isMainPositionOrder()) {
            info("[ProcessOrderChangesJob] Order {$this->order->id} is not the main position order. Skipping.");

            return;
        }

        if ($this->stopMarketFilled()) {
            info("[ProcessOrderChangesJob] STOP-MARKET order {$this->order->id} is filled. Handling...");
            $this->handleStopMarketFilled();

            return;
        }

        if ($this->profitLimitFilled()) {
            info("[ProcessOrderChangesJob] PROFIT-LIMIT order {$this->order->id} is filled. Handling...");
            $this->handleProfitLimitFilled();

            return;
        }

        if ($this->limitFilled()) {
            info("[ProcessOrderChangesJob] LIMIT order {$this->order->id} is filled. Handling...");
            $this->handleLimitFilled();

            return;
        }

        info("[ProcessOrderChangesJob] No relevant order condition matched for order ID {$this->order->id}.");
    }

    private function isMainPositionOrder(): bool
    {
        return $this->order->position_side === $this->order->position->direction;
    }

    private function stopMarketFilled(): bool
    {
        return $this->order->type === 'STOP-MARKET'
            && in_array($this->order->reference_status, self::OPEN_REFERENCE_STATUSES, true)
            && $this->order->status === 'FILLED';
    }

    private function profitLimitFilled(): bool
    {
        return $this->order->type === 'PROFIT-LIMIT'
            && in_array($this->order->reference_status, self::OPEN_REFERENCE_STATUSES, true)
            && $this->order->status === 'FILLED';
    }

    private function limitFilled(): bool
    {
        return $this->order->type === 'LIMIT'
            && in_array($this->order->reference_status, self::OPEN_REFERENCE_STATUSES, true)
            && $this->order->status === 'FILLED';
    }

    private function handleStopMarketFilled(): void
    {
        info('[ProcessOrderChangesJob] Logging and dispatching ClosePositionJob due to STOP-MARKET filled.');

        NotificationThrottler::sendToAdmin(
            messageCanonical: 'process_order_changes',
            message: "Position {$this->order->position->parsed_trading_pair} STOP-MARKET filled. Please monitor!",
            title: "Position {$this->order->position->parsed_trading_pair} STOP-MARKET filled, closing position",
            deliveryGroup: 'exceptions'
        );

        Step::create([
            'class' => ClosePositionJob::class,
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->order->position->id],
        ]);

        info('[ProcessOrderChangesJob] ClosePositionJob step created.');
    }

    private function handleProfitLimitFilled(): void
    {
        info('[ProcessOrderChangesJob] Logging and dispatching ClosePositionJob due to PROFIT-LIMIT filled.');

        $this->position->updateSaving(['closed_by' => 'watcher']);

        Step::create([
            'class' => ClosePositionJob::class,
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->order->position->id],
        ]);

        info('[ProcessOrderChangesJob] ClosePositionJob step created.');
    }

    private function handleLimitFilled(): void
    {
        info('[ProcessOrderChangesJob] Logging and dispatching ApplyWAPJob due to LIMIT filled.');

        $uuid = Str::uuid()->toString();
        $childBlockId = Str::uuid()->toString();

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'positionId' => $this->order->position->id,
                'status' => 'watching',
            ],
        ]);

        info('[ProcessOrderChangesJob] Step 1 (watching) created.');

        Step::create([
            'class' => ApplyWAPJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'child_block_uuid' => $childBlockId,
            'index' => 2,
            'arguments' => [
                'positionId' => $this->order->position->id,
            ],
        ]);

        info('[ProcessOrderChangesJob] Step 2 (ApplyWAPJob with child block) created.');

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => 3,
            'arguments' => [
                'positionId' => $this->order->position->id,
                'status' => 'active',
            ],
        ]);

        info('[ProcessOrderChangesJob] Step 3 (active) created.');
    }
}
