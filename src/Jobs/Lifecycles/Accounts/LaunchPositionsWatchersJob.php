<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Accounts;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Lifecycles\Positions\CheckPositionOrderChangesJob;
use Martingalian\Core\Jobs\Models\Account\QueryOpenOrdersJob;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Jobs\Models\Position\SyncPositionOrdersJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationThrottler;
use Throwable;

final class LaunchPositionsWatchersJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function compute()
    {
        $uuid = Str::uuid()->toString();

        // Get all open positions for this account.
        Step::create([
            'class' => QueryPositionsJob::class,
            'queue' => 'watchers',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        // Get all open orders for this account.
        Step::create([
            'class' => QueryOpenOrdersJob::class,
            'queue' => 'watchers',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
        ]);

        // Active positions because "opening" positions will give a false positive.
        $this->account->positions()->where('positions.status', 'active')
            ->each(function (Position $position) {

                $uuid = Str::uuid()->toString();
                $childUuid = Str::uuid()->toString();

                Step::create([
                    'class' => UpdatePositionStatusJob::class,
                    'queue' => 'watchers',
                    'block_uuid' => $uuid,
                    'index' => 1,
                    'arguments' => [
                        'positionId' => $position->id,
                        'status' => 'watching',
                    ],
                ]);

                Step::create([
                    'class' => SyncPositionOrdersJob::class,
                    'queue' => 'watchers',
                    'block_uuid' => $uuid,
                    'index' => 2,
                    'arguments' => [
                        'positionId' => $position->id,
                    ],
                ]);

                Step::create([
                    'class' => CheckPositionOrderChangesJob::class,
                    'queue' => 'watchers',
                    'block_uuid' => $uuid,
                    'child_block_uuid' => $childUuid,
                    'index' => 3,
                    'arguments' => [
                        'positionId' => $position->id,
                    ],
                ]);
            });
    }

    public function resolveException(Throwable $e)
    {
        NotificationThrottler::sendToAdmin(
            messageCanonical: 'launch_positions_watchers',
            message: "[{$this->account->id}] Account {$this->account->user->name}/{$this->account->tradingQuote->canonical} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
            title: "[S:{$this->step->id} ".class_basename(self::class).'] - Error',
            deliveryGroup: 'exceptions'
        );
    }
}
