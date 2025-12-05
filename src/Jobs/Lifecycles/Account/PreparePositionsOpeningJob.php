<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Step;

/*
 * PreparePositionsOpeningJob
 *
 * Prepares and validates position opening for an account:
 * • Step 1: QueryAccountPositionsJob - Fetches open positions from exchange, stores in api_snapshots
 * • Step 2: MatchPositionsJob - Compares exchange positions with limits, creates empty Position records
 * • Step 3+: Future steps for actual position opening
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
        $blockUuid = (string) Str::uuid();

        // Step 1: Query exchange for actual open positions
        Step::create([
            'class' => QueryPositionsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $blockUuid,
            'index' => 1,
        ]);

        // Step 2: Match positions and create empty Position records for slots to fill
        // Step::create([
        //     'class' => MatchPositionsJob::class,
        //     'arguments' => [
        //         'accountId' => $this->account->id,
        //     ],
        //     'block_uuid' => $blockUuid,
        //     'index' => 2,
        // ]);

        return [
            'account_id' => $this->account->id,
            'message' => 'Position opening preparation initiated',
        ];
    }
}
