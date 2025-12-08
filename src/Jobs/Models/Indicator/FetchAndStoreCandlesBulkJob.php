<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Indicator;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Candle;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use RuntimeException;
use Throwable;

use function count;

/**
 * FetchAndStoreCandlesBulkJob
 *
 * Batch-fetches candle data for multiple exchange symbols in a single API call
 * using TAAPI's /bulk endpoint with multiple constructs.
 *
 * This reduces API calls from 1-per-symbol to 1-per-chunk, respecting TAAPI's
 * construct limits per plan (Pro: 3, Expert: 10, Max: 20).
 *
 * Each job processes one timeframe for a chunk of symbols (e.g., 10 symbols × 1h).
 *
 * For requests > 20 candles, the job automatically paginates using backtrack,
 * making multiple bulk API calls (e.g., 100 candles = 5 calls with backtrack 0, 20, 40, 60, 80).
 */
final class FetchAndStoreCandlesBulkJob extends BaseApiableJob
{
    /** Maximum candles per construct in TAAPI bulk API */
    private const TAAPI_BULK_MAX_RESULTS = 20;
    /** @var int[] Array of exchange symbol IDs to process */
    public array $exchangeSymbolIds;

    /** The timeframe for this batch (e.g., '1h', '4h', '1d') */
    public string $timeframe;

    /** Number of candle results to fetch per symbol */
    public int $results;

    /** Backtrack offset for historical data */
    public int $backtrack;

    /** @var Collection<int, ExchangeSymbol> Loaded exchange symbols keyed by ID */
    private Collection $exchangeSymbols;

    /**
     * @param  int[]  $exchangeSymbolIds  Array of exchange symbol IDs to fetch candles for
     * @param  string  $timeframe  The candle timeframe (e.g., '1h', '4h', '1d')
     * @param  int  $results  Number of candles to fetch (default: 24)
     * @param  int  $backtrack  Offset for historical data (default: 0)
     */
    public function __construct(
        array $exchangeSymbolIds,
        string $timeframe,
        int $results = 24,
        int $backtrack = 0
    ) {
        $this->exchangeSymbolIds = $exchangeSymbolIds;
        $this->timeframe = $timeframe;
        $this->results = $results;
        $this->backtrack = $backtrack;
        $this->retries = 300;

        // Load exchange symbols with relationships for construct building
        $this->exchangeSymbols = ExchangeSymbol::query()
            ->with(['symbol', 'apiSystem'])
            ->whereIn('id', $exchangeSymbolIds)
            ->get()
            ->keyBy('id');
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        // Return the first exchange symbol for logging/tracking purposes
        return $this->exchangeSymbols->first();
    }

    public function computeApiable()
    {
        if ($this->exchangeSymbols->isEmpty()) {
            return ['stored' => 0, 'errors' => ['No exchange symbols found']];
        }

        // Significant jitter to prevent concurrent request storms
        Sleep::for(random_int(500, 2000))->milliseconds();

        // Build bulk request constructs (one per symbol)
        $constructs = $this->buildConstructs();

        // Make bulk API call
        $response = $this->callBulkApi($constructs);

        // Parse and store candles for each symbol
        return $this->parseAndStoreResults($response);
    }

    /**
     * Build TAAPI bulk API constructs array.
     * Each construct represents one symbol with its candle request.
     *
     * Format per construct:
     * {
     *   "id": "symbol_id_123",
     *   "exchange": "binance",
     *   "symbol": "BTC/USDT",
     *   "interval": "1h",
     *   "indicators": [
     *     {"indicator": "candle", "results": 20, "backtrack": 0, "addResultTimestamp": true}
     *   ]
     * }
     */
    private function buildConstructs(): array
    {
        $constructs = [];

        foreach ($this->exchangeSymbols as $exchangeSymbol) {
            // Get the proper symbol format for TAAPI
            $symbol = $this->buildSymbolForTaapi($exchangeSymbol);

            $constructs[] = [
                'id' => (string) $exchangeSymbol->id, // Use ID for response mapping
                'exchange' => mb_strtolower($exchangeSymbol->apiSystem->taapi_canonical ?? $exchangeSymbol->apiSystem->canonical),
                'symbol' => $symbol,
                'interval' => $this->timeframe,
                'indicators' => [
                    [
                        'indicator' => 'candle',
                        'results' => $this->results,
                        'backtrack' => $this->backtrack,
                        'addResultTimestamp' => true,
                    ],
                ],
            ];
        }

        return $constructs;
    }

