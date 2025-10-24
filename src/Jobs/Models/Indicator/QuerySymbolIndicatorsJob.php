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
use Martingalian\Core\Models\User;
use Martingalian\Core\Support\Throttlers\TaapiThrottler;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * QuerySymbolIndicatorsJob
 *
 * Queries a SINGLE exchange symbol with ALL indicators for a SINGLE timeframe.
 * Part of atomic per-symbol workflow for progressive indicator analysis.
 * Stores results in indicator_histories table.
 */
final class QuerySymbolIndicatorsJob extends BaseApiableJob
{
    public int $exchangeSymbolId;

    public string $timeframe;

    public array $previousConclusions;

    /**
     * @param  int  $exchangeSymbolId  Single symbol to query
     * @param  string  $timeframe  The timeframe to query (e.g., '1h', '4h')
     * @param  array  $previousConclusions  Conclusions from previous timeframes for path consistency
     */
    public function __construct(int $exchangeSymbolId, string $timeframe, array $previousConclusions = [])
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
        $this->timeframe = $timeframe;
        $this->previousConclusions = $previousConclusions;
        $this->retries = 100;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function computeApiable()
    {
        // Record that we're making a request
        TaapiThrottler::recordDispatch();

        // Load the exchange symbol
        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);

        // Load active refresh-data indicators
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->where('is_apiable', true)
            ->where('type', 'refresh-data')
            ->get();

        if ($indicators->isEmpty()) {
            return ['stored' => 0, 'errors' => ['No indicators found']];
        }

        // Build Taapi bulk API construct for this single symbol
        $construct = $this->buildConstruct($exchangeSymbol, $indicators);

        // Use proper API infrastructure
        $apiAccount = Account::admin('taapi');

        // Build API properties for bulk request (single construct)
        $apiProperties = new ApiProperties([
            'constructs' => [$construct],
            'relatable' => $exchangeSymbol,
        ]);

        // Make the API call using proper infrastructure
        $guzzleResponse = $apiAccount->withApi()->getBulkIndicatorsValues($apiProperties);

        // Parse Guzzle response to array
        $response = json_decode((string) $guzzleResponse->getBody(), true);

        // Parse and store results
        $result = $this->parseAndStoreResults($response, $exchangeSymbol, $indicators);

        return $result;
    }

    public function resolveException(Throwable $e)
    {
        User::notifyAdminsViaPushover(
            '[Symbol:'.$this->exchangeSymbolId.' | Timeframe:'.$this->timeframe.'] Query error - '.$e->getMessage(),
            '['.class_basename(self::class).'] - Error',
            'nidavellir_errors'
        );
    }

    /**
     * Check throttling before job execution starts.
     * Called by BaseQueueableJob->shouldExitEarly() BEFORE transitioning to Running state.
     * This prevents the Pending->Running transition error.
     */
    public function startOrRetry(): bool
    {
        // Pass current retry count to throttler for exponential backoff
        $retryCount = $this->step->retries ?? 0;
        $secondsToWait = TaapiThrottler::canDispatch($retryCount);

        if ($secondsToWait > 0) {
            // Set custom backoff delay based on throttler (includes exponential backoff)
            $this->jobBackoffSeconds = $secondsToWait;

            // Return false to trigger retry with the backoff delay
            return false;
        }

        // OK to proceed with job execution
        return true;
    }

    /**
     * Build Taapi bulk API construct for a single symbol.
     */
    private function buildConstruct(ExchangeSymbol $exchangeSymbol, Collection $indicators): array
    {
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

        return [
            'exchange' => mb_strtolower($exchangeSymbol->apiSystem->taapi_canonical),
            'symbol' => str_replace('-', '/', $exchangeSymbol->symbol->token.'/'.$exchangeSymbol->quote->canonical),
            'interval' => $this->timeframe,
            'indicators' => $indicatorsArray,
        ];
    }

    /**
     * Parse Taapi bulk response and upsert to indicator_histories.
     */
    private function parseAndStoreResults(array $response, ExchangeSymbol $exchangeSymbol, Collection $indicators): array
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
            try {
                $idParts = explode('_', $id);
                $exchange = $idParts[0] ?? null;
                $symbol = $idParts[1] ?? null;
                $interval = $idParts[2] ?? null;

                if (! $exchange || ! $symbol || ! $interval) {
                    throw new Exception("Invalid ID format: {$id}");
                }

                // Match indicator by endpoint and optional period parameter
                $indicatorEndpoint = $taapiIndicator ?? $idParts[3] ?? null;
                $periodParam = null;

                // Check if this is an EMA with period in the ID (position 4)
                if ($indicatorEndpoint === 'ema' && isset($idParts[4]) && is_numeric($idParts[4])) {
                    $periodParam = $idParts[4];
                }

                // Try to match indicator
                $indicator = $indicators->first(function ($ind) use ($indicatorEndpoint, $periodParam, $exchangeSymbol) {
                    $indClass = $ind->class;
                    if (! class_exists($indClass)) {
                        return false;
                    }

                    $tempInstance = new $indClass($exchangeSymbol, ['interval' => '1h']);
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

                // Use current timestamp
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
