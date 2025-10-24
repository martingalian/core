<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Indicator;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Candle;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Throwable;

use function count;

final class FetchAndStoreOnCandleJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    /** e.g. 'candles' */
    public string $canonical;

    /** Maps to your Indicator row */
    public string $type;

    /** '1h' | '2h' | '4h' | '1d' */
    public string $timeframe;

    public array $params;

    public function __construct(
        int $exchangeSymbolId,
        string $canonical,
        string $type,
        string $timeframe,
        array $params = []
    ) {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
        $this->canonical = $canonical;
        $this->type = $type;
        $this->timeframe = $timeframe;
        $this->params = $params;
        $this->retries = 20;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable()
    {
        // Resolve indicator implementation
        $indicatorModel = Indicator::query()
            ->where('canonical', $this->canonical)
            ->where('type', $this->type)
            ->where('is_active', 1)
            ->firstOrFail();

        $class = $indicatorModel->class;

        // Merge default params (interval) with caller overrides
        $defaultParams = ['interval' => $this->timeframe];
        $mergedParams = array_replace($defaultParams, $this->params);

        /** @var \Martingalian\Core\Abstracts\BaseIndicator $indicator */
        $indicator = new $class($this->exchangeSymbol, $mergedParams);

        // Gentle jitter to smooth bursts across many workers
        Sleep::for(random_int(500, 1_500))->milliseconds();

        // Fetch raw candles from provider (Taapi)
        $data = $indicator->compute();

        // Normalize into rows with consistent keys
        $rows = $this->normalizeToRows($data);
        if (empty($rows)) {
            return ['stored' => true, 'upserts' => 0];
        }

        $now = now();
        $count = 0;
        $chunkSize = 1000;
        $buffer = [];

        foreach ($rows as $row) {
            // Require OHLC + timestamp
            if (! isset($row['timestamp'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                continue;
            }

            // Normalize epoch (accept seconds or milliseconds)
            $epochSec = $this->normalizeEpochToSeconds($row['timestamp']);

            // Convert to a real SQL datetime in UTC (your new column)
            $candleTime = Carbon::createFromTimestampUTC($epochSec)->format('Y-m-d H:i:s');

            // Buffer row for upsert
            $buffer[] = [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'timeframe' => $this->timeframe,
                'timestamp' => $epochSec,                 // keep raw epoch (seconds)
                'candle_time' => $candleTime,               // new searchable datetime
                'open' => (string) $row['open'],
                'high' => (string) $row['high'],
                'low' => (string) $row['low'],
                'close' => (string) $row['close'],
                'volume' => (string) ($row['volume'] ?? '0'),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Flush in chunks for memory/perf
            if (count($buffer) >= $chunkSize) {
                Candle::query()->upsert(
                    $buffer,
                    // Unique key: avoid duplicates per (symbol, timeframe, timestamp)
                    ['exchange_symbol_id', 'timeframe', 'timestamp'],
                    // On conflict, update latest values including candle_time
                    ['open', 'high', 'low', 'close', 'volume', 'candle_time', 'updated_at']
                );
                $count += count($buffer);
                $buffer = [];
            }
        }

        // Flush tail
        if (! empty($buffer)) {
            Candle::query()->upsert(
                $buffer,
                ['exchange_symbol_id', 'timeframe', 'timestamp'],
                ['open', 'high', 'low', 'close', 'volume', 'candle_time', 'updated_at']
            );
            $count += count($buffer);
        }

        return ['stored' => true, 'upserts' => $count];
    }

    public function ignoreException(Throwable $e)
    {
        $ignoredMessages = [
            'An unknown error occurred. Please check your parameters',
            'Connection reset by peer',
        ];

        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            foreach ($ignoredMessages as $msg) {
                if (str_contains($body, $msg)) {
                    return true;
                }
            }
        }

        foreach ($ignoredMessages as $msg) {
            if (str_contains($e->getMessage(), $msg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize provider output into a list of rows with canonical keys.
     *
     * Accepts:
     *  - list of associative arrays with keys {timestamp, open, high, low, close, volume?}
     *  - columnar arrays: ['timestamp'=>[], 'open'=>[], 'high'=>[], 'low'=>[], 'close'=>[], 'volume'?=>[]]
     *  - single associative array candle
     */
    public function normalizeToRows($data): array
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
     * Decide if an incoming epoch is in seconds or milliseconds and normalize to seconds.
     *
     * - If >= 10^12, assume milliseconds.
     * - Otherwise, assume seconds.
     */
    public function normalizeEpochToSeconds($epoch): int
    {
        // Ensure numeric
        $val = (int) $epoch;

        // Heuristic: >= 1_000_000_000_000 (approx year 2001 in ms) => ms
        if ($val >= 1_000_000_000_000) {
            return intdiv($val, 1000);
        }

        return $val;
    }
}
