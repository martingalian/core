<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Account;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ApiSystem;

/**
 * QueryAccountOpenOrdersJob (Atomic)
 *
 * Queries the exchange for open orders on the account.
 * Stores the API result in the `api_snapshots` table for subsequent jobs.
 */
class QueryAccountOpenOrdersJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

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
        $apiResponse = $this->account->apiQueryOpenOrders();

        ApiSnapshot::storeFor($this->account, 'account-open-orders', $apiResponse->result);

        return [
            'account_id' => $this->account->id,
            'open_orders_count' => count($apiResponse->result ?? []),
            'open_orders' => $apiResponse->result,
        ];
    }
}