    /**
     * Build the symbol string for TAAPI API (e.g., "BTC/USDT").
     * Uses token and quote columns directly from exchange_symbols.
     */
    private function buildSymbolForTaapi(ExchangeSymbol $exchangeSymbol): string
    {
        return "{$exchangeSymbol->token}/{$exchangeSymbol->quote}";
    }

    /**
     * Call TAAPI's bulk API with the constructs payload.
     */
    private function callBulkApi(array $constructs): array
    {
        $apiAccount = Account::admin('taapi');
        $secret = $apiAccount->taapi_secret;

        $payload = [
            'secret' => $secret,
            'construct' => $constructs,
        ];

        // Link API request log to the Step if running via Step dispatcher
        if (isset($this->step)) {
            $payload['relatable'] = $this->step;
        }

        // Don't wrap in 'options' - for POST JSON requests, payload goes at root level
        $apiProperties = new ApiProperties($payload);

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

        return json_decode((string) $guzzleResponse->getBody(), true);
    }

    /**
     * Parse TAAPI bulk response and store candles for each symbol.
     *
     * Response format:
     * {
     *   "data": [
     *     {
     *       "id": "123_candle",
     *       "result": [
     *         {"timestamp": 1234567890, "open": 100, "high": 105, "low": 99, "close": 102, "volume": 1000},
     *         ...
     *       ],
     *       "errors": []
     *     },
     *     ...
     *   ]
     * }
     */
    private function parseAndStoreResults(array $response): array
    {
        $data = $response['data'] ?? [];
        $stored = 0;
        $errors = [];

        foreach ($data as $entry) {
            $id = $entry['id'] ?? null;
            $result = $entry['result'] ?? null;
            $entryErrors = $entry['errors'] ?? [];

            if (! $id || $result === null) {
                if (! empty($entryErrors)) {
                    $errors[] = "ID: {$id} - Errors: ".json_encode($entryErrors);
                }

                continue;
            }

            try {
                // Parse TAAPI response ID format: "{exchange}_{symbol}_{interval}_{indicator}_{params...}"
                // Example: "binancefutures_BTC/USDT_1h_candle_20_0_true"
                $exchangeSymbol = $this->findExchangeSymbolFromResponseId($id);

                if (! $exchangeSymbol) {
                    $errors[] = "Exchange symbol not found for ID: {$id}";

                    continue;
                }

                // Store candles for this symbol
                $storedCount = $this->storeCandles($exchangeSymbol, $result);
                $stored += $storedCount;
            } catch (Throwable $e) {
                $errors[] = "Parse error for {$id}: {$e->getMessage()}";
            }
        }

        return [
            'stored' => $stored,
            'errors' => $errors,
            'total_responses' => count($data),
            'symbols_processed' => count($this->exchangeSymbolIds),
        ];
    }

    /**
     * Find the exchange symbol from TAAPI response ID.
     *
     * TAAPI returns IDs in format: "{exchange}_{symbol}_{interval}_{indicator}_{params...}"
     * Example: "binancefutures_BTC/USDT_1h_candle_20_0_true"
     */
    private function findExchangeSymbolFromResponseId(string $responseId): ?ExchangeSymbol
    {
        $idParts = explode('_', $responseId);

        if (count($idParts) < 3) {
            return null;
        }

        $exchange = $idParts[0] ?? null;
        $symbol = $idParts[1] ?? null;

        if (! $exchange || ! $symbol) {
            return null;
        }

        // Find matching exchange symbol by building TAAPI format and comparing
        return $this->exchangeSymbols->first(function (ExchangeSymbol $es) use ($symbol, $exchange) {
            $apiCanonical = mb_strtolower($es->apiSystem->taapi_canonical ?? $es->apiSystem->canonical);

            // Build the TAAPI symbol format using the same method as request building
            $taapiSymbol = $this->buildSymbolForTaapi($es);

            return $taapiSymbol === $symbol && $apiCanonical === $exchange;
        });
    }

