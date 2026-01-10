<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * DispatchPositionSlotsJob
 *
 * Dispatches all new positions for an account that have tokens assigned.
 * This is an orchestrator step (NOT proxied - same logic for all exchanges).
 * Uses JobProxy to resolve exchange-specific DispatchPositionJob lifecycle.
 *
 * Flow:
 * • Step 1: DispatchPositionJob (parallel) - Exchange-specific lifecycle for each position
 */
final class DispatchPositionSlotsJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function compute()
    {
        // Get all new positions for this account that have a token assigned
        $positions = $this->account->positions()
            ->where('status', 'new')
            ->whereNotNull('exchange_symbol_id')
            ->get();

        if ($positions->isEmpty()) {
            return [
                'account_id' => $this->account->id,
                'positions_dispatched' => 0,
                'message' => 'No new positions to dispatch',
            ];
        }

        $workflowId = (string) Str::uuid();
        $resolver = JobProxy::with($this->account);
        $dispatchJobClass = $resolver->resolve(DispatchPositionJob::class);

        // Step 1: Dispatch each position (all with same index = parallel execution)
        // Uses exchange-specific DispatchPositionJob lifecycle
        foreach ($positions as $position) {
            \Martingalian\Core\Models\Step::create([
                'class' => $dispatchJobClass,
                'arguments' => ['positionId' => $position->id],
                'block_uuid' => $this->uuid(),
                'child_block_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'index' => 1,
            ]);
        }

        return [
            'account_id' => $this->account->id,
            'positions_dispatched' => $positions->count(),
            'message' => 'Position dispatching initiated',
        ];
    }
}
