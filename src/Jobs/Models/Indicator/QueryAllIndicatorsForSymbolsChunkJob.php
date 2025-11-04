<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Indicator;

use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\Throttler;
use Exception;
use Illuminate\Support\Collection;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * QueryAllIndicatorsForSymbolsChunkJob
 *
 * Batch-queries multiple exchange symbols with ALL indicators using Taapi's bulk API.
 * Each construct contains one symbol with ALL indicators, allowing efficient batching.
 * Stores results in indicator_histories table for downstream consumption.
 *
 * This reduces API calls from N symbols (one bulk call per symbol with all indicators)
 * to ceil(N / chunk_size) calls.
 */
final class QueryAllIndicatorsForSymbolsChunkJob extends BaseApiableJob
{
    /** @var int[] */
    public array $exchangeSymbolIds;

    public string $timeframe;

    /**
     * @param  int[]  $exchangeSymbolIds
     * @param  string  $timeframe  The timeframe to query (e.g., '1h', '4h')
     */
    public function __construct(array $exchangeSymbolIds, string $timeframe)
    {
        $this->exchangeSymbolIds = $exchangeSymbolIds;
        $this->timeframe = $timeframe;
        $this->retries = 150;
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
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] computeApiable() starting...');

        // Load models here, not in constructor
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Loading exchange symbols...');
        $exchangeSymbols = ExchangeSymbol::query()
            ->whereIn('id', $this->exchangeSymbolIds)
            ->get()
            ->keyBy('id');
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Loaded '.$exchangeSymbols->count().' exchange symbols');

        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Loading indicators...');
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->where('is_computed', false)
            ->where('type', 'refresh-data')
            ->get();
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Loaded '.$indicators->count().' indicators');

        if ($exchangeSymbols->isEmpty()) {
            info_if('[QueryAllIndicatorsForSymbolsChunkJob] ERROR: No exchange symbols found');

            return ['stored' => 0, 'errors' => ['No exchange symbols found']];
        }

        if ($indicators->isEmpty()) {
            info_if('[QueryAllIndicatorsForSymbolsChunkJob] ERROR: No indicators found');

            return ['stored' => 0, 'errors' => ['No indicators found']];
        }

        // Build bulk request constructs (one per symbol, each with ALL indicators)
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Building constructs...');
        $constructs = $this->buildConstructs($exchangeSymbols, $indicators);
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Built '.count($constructs).' constructs');

        // Use the proper infrastructure to make the API call
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Loading Taapi account...');
        $apiAccount = Account::admin('taapi');

