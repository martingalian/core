<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Order;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\Order\SyncPositionOrdersJob as SyncPositionOrdersLifecycle;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * PrepareSyncOrdersJob (Orchestrator)
 *
 * Top-level lifecycle orchestrator dispatched by cronjobs:sync-orders.
 * Creates the atomic SyncPositionOrdersJob as a child step.
 *
 * The atomic sync job updates order statuses from the exchange.
 * The Order Observer detects changes and dispatches independent
 * lifecycle steps (CancelPositionJob / ClosePositionJob) as needed.
 */
final class PrepareSyncOrdersJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        $resolver = JobProxy::with($this->position->account);

        // Step 1: Sync all position orders from exchange
        $lifecycleClass = $resolver->resolve(SyncPositionOrdersLifecycle::class);
        $lifecycle = new $lifecycleClass($this->position);
        $lifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: null
        );

        return [
            'position_id' => $this->position->id,
            'message' => 'Sync orders workflow initiated',
        ];
    }
}
