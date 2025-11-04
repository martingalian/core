<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Account;

use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\Throttler;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Account;
use Throwable;

/*
 * AssignTokensToNewPositionsJob
 *
 * • Handles token assignment for new positions of a given account.
 * • Runs only if there are "new" positions with no assigned exchange symbol.
 * • Uses account's internal logic to assign best token to each eligible position.
 * • Cancels positions that could not be matched with an exchange symbol.
 * • On exception, sends a pushover alert to admins with error details.
 */
final class AssignTokensToNewPositionsJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        // Retrieve the account by ID and fail if not found.
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable()
    {
        // Allow job logs to be associated with the account model.
        return $this->account;
    }

    public function startOrSkip()
    {
        /*
         * Determine if the job should run:
         * - There must be at least one "new" position with no exchange symbol.
         */
        $exists = $this->account
            ->positions()
            ->where('positions.status', 'new')
            ->whereNull('positions.exchange_symbol_id')
            ->exists();

        if (! $exists) {
            $this->step->updateSaving(['response' => ['response' => 'None of the new positions need tokens to be assigned. Skiping job']]);
        }

        return $exists;
    }

    public function compute()
    {
        // Attempt to assign best available tokens to new positions.
        $tokens = $this->account->assignBestTokenToNewPositions();

        /*
         * DELETE any positions that are still unmatched after assignment.
         */
        $this->account
            ->positions()
            ->where('positions.status', 'new')
            ->whereNull('positions.exchange_symbol_id')
            ->each(function ($position) {
                $position->forceDelete();
            });

        return ['response' => 'Tokens: '.$tokens];
    }

    public function resolveException(Throwable $e)
    {
        /*
         * Notify admins via Pushover if any exception occurs during job.
         * Includes account ID, user name, quote symbol, and error summary.
         */
        Throttler::using(NotificationService::class)
            ->withCanonical('assign_tokens_positions')
            ->execute(function () {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$this->account->id}] Account {$this->account->user->name}/{$this->account->tradingQuote->canonical} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
