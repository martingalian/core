<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BasePositionLifecycle;
use Martingalian\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob as AtomicCancelPositionOpenOrdersJob;
use Martingalian\Core\Models\Step;

/**
 * CancelPositionOpenOrdersJob (Lifecycle)
 *
 * Orchestrator that creates step for cancelling all open orders for a position.
 * Delegates to the atomic job which calls the exchange API.
 */
class CancelPositionOpenOrdersJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicCancelPositionOpenOrdersJob::class),
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
