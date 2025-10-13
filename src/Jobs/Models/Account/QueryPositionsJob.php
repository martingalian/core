<?php

namespace Martingalian\Core\Jobs\Models\Account;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ApiSystem;

/*
 * QueryPositionsJob
 *
 * • Queries the exchange for currently open positions on a specific account.
 * • Stores the API result in the `api_snapshots` table for auditing purposes.
 * • Uses the API system's canonical name to assign proper limiter and handler.
 * • Logs a message when no positions are returned or lists the active pairs.
 * • Helps track real-time exposure and open trades per user account.
 */
class QueryPositionsJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        // Load account and corresponding API system.
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
    }

    public function assignExceptionHandler()
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount(Account::admin($canonical));
    }

    public function relatable()
    {
        // Associate logs and traceability with the account.
        return $this->account;
    }

    public function computeApiable()
    {
        // Call API to fetch open positions for the account.
        $apiResponse = $this->account->apiQueryPositions();

        // Persist the API result for historical tracking.
        ApiSnapshot::storeFor($this->account, 'account-positions', $apiResponse->result);

        if (empty($apiResponse->result)) {
            /*
             * Log an informative message when no positions exist.
             * Useful for validation and monitoring.
             */
            $this->account->logApplicationEvent(
                'No open positions returned from API.',
                self::class,
                __FUNCTION__
            );

            return ['response' => "No open positions returned for account ID {$this->account->id} - {$this->account->user->name}"];
        }

        /*
         * Extract and format the trading pairs from result keys.
         * Logs the symbols for transparency and audit trail.
         */
        $tradingPairs = collect(array_keys($apiResponse->result))->join(', ');

        $this->account->logApplicationEvent(
            "Exchange opened positions: {$tradingPairs}",
            self::class,
            __FUNCTION__
        );

        return $apiResponse->result;
    }
}
