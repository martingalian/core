<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\TradeConfiguration;

final class AssessIndicatorConclusionJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function computeApiable()
    {
        $uuid = $this->uuid();

        Step::create([
            'class' => QueryIndicatorJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 1,
            'arguments' => [
                'exchangeSymbolId' => $this->exchangeSymbol->id,
                'timeframe' => TradeConfiguration::getDefault()->indicator_timeframes[0],
            ],
        ]);

        Step::create([
            'class' => ConcludeDirectionJob::class,
            'queue' => 'default',
            'block_uuid' => $uuid,
            'index' => 2,
            'arguments' => [
                'exchangeSymbolId' => $this->exchangeSymbol->id,
            ],
        ]);
    }
}
