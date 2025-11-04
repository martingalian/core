<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Symbol;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;

/**
 * This job syncs an unique symbol. Normally it's just called once on its
 * lifetime. After that, there are no more changes.
 */
final class SyncSymbolJob extends BaseApiableJob
{
    public Symbol $symbol;

    public function __construct(int $symbolId)
    {
        $this->symbol = Symbol::findOrFail($symbolId);
    }

    public function relatable()
    {
        return $this->symbol;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap')
            ->withAccount(
                Account::admin('coinmarketcap')
            );
    }

    public function computeApiable()
    {
        $this->symbol->apiSyncCMCData();

        // Notify admin when symbol is successfully synced with CMC data
        if ($this->symbol->cmc_id) {
            $symbol = $this->symbol;
            Throttler::using(NotificationService::class)
                ->withCanonical('symbol_cmc_sync_success')
                ->execute(function () use ($symbol) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "Symbol {$symbol->token} successfully synced with CoinMarketCap (CMC ID: {$symbol->cmc_id})",
                        title: 'Symbol Synced',
                        canonical: 'symbol_cmc_sync_success',
                        deliveryGroup: 'default'
                    );
                });
        }
    }
}
