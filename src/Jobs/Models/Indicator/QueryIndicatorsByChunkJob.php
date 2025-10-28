<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Indicator;

use Exception;
use Illuminate\Support\Collection;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use App\Support\NotificationService;
use App\Support\Throttler;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * QueryIndicatorsByChunkJob
 *
 * Batch-queries multiple exchange symbols for a single indicator using Taapi's bulk API.
 * Stores results in indicator_histories table for downstream consumption.
 *
 * This reduces API calls from 1-per-symbol to 1-per-chunk, respecting Taapi's
 * 20 calculations/request limit on Expert plan.
 */
final class QueryIndicatorsByChunkJob extends BaseApiableJob
{
    /** @var int[] */
    public array $exchangeSymbolIds;

    public int $indicatorId;

    public array $parameters;

    private Indicator $indicator;

    /** @var Collection<ExchangeSymbol> */
    private Collection $exchangeSymbols;

    /**
     * @param  int[]  $exchangeSymbolIds
     * @param  array  $parameters  Merged parameters (e.g., interval, backtrack, results)
     */
    public function __construct(array $exchangeSymbolIds, int $indicatorId, array $parameters = [])
    {
        $this->exchangeSymbolIds = $exchangeSymbolIds;
        $this->indicatorId = $indicatorId;
        $this->parameters = $parameters;
        $this->retries = 150;

        // Load models
        $this->indicator = Indicator::findOrFail($indicatorId);
        $this->exchangeSymbols = ExchangeSymbol::query()
            ->whereIn('id', $exchangeSymbolIds)
            ->get()
            ->keyBy('id');
    }

