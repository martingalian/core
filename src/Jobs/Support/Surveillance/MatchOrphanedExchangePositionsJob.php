<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support\Surveillance;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\NotificationThrottler;
use Throwable;

final class MatchOrphanedExchangePositionsJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function compute()
    {
        $positionsOnExchange = ApiSnapshot::getFrom($this->account, 'account-positions');
        $positionsOnDB = $this->account->positions()->ongoing()->get();

        $exchangeSymbolDirections = collect($positionsOnExchange)
            ->flatMap(function ($entries, $symbol) {
                $entries = is_array($entries) ? $entries : [$entries];

                return collect($entries)
                    ->filter(fn ($p) => isset($p['positionAmt']) && (float) $p['positionAmt'] !== 0)
                    ->map(fn ($p) => $symbol.':'.($p['positionAmt'] > 0 ? 'LONG' : 'SHORT'));
            })
            ->unique()
            ->sort()
            ->values();

        $dbSymbolDirections = $positionsOnDB
            ->map(fn ($p) => $p->parsed_trading_pair.':'.$p->direction)
            ->unique()
            ->sort()
            ->values();

        $missingInDB = $exchangeSymbolDirections->diff($dbSymbolDirections);

        if ($missingInDB->isNotEmpty()) {
            $account = $this->account;
            NotificationThrottler::using(NotificationService::class)
                ->withCanonical('orphaned_positions_detected')
                ->execute(function () use ($account, $missingInDB) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "[{$account->id}] Monitoring Synced Positions mismatch: ".$missingInDB->implode(', ').' opened in Exchange and not in DB',
                        title: 'Orphaned Positions Detected',
                        canonical: 'orphaned_positions_detected',
                        deliveryGroup: 'exceptions'
                    );
                });
        }
    }

    public function resolveException(Throwable $e)
    {
        $account = $this->account;
        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('orphaned_positions_match_error')
            ->execute(function () use ($account, $e) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$account->id}] Account {$account->user->name}/{$account->tradingQuote->canonical} surveillance error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    canonical: 'orphaned_positions_match_error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
