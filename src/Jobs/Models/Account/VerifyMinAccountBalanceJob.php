<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Account;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;

/*
 * VerifyMinAccountBalanceJob
 *
 * • Reads the account balance snapshot from api_snapshots (canonical: account-balance).
 * • Compares available-balance against the trade configuration's min_account_balance.
 * • Stops the workflow gracefully if balance is insufficient.
 */
final class VerifyMinAccountBalanceJob extends BaseQueueableJob
{
    public Account $account;

    public bool $hasMinBalance = false;

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
        // Get balance from the snapshot stored by QueryAccountBalanceJob
        $balanceData = ApiSnapshot::getFrom($this->account, 'account-balance') ?? [];

        $availableBalance = $balanceData['available-balance'] ?? '0';
        $minAccountBalance = $this->account->tradeConfiguration->min_account_balance ?? '100';

        // Compare using bccomp for precision (returns -1 if available < min)
        $this->hasMinBalance = bccomp($availableBalance, $minAccountBalance, 8) >= 0;

        return [
            'account_id' => $this->account->id,
            'available_balance' => $availableBalance,
            'min_account_balance' => $minAccountBalance,
            'has_min_balance' => $this->hasMinBalance,
        ];
    }

    /**
     * Stop the workflow gracefully if balance is below minimum.
     */
    public function complete(): void
    {
        if (! $this->hasMinBalance) {
            $this->stopJob();
        }
    }
}
