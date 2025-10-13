<?php

namespace Martingalian\Core\Jobs\Models\Account;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ApiSystem;

/*
 * QueryAccountBalanceJob
 *
 * • Fetches and stores the account's current balance via API call.
 * • Uses API system and canonical name to configure rate limiter and handler.
 * • Persists API result to `api_snapshots` for traceability.
 * • Logs a message if there are no significant balances (> 0.01).
 * • Designed to run under throttling and exception-handling conditions.
 */
class QueryAccountBalanceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        // Load the target account and its API system.
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical)->withAccount($this->account);
    }

    public function relatable()
    {
        // Associate logs and context with the account model.
        return $this->account;
    }

    public function computeApiable()
    {
        // Query the account's balance from the API.
        $apiResponse = $this->account->apiQueryBalance();

        // Persist the API response to the snapshot table.
        ApiSnapshot::storeFor($this->account, 'account-balance', $apiResponse->result);

        if (empty($apiResponse->result)) {
            return [];
        }

        /*
         * Extract and format all non-zero balances (>= 0.01).
         * Example: "BTC (0.12), ETH (1.04)"
         */
        $nonZeroBalances = collect($apiResponse->result)
            ->filter(fn ($balance) => (float) $balance >= 0.01)
            ->map(fn ($balance, $asset) => "{$asset} (".number_format((float) $balance, 2, '.', '').')')
            ->values()
            ->join(', ');

        /*
         * Log whether meaningful balances were found.
         * Helps with debugging empty or minimal accounts.
         */
        if ($nonZeroBalances === '') {
            $this->account->logApplicationEvent(
                'No non-zero balances found.',
                self::class,
                __FUNCTION__
            );

            return ['response' => "No non-zero balances found for account ID {$this->account->id} / {$this->account->user->name}"];
        } else {
            $this->account->logApplicationEvent(
                "Synced non-zero balances: {$nonZeroBalances}",
                self::class,
                __FUNCTION__
            );
        }

        return $apiResponse->result;
    }
}
