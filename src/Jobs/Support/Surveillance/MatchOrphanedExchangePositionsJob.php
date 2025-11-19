<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support\Surveillance;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
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
            // Removed NotificationService::send - invalid canonical: orphaned_positions_detected
        }
    }

    public function resolveException(Throwable $e)
    {
        // Removed NotificationService::send - invalid canonical: orphaned_positions_match_error
    }
}
