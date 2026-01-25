<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Order;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Order\PlaceStopLossOrderJob as PlaceStopLossOrderAtomic;
use Martingalian\Core\Models\Step;

/**
 * PlaceStopLossOrderJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for placing the stop-loss order.
 *
 * Flow:
 * - Step N: PlaceStopLossOrderJob (Atomic) - Places stop-loss order
 *
 * Must run AFTER PlaceLimitOrdersJob (needs limit order prices for anchor).
 */
class PlaceStopLossOrderJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(PlaceStopLossOrderAtomic::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
