<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Account;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Martingalian\Core\Jobs\Models\Account\CreatePositionSlotsJob;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Step;

/*
 * PreparePositionsOpeningJob
 *
 * Prepares and validates position opening for an account:
 * • Step 1: QueryPositionsJob - Fetches open positions from exchange, stores in api_snapshots
 * • Step 2: CreatePositionSlotsJob - Compares exchange positions with limits, creates empty Position records
 * • Step 3: AssignBestTokensToPositionSlotsJob - Assigns optimal tokens to slots, deletes unassigned
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
        // Step 1: Query exchange for actual open positions
        Step::create([
            'class' => QueryPositionsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 1,
        ]);

        // Step 2: Create empty Position records for available slots
        Step::create([
            'class' => CreatePositionSlotsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 2,
        ]);

        // Step 3: Assign best tokens to position slots (deletes unassigned slots)
        Step::create([
            'class' => AssignBestTokensToPositionSlotsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 3,
        ]);

        return [
            'account_id' => $this->account->id,
            'message' => 'Position opening preparation initiated',
        ];
    }
}
