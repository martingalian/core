<?php

namespace Martingalian\Core\Jobs\Models\Account;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ApiSystem;

/*
 * QueryOpenOrdersJob
 *
 * • Queries the exchange for currently open orders on a given account.
 * • Stores the result in `api_snapshots` for auditing and debugging.
 * • Applies rate limiting and exception handling via canonical API system.
 * • Logs a message if no open orders are returned.
 * • Helps track and debug trading activity and open positions per account.
 */
class QueryOpenOrdersJob extends BaseApiableJob
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
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount(Account::admin($canonical));
    }

    public function relatable()
    {
        // Link logs and tracing to the account entity.
        return $this->account;
    }

    public function computeApiable()
    {
        // Perform the actual API call to retrieve open orders.
        $apiResponse = $this->account->apiQueryOpenOrders();

        // Store the API response in the snapshot system.
        ApiSnapshot::storeFor($this->account, 'account-open-orders', $apiResponse->result);

        if (empty($apiResponse->result)) {
            /*
             * Log the absence of open orders for visibility.
             * This is useful when validating system behavior.
             */
            $this->account->logApplicationEvent(
                'No open orders returned from API.',
                self::class,
                __FUNCTION__
            );

            return ['response' => "No open orders found for account ID {$this->account->id} / {$this->account->user->name}"];
        }

        /*
         * Extract and list the unique trading symbols present in the open orders.
         * The result is not logged but may be used downstream.
         */
        $symbols = collect($apiResponse->result)
            ->pluck('symbol')
            ->unique()
            ->join(', ');

        return $apiResponse->result;
    }
}