    /**
     * Store candles for a single exchange symbol.
     * Handles both array of candles and single candle responses.
     */
    private function storeCandles(ExchangeSymbol $exchangeSymbol, mixed $result): int
    {
        $rows = $this->normalizeToRows($result);

        if (empty($rows)) {
            return 0;
        }

        $now = now();
        $count = 0;
        $chunkSize = 50;
        $buffer = [];

        foreach ($rows as $row) {
            // Require OHLC + timestamp
            if (! isset($row['timestamp'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                continue;
            }

            // Normalize epoch (accept seconds or milliseconds)
            $epochSec = $this->normalizeEpochToSeconds($row['timestamp']);

            // Convert to SQL datetime in UTC
            $candleTime = Carbon::createFromTimestampUTC($epochSec)->format('Y-m-d H:i:s');

            $buffer[] = [
                'exchange_symbol_id' => $exchangeSymbol->id,
                'timeframe' => $this->timeframe,
                'timestamp' => $epochSec,
                'candle_time' => $candleTime,
                'open' => (string) $row['open'],
                'high' => (string) $row['high'],
                'low' => (string) $row['low'],
                'close' => (string) $row['close'],
                'volume' => (string) ($row['volume'] ?? '0'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Flush in chunks
            if (count($buffer) >= $chunkSize) {
                $this->upsertWithLock($exchangeSymbol, $buffer);
                $count += count($buffer);
                $buffer = [];
            }
        }

        // Flush remaining
        if (! empty($buffer)) {
            $this->upsertWithLock($exchangeSymbol, $buffer);
            $count += count($buffer);
        }

        return $count;
    }

    /**
     * Normalize provider output into a list of rows with canonical keys.
     */
    private function normalizeToRows(mixed $data): array
    {
        // Array of candle objects
        if (is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        // Columnar arrays
        if (is_array($data) &&
            isset($data['timestamp'], $data['open'], $data['high'], $data['low'], $data['close']) &&
            is_array($data['timestamp']) && is_array($data['open']) && is_array($data['high']) &&
            is_array($data['low']) && is_array($data['close'])
        ) {
            $ts = $data['timestamp'];
            $open = $data['open'];
            $high = $data['high'];
            $low = $data['low'];
            $close = $data['close'];
            $volume = isset($data['volume']) && is_array($data['volume']) ? $data['volume'] : null;

            $len = min(
                count($ts),
                count($open),
                count($high),
                count($low),
                count($close),
                $volume ? count($volume) : PHP_INT_MAX
            );

            $rows = [];
            for ($i = 0; $i < $len; $i++) {
                $rows[] = [
                    'timestamp' => (int) $ts[$i],
                    'open' => $open[$i],
                    'high' => $high[$i],
                    'low' => $low[$i],
                    'close' => $close[$i],
                    'volume' => $volume ? $volume[$i] : null,
                ];
            }

            return $rows;
        }

        // Single candle object
        if (is_array($data) && isset($data['timestamp'], $data['open'], $data['high'], $data['low'], $data['close'])) {
            return [$data];
        }

        return [];
    }

    /**
     * Normalize epoch to seconds (handles both seconds and milliseconds).
     */
    private function normalizeEpochToSeconds(mixed $epoch): int
    {
        $val = (int) $epoch;

        // If >= 10^12 (year 2001+ in ms), assume milliseconds
        if ($val >= 1_000_000_000_000) {
            return intdiv($val, 1000);
        }

        return $val;
    }

    /**
     * Upsert candles with advisory lock to prevent deadlocks.
     * Uses MySQL GET_LOCK to serialize upserts per symbol/timeframe.
     */
    private function upsertWithLock(ExchangeSymbol $exchangeSymbol, array $buffer, int $maxAttempts = 5): void
    {
        $lockKey = "candles_{$exchangeSymbol->id}_{$this->timeframe}";
        $lockTimeout = 30;
        $lockAcquired = false;

        try {
            $result = DB::selectOne('SELECT GET_LOCK(?, ?) as lock_result', [$lockKey, $lockTimeout]);

            if (! $result || $result->lock_result !== 1) {
                throw new RuntimeException("Failed to acquire advisory lock for {$lockKey} after {$lockTimeout}s");
            }

            $lockAcquired = true;

            $attempt = 0;

            while ($attempt < $maxAttempts) {
                try {
                    DB::transaction(function () use ($buffer) {
                        Candle::query()->upsert(
                            $buffer,
                            ['exchange_symbol_id', 'timeframe', 'timestamp'],
                            ['open', 'high', 'low', 'close', 'volume', 'candle_time', 'updated_at']
                        );
                    });

                    return;
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '40001' || str_contains($e->getMessage(), 'Deadlock')) {
                        $attempt++;

                        if ($attempt >= $maxAttempts) {
                            throw $e;
                        }

                        $backoffMs = 100 * (2 ** ($attempt - 1));
                        Sleep::for($backoffMs)->milliseconds();

                        continue;
                    }

                    throw $e;
                }
            }
        } finally {
            if ($lockAcquired) {
                DB::selectOne('SELECT RELEASE_LOCK(?) as release_result', [$lockKey]);
            }
        }
    }
}
