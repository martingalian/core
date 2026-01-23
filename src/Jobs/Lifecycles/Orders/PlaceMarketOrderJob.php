<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Orders;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Position\PlaceMarketOrderJob as PlaceMarketOrderAtomic;
use Martingalian\Core\Models\Step;

/**
 * PlaceMarketOrderJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for placing the entry order.
 * Default implementation creates a single atomic step for market order.
 *
 * Flow:
 * - Step N: PlaceMarketOrderJob (Atomic) - Places market entry order
 *
 * Must run AFTER PreparePositionDataJob (margin, leverage must be set).
 */
class PlaceMarketOrderJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(PlaceMarketOrderAtomic::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
