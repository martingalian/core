<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Indicator;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Sleep;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\IndicatorHistory;
use Throwable;

final class FetchAndStoreOnHistoryJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public string $canonical;

    public string $type;

    public string $timeframe;

    /**
     * Extra parameters to override indicator defaults.
     */
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
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')->withAccount(Account::admin('taapi'));
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function computeApiable()
    {
        $indicatorModel = Indicator::query()
            ->where('canonical', $this->canonical)
            ->where('type', $this->type)
            ->where('is_active', 1)
            ->first();

        if (! $indicatorModel) {
            return;
        }

        $class = $indicatorModel->class;

        // Default params + overrides (overrides win)
        $defaultParams = ['interval' => $this->timeframe];
        $mergedParams = array_merge($defaultParams, $this->params);

        // Instantiate indicator with merged parameters
        /** @var \Martingalian\Core\Abstracts\BaseIndicator $indicator */
        $indicator = new $class($this->exchangeSymbol, $mergedParams);

        // Randomized short delay to avoid hammering providers
        Sleep::for(random_int(1_000, 2_000))->milliseconds();

        $data = $indicator->compute();     // will call addTimestampForHumans() internally
        $conclusion = $indicator->conclusion();  // array with volatility summary

        // Robust timestamp extraction for history "bucket":
        // 1) If a flat 'timestamp' array exists, use its last value
        // 2) If a flat 'timestamp' scalar exists, use it
        // 3) If data is a list of candle objects, use last candle's 'timestamp'
        // 4) Fallback to now()
        $ts = null;

        if (isset($data['timestamp']) && is_array($data['timestamp']) && count($data['timestamp']) > 0) {
            $last = end($data['timestamp']);
            $ts = (string) $last;
        } elseif (isset($data['timestamp']) && is_scalar($data['timestamp'])) {
            $ts = (string) $data['timestamp'];
        } elseif (is_array($data) && array_key_exists(0, $data) && is_array($data[0]) && isset($data[array_key_last($data)]['timestamp'])) {
            $ts = (string) $data[array_key_last($data)]['timestamp'];
        } else {
            $ts = (string) now()->timestamp;
        }

        $history = IndicatorHistory::updateOrCreate(
            [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'indicator_id' => $indicatorModel->id,
                'timeframe' => $this->timeframe,
                'timestamp' => $ts,
            ],
            [
                // Persist the processed payload with the human timestamps attached
                'data' => $indicator->data(true),
                // Persist conclusion; array → JSON, scalar → cast to string
                'conclusion' => is_scalar($conclusion) ? (string) $conclusion : json_encode($conclusion),
            ]
        );

        return ['stored' => true, 'id' => $history->id];
    }

    public function ignoreException(Throwable $e)
    {
        // List of error message substrings we want to ignore
        $ignoredMessages = [
            'An unknown error occurred. Please check your parameters',
            'Connection reset by peer',
            'Conflict',
        ];

        // Only handle Guzzle RequestExceptions with a response
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();

            foreach ($ignoredMessages as $msg) {
                if (str_contains($body, $msg)) {
                    return true;
                }
            }
        }

        // Also check exception message itself (covers network-level errors)
        foreach ($ignoredMessages as $msg) {
            if (str_contains($e->getMessage(), $msg)) {
                return true;
            }
        }

        return false;
    }
}
