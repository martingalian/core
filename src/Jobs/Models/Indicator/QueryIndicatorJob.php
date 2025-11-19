<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Indicator;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class QueryIndicatorJob extends BaseApiableJob
{
    public Indicator $indicator;

    public ExchangeSymbol $exchangeSymbol;

    public array $parameters = [];

    public function __construct(int $indicatorId, int $exchangeSymbolId, array $parameters = [])
    {
        $this->indicator = Indicator::findOrFail($indicatorId);
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->parameters = $parameters;
        $this->retries = 150;
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function computeApiable()
    {
        $indicator = new ($this->indicator->class)($this->exchangeSymbol, $this->parameters);

        return $indicator->compute();
    }

    public function resolveException(Throwable $e)
    {
        // Removed NotificationService::send - invalid canonical: query_indicator
    }
}
