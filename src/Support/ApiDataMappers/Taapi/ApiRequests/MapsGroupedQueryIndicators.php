<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Taapi\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Collection;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsGroupedQueryIndicators
{
    public function prepareGroupedQueryIndicatorsProperties(ExchangeSymbol $exchangeSymbol, Collection $indicators, string $timeframe): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        $symbol = $apiDataMapper->baseWithQuote(
            $exchangeSymbol->symbol->token,
            $exchangeSymbol->quote->canonical
        );

        $properties->set('options.symbol', $symbol);
        $properties->set('options.interval', $timeframe);
        $properties->set('options.exchange', $exchangeSymbol->apiSystem->taapi_canonical);
        $properties->set('options.indicators', $this->getIndicatorsListForApi($exchangeSymbol, $indicators, $timeframe));

        return $properties;
    }

    public function resolveGroupedQueryIndicatorsResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param  Collection<int, Indicator>  $indicators
     */
    protected function getIndicatorsListForApi(ExchangeSymbol $exchangeSymbol, Collection $indicators, string $timeframe): array
    {
        $enrichedIndicators = [];

        foreach ($indicators as $indicatorModel) {
            // Instanciate indicator to retrieve the right db parameters.
            $indicatorClass = $indicatorModel->class;
            $indicatorInstance = new $indicatorClass($exchangeSymbol, ['interval' => $timeframe]);

            $parameters = $indicatorModel->parameters ?? [];

            $enrichedIndicator = array_merge(
                [
                    'id' => $indicatorModel->canonical,
                    'indicator' => $indicatorInstance->endpoint,
                ],
                $indicatorInstance->parameters
            );

            $enrichedIndicators[] = $enrichedIndicator;
        }

        return $enrichedIndicators;
    }
}