        // Build API properties for bulk request
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Building API properties...');
        $apiProperties = new ApiProperties([
            'constructs' => $constructs,
            'relatable' => $exchangeSymbols->first(),
        ]);
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] API properties built');

        // Make the API call using the proper infrastructure
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Making API call to Taapi...');
        $guzzleResponse = $apiAccount->withApi()->getBulkIndicatorsValues($apiProperties);
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] API call completed with status: '.$guzzleResponse->getStatusCode());

        // Parse Guzzle response to array
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Parsing response...');
        $response = json_decode((string) $guzzleResponse->getBody(), true);
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Response parsed, data count: '.(isset($response['data']) ? count($response['data']) : 0));

        // Parse and store results
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Storing results...');
        $result = $this->parseAndStoreResults($response, $exchangeSymbols, $indicators);
        info_if('[QueryAllIndicatorsForSymbolsChunkJob] Results stored: '.json_encode($result));

        // Add request and response for debugging
        $result['debug_request'] = [
            'construct' => $constructs,
        ];
        $result['debug_response'] = $response;

        info_if('[QueryAllIndicatorsForSymbolsChunkJob] computeApiable() completed successfully');

        return $result;
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('query_all_indicators_chunk')
            ->execute(function () {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: '[Timeframe:'.$this->timeframe.'] Chunk query error - '.$e->getMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });
    }

    /**
     * Build Taapi bulk API constructs array.
     * Each construct represents one symbol + ALL indicators.
     *
     * Format: [
     *   {exchange, symbol, interval, indicators: [{indicator, period, ...}, ...]}
     * ]
     */
    private function buildConstructs(Collection $exchangeSymbols, Collection $indicators): array
    {
        $constructs = [];

        foreach ($exchangeSymbols as $exchangeSymbol) {
            $indicatorsArray = [];

            // Build ALL indicator parameter sets for this symbol
            foreach ($indicators as $indicator) {
                $indicatorClass = $indicator->class;
                $indicatorInstance = new $indicatorClass($exchangeSymbol, array_merge(
                    $indicator->parameters ?? [],
                    ['interval' => $this->timeframe]
                ));

                $params = $indicatorInstance->parameters();
                $params['indicator'] = $indicatorInstance->endpoint;

                // Remove Taapi-internal flags
                unset($params['endpoint'], $params['interval'], $params['addResultTimestamp']);

                $indicatorsArray[] = $params;
            }

            $constructs[] = [
                'exchange' => mb_strtolower($exchangeSymbol->apiSystem->taapi_canonical),
                'symbol' => str_replace('-', '/', $exchangeSymbol->symbol->token.'/'.$exchangeSymbol->quote->canonical),
                'interval' => $this->timeframe,
                'indicators' => $indicatorsArray,
            ];
        }

        return $constructs;
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
    private function parseAndStoreResults(array $response, Collection $exchangeSymbols, Collection $indicators): array
    {
        $data = $response['data'] ?? [];
        $stored = 0;
        $errors = [];
        $now = now();

        foreach ($data as $entry) {
            $id = $entry['id'] ?? null;
            $result = $entry['result'] ?? null;
            $entryErrors = $entry['errors'] ?? [];
            $taapiIndicator = $entry['indicator'] ?? null;

            if (! $id || ! $result) {
                if (! empty($entryErrors)) {
                    $errors[] = "ID: {$id} - Errors: ".json_encode($entryErrors);
                }

                continue;
            }

            // Parse ID: "binance_BTC/USDT_1h_ema_40_2_1"
            // Format: {exchange}_{symbol}_{interval}_{indicator}_{params...}
            try {
                $idParts = explode('_', $id);
                $exchange = $idParts[0] ?? null;
                $symbol = $idParts[1] ?? null;
                $interval = $idParts[2] ?? null;

                if (! $exchange || ! $symbol || ! $interval) {
                    throw new Exception("Invalid ID format: {$id}");
                }

                // Find matching exchange symbol
                $symbolToken = explode('/', $symbol)[0] ?? null;
                $quoteToken = explode('/', $symbol)[1] ?? null;

                $exchangeSymbol = $exchangeSymbols->first(function ($es) use ($symbolToken, $quoteToken, $exchange) {
                    return $es->symbol->token === $symbolToken &&
                           $es->quote->canonical === $quoteToken &&
                           mb_strtolower($es->apiSystem->taapi_canonical) === $exchange;
                });

                if (! $exchangeSymbol) {
                    $errors[] = "Symbol not found: {$exchange}_{$symbol}";

                    continue;
                }

                // Match indicator by endpoint and optional period parameter
                // For EMA: ID has "ema_40" -> endpoint="ema", need to match "ema-40" in DB
                $indicatorEndpoint = $taapiIndicator ?? $idParts[3] ?? null;
                $periodParam = null;

                // Check if this is an EMA with period in the ID (position 4)
                if ($indicatorEndpoint === 'ema' && isset($idParts[4]) && is_numeric($idParts[4])) {
                    $periodParam = $idParts[4];
                }

                // Try to match indicator
                $indicator = $indicators->first(function ($ind) use ($indicatorEndpoint, $periodParam, $exchangeSymbols) {
                    // Check if indicator's endpoint matches
                    $indClass = $ind->class;
                    if (! class_exists($indClass)) {
                        return false;
                    }

                    $tempInstance = new $indClass($exchangeSymbols->first(), ['interval' => '1h']);
                    if ($tempInstance->endpoint !== $indicatorEndpoint) {
                        return false;
                    }

                    // For EMA, also match period
                    if ($indicatorEndpoint === 'ema' && $periodParam) {
                        $expectedCanonical = "ema-{$periodParam}";

                        return $ind->canonical === $expectedCanonical;
                    }

                    return true;
                });

                if (! $indicator) {
                    $errors[] = "Indicator not found: {$indicatorEndpoint}".($periodParam ? "-{$periodParam}" : '');

                    continue;
                }

                // Taapi bulk API doesn't include timestamps - use current time
                $timestamp = now()->timestamp;

                // Instantiate indicator to call conclusion()
                $indicatorClass = $indicator->class;
                $indicatorInstance = new $indicatorClass($exchangeSymbol, ['interval' => $interval]);
                $indicatorInstance->load($result);

                $conclusion = $indicatorInstance->conclusion();

                // Upsert to indicator_histories
                IndicatorHistory::query()->upsert(
                    [
                        [
                            'exchange_symbol_id' => $exchangeSymbol->id,
                            'indicator_id' => $indicator->id,
                            'taapi_construct_id' => $id,
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
                    ['taapi_construct_id', 'data', 'conclusion', 'updated_at']
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
