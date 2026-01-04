<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Martingalian\Core\_Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Martingalian\Core\_Jobs\Models\Account\QueryAccountOpenOrdersJob;
use Martingalian\Core\_Jobs\Models\Account\QueryAccountPositionsJob;
use Martingalian\Core\Abstracts\BaseQueueableJob;
// TODO: Replace these with Lifecycle classes when created
use Martingalian\Core\Jobs\Lifecycles\Account\VerifyMinAccountBalanceJob as VerifyMinAccountBalanceLifecycle;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * PreparePositionsOpeningJob
 *
 * Prepares and validates position opening for an account.
 * This is an orchestrator step (NOT proxied - same logic for all exchanges).
 * Uses Lifecycle classes internally to dispatch exchange-specific atomic jobs.
 *
 * Flow:
 * • Step 1: VerifyMinAccountBalanceJob - Queries balance + verifies minimum (showstopper)
 * • Step 2: QueryAccountPositionsJob - Fetches open positions from exchange (parallel)
 * • Step 2: QueryAccountOpenOrdersJob - Fetches open orders from exchange (parallel)
 * • Step 3: AssignBestTokensToPositionSlotsJob - Creates slots + assigns optimal tokens
 * • Step 4: DispatchPositionSlotsJob - Dispatches positions for trading
 */
final class PreparePositionsOpeningJob extends BaseQueueableJob
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
        $resolver = JobProxy::with($this->account);
        $workflowId = (string) Str::uuid();

        // Step 1: Query balance + verify minimum (showstopper)
        // Uses Lifecycle pattern for exchange-specific behavior
        $lifecycleClass = $resolver->resolve(VerifyMinAccountBalanceLifecycle::class);
        $lifecycle = new $lifecycleClass($this->account);
        $nextIndex = $lifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: $workflowId
        );

        // TODO: Convert to Lifecycle pattern when created
        // Step 2: Query exchange for open positions (parallel)
        \Martingalian\Core\Models\Step::create([
            'class' => QueryAccountPositionsJob::class,
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        // Step 2: Query exchange for open orders (parallel - same index)
        \Martingalian\Core\Models\Step::create([
            'class' => QueryAccountOpenOrdersJob::class,
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        $nextIndex++;

        // Step 3: Create slots + assign best tokens
        \Martingalian\Core\Models\Step::create([
            'class' => AssignBestTokensToPositionSlotsJob::class,
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        $nextIndex++;

        // Step 4: Dispatch positions for trading
        \Martingalian\Core\Models\Step::create([
            'class' => DispatchPositionSlotsJob::class,
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'child_block_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        return [
            'account_id' => $this->account->id,
            'message' => 'Position opening preparation initiated',
        ];
    }
}
