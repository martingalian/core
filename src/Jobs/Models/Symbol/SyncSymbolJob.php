<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Symbol;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Debuggable;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Support\Martingalian;

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

        Debuggable::debug($this->symbol, 'CMC data synced', $this->symbol->token);

        // Notify admin when symbol is successfully synced with CMC data
        if ($this->symbol->cmc_id) {
            Martingalian::notifyAdmins(
                message: "Symbol {$this->symbol->token} successfully synced with CoinMarketCap (CMC ID: {$this->symbol->cmc_id})",
                title: 'Symbol Synced',
                deliveryGroup: 'default'
            );
        }
    }
}