    public function relatable()
    {
        return null;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function computeApiable()
    {
        if ($this->exchangeSymbols->isEmpty()) {
            return ['stored' => 0, 'errors' => ['No exchange symbols found']];
        }

        // Build bulk request constructs (one per symbol)
        $constructs = $this->buildConstructs();

        // Call Taapi bulk API directly (not using getGroupedIndicatorsValues)
        // because we're sending an array of constructs, not a single construct
        $apiAccount = Account::admin('taapi');
        $secret = $apiAccount->taapi_secret;

        // Build API request directly
        $payload = [
            'secret' => $secret,
            'construct' => $constructs, // Array of construct objects
        ];

        $apiProperties = new ApiProperties(['options' => $payload]);

        // Make the API call using the Taapi client
        $taapiClient = new \Martingalian\Core\Support\ApiClients\REST\TaapiApiClient([
            'url' => config('martingalian.api.url.taapi.rest'),
            'secret' => $secret,
        ]);

        $apiRequest = \Martingalian\Core\Support\ValueObjects\ApiRequest::make(
            'POST',
            '/bulk',
            $apiProperties
        );

        $guzzleResponse = $taapiClient->publicRequest($apiRequest);

        // Parse Guzzle response to array
        $response = json_decode((string) $guzzleResponse->getBody(), true);

        // Parse and store results
        return $this->parseAndStoreResults($response);
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
                ->withCanonical('query_indicators_chunk')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[Indicator:{$this->indicatorId}] Chunk query error - {$e->getMessage()}",
                        title: '['.class_basename(self::class).'] - Error',
                        deliveryGroup: 'exceptions'
                    );
                });
    }

    /**
     * Build Taapi bulk API constructs array.
     * Each construct represents one symbol + indicator combination.
     *
     * Format: [
     *   {exchange, symbol, interval, indicators: [{indicator, ...params}]}
     * ]
     */
    private function buildConstructs(): array
    {
        $constructs = [];

        // Instantiate indicator to get endpoint and merge parameters
        foreach ($this->exchangeSymbols as $exchangeSymbol) {
            $indicatorClass = $this->indicator->class;
            $indicatorInstance = new $indicatorClass($exchangeSymbol, $this->parameters);

            $constructs[] = [
                'exchange' => mb_strtolower($exchangeSymbol->apiSystem->canonical),
                'symbol' => str_replace('-', '/', $exchangeSymbol->canonical),
                'interval' => $this->parameters['interval'] ?? $exchangeSymbol->indicators_timeframe,
                'indicators' => [
                    $this->buildIndicatorParameters($indicatorInstance),
                ],
            ];
        }

        return $constructs;
    }

    /**
     * Build indicator-specific parameters from instance.
     * Returns array like: {indicator: 'rsi', period: 14, backtrack: 1, results: 2}
     */
    private function buildIndicatorParameters($indicatorInstance): array
    {
        $params = $indicatorInstance->parameters();
        $params['indicator'] = $indicatorInstance->endpoint;

        // Remove Taapi-internal flags
        unset($params['endpoint'], $params['interval'], $params['addResultTimestamp']);

        return $params;
    }

    /**
     * Parse Taapi bulk response and upsert to indicator_histories.
     *
     * Response format:
     * {
     *   data: [
     *     {id: "binance_BTC/USDT_1h_rsi_14_0", result: {...}, errors: [...]},
     *     ...
     *   ]
     * }
     */
    private function parseAndStoreResults(array $response): array
    {
        $data = $response['data'] ?? [];
        $stored = 0;
        $errors = [];
        $now = now();

        foreach ($data as $entry) {
            $id = $entry['id'] ?? null;
            $result = $entry['result'] ?? null;
            $entryErrors = $entry['errors'] ?? [];

            if (! $id || ! $result) {
                if (! empty($entryErrors)) {
                    $errors[] = "ID: {$id} - Errors: ".json_encode($entryErrors);
                }

                continue;
            }

            // Parse ID: "binance_BTC/USDT_1h_rsi_14_0"
            // Format: {exchange}_{symbol}_{interval}_{indicator}_{params...}_{backtrack}
            try {
                $idParts = explode('_', $id);
                $exchange = $idParts[0] ?? null;
                $symbol = $idParts[1] ?? null;
                $interval = $idParts[2] ?? null;

                if (! $exchange || ! $symbol || ! $interval) {
                    throw new Exception("Invalid ID format: {$id}");
                }

                // Find matching exchange symbol
                $canonicalSymbol = str_replace('/', '-', $symbol);
                $exchangeSymbol = $this->exchangeSymbols->first(function ($es) use ($canonicalSymbol, $exchange) {
                    return $es->canonical === $canonicalSymbol &&
                           mb_strtolower($es->apiSystem->canonical) === $exchange;
                });

                if (! $exchangeSymbol) {
                    $errors[] = "Symbol not found: {$exchange}_{$canonicalSymbol}";

                    continue;
                }

                // Extract timestamp from result (Taapi provides this)
                $timestamp = $result['timestamp'] ?? $result['result_timestamp'] ?? null;

                if (! $timestamp) {
                    $errors[] = "No timestamp in result for {$id}";

                    continue;
                }

                // Instantiate indicator to call conclusion()
                $indicatorClass = $this->indicator->class;
                $indicatorInstance = new $indicatorClass($exchangeSymbol, $this->parameters);
                $indicatorInstance->load($result);

                $conclusion = $indicatorInstance->conclusion();

                // Upsert to indicator_histories
                IndicatorHistory::query()->upsert(
                    [
                        [
                            'exchange_symbol_id' => $exchangeSymbol->id,
                            'indicator_id' => $this->indicatorId,
                            'timeframe' => $interval,
                            'timestamp' => (string) $timestamp,
                            'data' => json_encode($result),
                            'conclusion' => $conclusion,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ],
                    // Unique constraint
                    ['exchange_symbol_id', 'indicator_id', 'timeframe', 'timestamp'],
                    // Update on conflict
                    ['data', 'conclusion', 'updated_at']
                );

                $stored++;
            } catch (Throwable $e) {
                $errors[] = "Parse error for {$id}: {$e->getMessage()}";
            }
        }

        return [
            'stored' => $stored,
            'errors' => $errors,
            'total_responses' => count($data),
        ];
    }
}
