<?php

namespace Martingalian\Core\Jobs\Lifecycles\Accounts;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Lifecycles\Positions\DispatchPositionJob;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\User;

class DispatchNewPositionsWithTokensAssignedJob extends BaseQueueableJob
{
    public int $accountId;

    public ?Account $account = null;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function compute()
    {
        $positionsQuery = $this->account->positions()
            ->where('positions.status', 'new')
            ->whereNotNull('exchange_symbol_id');

        $count = (int) $positionsQuery->count();

        foreach ($positionsQuery->cursor() as $position) {
            Step::create([
                'class' => DispatchPositionJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                ],
            ]);
        }

        return ['result' => "Dispatching {$count} new Positions"];
    }

    public function resolveException(\Throwable $e)
    {
        User::notifyAdminsViaPushover(
            "Account {$this->account->user->name}/{$this->account->tradingQuote->canonical} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
            "[S:{$this->step->id} A:{$this->account->id}] - [".class_basename(static::class).'] - Error',
            'nidavellir_errors'
        );
    }
}
