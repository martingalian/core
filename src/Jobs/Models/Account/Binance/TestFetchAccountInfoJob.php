<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Account\Binance;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;

/**
 * TestFetchAccountInfoJob - Binance
 *
 * Stress test job that fetches account information from Binance.
 * Weight: 5
 * Used to test parallel execution and rate limiting.
 */
final class TestFetchAccountInfoJob extends BaseApiableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::with('apiSystem')->findOrFail($accountId);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('binance')
            ->withAccount($this->account);
    }

    public function startOrFail()
    {
        return $this->account->apiSystem->canonical === 'binance';
    }

    public function computeApiable()
    {
        // Call Binance Account Information V3 endpoint (weight: 5)
        $response = $this->account->apiQuery();

        return [
            'total_wallet_balance' => $response->result->totalWalletBalance ?? null,
            'total_unrealized_profit' => $response->result->totalUnrealizedProfit ?? null,
            'available_balance' => $response->result->availableBalance ?? null,
            'positions_count' => isset($response->result->positions) ? count($response->result->positions) : 0,
            'assets_count' => isset($response->result->assets) ? count($response->result->assets) : 0,
        ];
    }
}
