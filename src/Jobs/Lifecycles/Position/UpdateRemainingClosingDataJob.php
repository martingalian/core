<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Position\UpdateRemainingClosingDataJob as AtomicUpdateRemainingClosingDataJob;
use Martingalian\Core\Models\Step;

/**
 * UpdateRemainingClosingDataJob (Lifecycle)
 *
 * Orchestrator that creates step for updating closing data.
 * Sets closing_price, was_fast_traded, and sends high-profit notifications.
 */
class UpdateRemainingClosingDataJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicUpdateRemainingClosingDataJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
