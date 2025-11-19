<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Accounts;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Account\AssignTokensToNewPositionsJob;
use Martingalian\Core\Jobs\Models\Account\QueryAccountBalanceJob;
use Martingalian\Core\Jobs\Models\Account\QueryOpenOrdersJob;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class LaunchCreatedPositionsJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function startOrSkip()
    {
        // Do we have positions with status = new?
        if (! $this->account->positions()->where('positions.status', 'new')->exists()) {
            $this->step->response = ['response' => 'No new positions found. Skipping lifecycle'];

            return false;
        }
    }

    public function compute()
    {
        $uuid = Str::uuid()->toString();

        // Get all open positions for this account.
        Step::create([
            'class' => QueryPositionsJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        // Get all open orders for this account.
        Step::create([
            'class' => QueryOpenOrdersJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        // Get wallet balance too.
        Step::create([
            'class' => QueryAccountBalanceJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        // Now assign tokens to the new positions (and close positions without tokens).
        Step::create([
            'class' => AssignTokensToNewPositionsJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 2,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        // Finally dispatch each of the new positions.
        Step::create([
            'class' => DispatchNewPositionsWithTokensAssignedJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 3,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        return ['response' => "Launching new positions for Account ID {$this->account->id} / {$this->account->user->name}"];
    }

    public function resolveException(Throwable $e)
    {
        // Removed NotificationService::send - invalid canonical: launch_created_positions
    }
}
