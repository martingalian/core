<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Position\PlaceMarketOrderJob;
use Martingalian\Core\Models\Step;

/**
 * PlaceEntryOrderJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for placing the entry order.
 * Default implementation creates a single atomic step for market order.
 *
 * Flow:
 * - Step N: PlaceMarketOrderJob (Atomic) - Places market entry order
 *
 * Must run AFTER PreparePositionDataJob (margin, leverage must be set).
 */
class PlaceEntryOrderJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(PlaceMarketOrderJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
