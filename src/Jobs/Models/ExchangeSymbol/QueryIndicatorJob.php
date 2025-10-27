<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Sleep;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\TradeConfiguration;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

final class QueryIndicatorJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public ApiProperties $apiProperties;

    public Response $response;

    public Account $apiAccount;

    public ApiDataMapperProxy $apiDataMapper;

    public string $timeframe;

    public function __construct(int $exchangeSymbolId, ?string $timeframe = null)
    {
        $this->timeframe = ! $timeframe
            ? TradeConfiguration::getDefault()->indicator_timeframes[0]
            : $timeframe;

        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);

        $this->retries = 150;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable(): Response
    {
        $this->apiDataMapper = new ApiDataMapperProxy('taapi');
        $this->apiAccount = Account::admin('taapi');

        // Just to avoid hitting a lot the rate limit threshold.
        Sleep::for(random_int(750, 1250))->milliseconds();

        $indicators = Indicator::active()
            ->apiable()
            ->where('type', 'refresh-data')
            ->get();

        $this->apiProperties = $this->apiDataMapper->prepareGroupedQueryIndicatorsProperties(
            $this->exchangeSymbol,
            $indicators,
            $this->timeframe
        );

        $this->response = $this->apiAccount->withApi()->getGroupedIndicatorsValues($this->apiProperties);

        $this->step->update([
            'response' => $this->apiDataMapper->resolveGroupedQueryIndicatorsResponse($this->response),
        ]);

        return $this->response;
    }
}
