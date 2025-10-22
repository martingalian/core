<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Exception;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;

final class UpdateMarkPriceJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable()
    {
        $markPriceResponse = $this->exchangeSymbol->apiQueryMarkPrice();
        $markPrice = (float) $markPriceResponse->result['mark_price'];

        if (! $markPrice || $markPrice <= 0) {
            throw new Exception('Invalid mark price received from exchange.');
        }

        $this->exchangeSymbol->mark_price = $markPrice;
        $this->exchangeSymbol->mark_price_synced_at = now();
        $this->exchangeSymbol->save();
    }
}
