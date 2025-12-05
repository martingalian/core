<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Account;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Account;

/*
 * AssignBestTokensToPositionSlotsJob
 *
 * • Assigns the optimal ExchangeSymbol to each "new" position slot for an account.
 * • Uses the HasTokenDiscovery trait's algorithm:
 *   - Priority 1: Fast-tracked tokens (recently profitable quick trades)
 *   - Priority 2: Elasticity-based scoring (correlation × elasticity metrics)
 * • Runs as a single job per account to prevent race conditions.
 * • Force deletes any position slots that couldn't be assigned a token.
 */
final class AssignBestTokensToPositionSlotsJob extends BaseQueueableJob
{
    public Account $account;

    public int $assignedCount = 0;

    public int $deletedCount = 0;

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
        // Assign best tokens to all new position slots for this account
        // Returns a string of assigned tokens (e.g., "SQD/USDT-SHORT IOTA/USDT-LONG")
        $assignedTokens = $this->account->assignBestTokenToNewPositions();

        // Count how many positions were successfully assigned
        $this->assignedCount = $this->account->positions()
            ->where('status', 'new')
            ->whereNotNull('exchange_symbol_id')
            ->count();

        // Force delete any position slots that couldn't be assigned a token
        // These are positions with status='new' but no exchange_symbol_id
        $unassignedPositions = $this->account->positions()
            ->where('status', 'new')
            ->whereNull('exchange_symbol_id')
            ->get();

        $this->deletedCount = $unassignedPositions->count();

        foreach ($unassignedPositions as $position) {
            $position->forceDelete();
        }

        return [
            'account_id' => $this->account->id,
            'assigned_tokens' => mb_trim($assignedTokens),
            'assigned_count' => $this->assignedCount,
            'deleted_count' => $this->deletedCount,
        ];
    }

    /**
     * Stop the workflow gracefully if no tokens were assigned.
     */
    public function complete(): void
    {
        if ($this->assignedCount === 0) {
            $this->stopJob();
        }
    }
}
