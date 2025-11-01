<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Symbol;

use App\Support\NotificationService;
use App\Support\Throttler;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Symbol;

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
            Throttler::using(NotificationService::class)
                ->withCanonical('throttle_3600')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "Symbol {$this->symbol->token} successfully synced with CoinMarketCap (CMC ID: {$this->symbol->cmc_id})",
                        title: 'Symbol Synced',
                        deliveryGroup: 'default'
                    );
                });
        }
    }
}
