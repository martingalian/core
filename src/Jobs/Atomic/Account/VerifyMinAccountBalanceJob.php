<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Account;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\Math;

/**
 * VerifyMinAccountBalanceJob (Atomic)
 *
 * Queries the exchange for account balance and stores in api_snapshots.
 * Compares available-balance against the trade configuration's min_account_balance.
 * Stops the workflow gracefully if balance is insufficient.
 */
final class VerifyMinAccountBalanceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public bool $hasMinBalance = false;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->account);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable()
    {
        // Query balance from exchange and store in api_snapshots
        $apiResponse = $this->account->apiQueryBalance();
        $balanceData = $apiResponse->result;

        ApiSnapshot::storeFor($this->account, 'account-balance', $balanceData);

        // Verify minimum balance
        $availableBalance = $balanceData['available-balance'] ?? '0';
        $minAccountBalance = $this->account->tradeConfiguration->min_account_balance ?? '100';

        $this->hasMinBalance = Math::gte($availableBalance, $minAccountBalance, 8);

        return [
            'account_id' => $this->account->id,
            'balance' => $balanceData,
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
