<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Position\PreparePositionDataJob as AtomicPreparePositionDataJob;
use Martingalian\Core\Models\Step;

/**
 * PreparePositionDataJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for preparing position data.
 * Default implementation creates a single atomic step.
 *
 * Flow:
 * - Step N: PreparePositionDataJob (Atomic) - Populates margin, leverage, indicators, etc.
 *
 * Must run AFTER token assignment (exchange_symbol_id must be set).
 * Must run BEFORE PlaceEntryOrderJob.
 */
class PreparePositionDataJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicPreparePositionDataJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
