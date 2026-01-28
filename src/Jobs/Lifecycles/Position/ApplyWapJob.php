<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Martingalian\Core\Jobs\Atomic\Position\UpdatePositionStatusJob as AtomicUpdatePositionStatusJob;
use Martingalian\Core\Jobs\Lifecycles\Account\QueryAccountPositionsJob as QueryAccountPositionsLifecycle;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * ApplyWapJob (Lifecycle Orchestrator)
 *
 * Dispatched when a LIMIT (DCA) order is filled.
 * Recalculates the take-profit price based on Binance's breakEvenPrice
 * and modifies the PROFIT order accordingly.
 *
 * Flow (4 steps):
 * 1. UpdatePositionStatusJob → status='waping'
 * 2. QueryAccountPositionsJob → fetches fresh data with breakEvenPrice
 * 3. CalculateWapAndModifyProfitOrderJob → the WAP calculation
 * 4. UpdatePositionStatusJob → status='active'
 * + resolve-exception: UpdatePositionStatusJob → status='active' (revert on failure)
 */
class ApplyWapJob extends BaseApiableJob
{
    public Position $position;

    public ?string $message;

    public function __construct(int $positionId, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->message = $message;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Guard: Only run if position is still in an active status.
     * Prevents WAP on positions already closing/closed.
     */
    public function startOrFail(): bool
    {
        // Position must be in an active status
        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            return false;
        }

        // Must have a profit order to modify
        if ($this->position->profitOrder() === null) {
            return false;
        }

        // Must have profit_percentage configured
        if ($this->position->profit_percentage === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $resolver = JobProxy::with($this->position->account);
        $blockUuid = $this->uuid();

        // Step 1: Update position status to 'waping'
        Step::create([
            'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'waping',
                'message' => $this->message,
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
            'workflow_id' => null,
        ]);

        // Step 2: Query account positions snapshot from exchange
        $queryPositionsLifecycleClass = $resolver->resolve(QueryAccountPositionsLifecycle::class);
        $queryPositionsLifecycle = new $queryPositionsLifecycleClass($this->position->account);
        $nextIndex = $queryPositionsLifecycle->dispatch(
            blockUuid: $blockUuid,
            startIndex: 2,
            workflowId: null
        );

        // Step 3: Calculate WAP and modify profit order
        Step::create([
            'class' => $resolver->resolve(CalculateWapAndModifyProfitOrderJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => $nextIndex,
            'workflow_id' => null,
        ]);
        $nextIndex++;

        // Step 4: Update position status back to 'active'
        Step::create([
            'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
                'message' => 'WAP applied successfully',
            ],
            'block_uuid' => $blockUuid,
            'index' => $nextIndex,
            'workflow_id' => null,
        ]);

        // Resolve-exception step: Revert status to 'active' on failure
        Step::create([
            'class' => $resolver->resolve(AtomicUpdatePositionStatusJob::class),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
                'message' => 'WAP failed, reverting to active',
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
            'type' => 'resolve-exception',
            'workflow_id' => null,
        ]);

        return [
            'position_id' => $this->position->id,
            'message' => 'WAP workflow initiated',
        ];
    }
}
