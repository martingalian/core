<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Step;

/*
 * DispatchPositionSlotsJob
 *
 * Dispatches all new positions for an account that have tokens assigned.
 * • Step 1: DispatchPositionJob (parallel) - Dispatches each position for trading
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

        // Step 1: Dispatch each position (all with same index = parallel execution)
        foreach ($positions as $position) {
            Step::create([
                'class' => DispatchPositionJob::class,
                'arguments' => [
                    'positionId' => $position->id,
                ],
                'block_uuid' => $this->uuid(),
                'child_block_uuid' => (string) Str::uuid(),
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
