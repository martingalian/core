<?php

namespace Martingalian\Core\Abstracts;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;

/*
 * BaseIndicator
 *
 * • Abstract base class for all indicator logic (ADX, RSI, etc.).
 * • Dynamically loads parameters from DB based on class type.
 * • Accepts constructor-level parameter overrides (e.g., interval).
 * • Executes indicator API call using a proxy over 'taapi'.
 * • Stores raw and processed data in `$this->data`.
 * • Supports dynamic timestamps and human-readable conversions.
 * • Provides structure for result access (`data()`) and conclusion logic.
 * • Subclasses must implement `conclusion()` to return interpreted signal.
 */
abstract class BaseIndicator
{
    // Unique identifier for the indicator call (optional use-case).
    public string $id;

    // API endpoint name to use (e.g., 'adx', 'rsi').
    public string $endpoint;

    // Full payload to send in the request.
    public array $parameters = [];

    // Holds the result from the indicator API.
    protected array $data = [];

    // Optional: defines which exchange symbol is being queried.
    public ?ExchangeSymbol $exchangeSymbol = null;

    public function __construct(ExchangeSymbol $exchangeSymbol, array $parameters = [])
    {
        $this->exchangeSymbol = $exchangeSymbol;

        // Identify child class to match the correct Indicator model.
        $childClass = get_called_class();

        // Load default indicator parameters from DB.
        $indicator = Indicator::where('class', $childClass)->first();
        $indicatorParams = $indicator->parameters ?? [];

        // Merge default DB parameters with constructor overrides.
        $mergedParams = array_merge($indicatorParams, $parameters);

        // Enforce the presence of the "interval" parameter.
        if (! array_key_exists('interval', $mergedParams)) {
            throw new \Exception('Indicator misses key -- interval --');
        }

        // Inject standard flag to return timestamps in TAAPI responses.
        $mergedParams['addResultTimestamp'] = true;

        // Apply parameters one by one.
        foreach ($mergedParams as $key => $value) {
            $this->addParameter($key, $value);
        }
    }

    // Retrieve previously fetched data.
    public function data($addTimestampForHumans = false)
    {
        if ($addTimestampForHumans) {
            $this->addTimestampForHumans();
        }

        return $this->data;
    }

    final public function compute()
    {
        /*
         * Perform API call and convert timestamps for readability.
         * Returns the full processed indicator data array.
         */
        $this->apiQuery();

        $this->addTimestampForHumans();

        return $this->data;
    }

    public function parameters()
    {
        return $this->parameters;
    }

    // Add/override a specific parameter.
    public function addParameter(string $key, $value)
    {
        $this->parameters[$key] = $value;
    }

    // Perform the API call using the taapi proxy and process response.
    public function apiQuery()
    {
        $apiAccount = Account::admin('taapi');

        if (! $this->exchangeSymbol) {
            throw new \Exception('No exchange symbol defined for the indicator query');
        }

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        $this->parameters['endpoint'] = $this->endpoint;

        $apiProperties = $apiDataMapper->prepareQueryIndicatorProperties($this->exchangeSymbol, $this->parameters);

        $this->data = $apiDataMapper->resolveQueryIndicatorResponse(
            $apiAccount->withApi()->getIndicatorValues($apiProperties)
        );
    }

    // Load previously stored API response data (e.g., for caching).
    public function load(array $data)
    {
        $this->data = $data;
    }

    /**
     * Abstract method that should return a simplified decision:
     * - Numeric value (e.g. 70)
     * - Boolean true/false
     * - String: LONG / SHORT
     * - null
     */
    abstract public function conclusion();

    /**
     * Make $this->data include human-readable timestamps — safely, for
     *  - a single candle (assoc array),
     *  - a list of candles (results = X),
     *  - or legacy mapped arrays with "timestamp" list.
     *
     * Also materializes a flat "timestamp" array when a list of candles is returned,
     * so callers (e.g., storing history) can always rely on it.
     */
    protected function addTimestampForHumans()
    {
        // Case 1: Legacy/flat shape with 'timestamp' already present
        if (isset($this->data['timestamp'])) {
            if (is_array($this->data['timestamp'])) {
                $this->data['timestamp_for_humans'] = array_map(function ($ts) {
                    return date('Y-m-d H:i:s', (int) $ts);
                }, $this->data['timestamp']);
            } else {
                $this->data['timestamp_for_humans'] = date('Y-m-d H:i:s', (int) $this->data['timestamp']);
            }

            return;
        }

        // Case 2: A list of candles (numeric keys) — extract timestamps column if available
        if (is_array($this->data) && array_key_exists(0, $this->data) && is_array($this->data[0])) {
            $timestamps = [];
            foreach ($this->data as $row) {
                if (isset($row['timestamp'])) {
                    $timestamps[] = (int) $row['timestamp'];
                }
            }

            if (! empty($timestamps)) {
                // Materialize a flat timestamp array + its human variant
                $this->data['timestamp'] = $timestamps;
                $this->data['timestamp_for_humans'] = array_map(function ($ts) {
                    return date('Y-m-d H:i:s', (int) $ts);
                }, $timestamps);
            }

            // If there are no timestamps per candle, we silently skip
            return;
        }

        // Case 3: Single candle assoc without explicit guard above
        if (is_array($this->data) && isset($this->data['timestamp'])) {
            $this->data['timestamp_for_humans'] = date('Y-m-d H:i:s', (int) $this->data['timestamp']);
        }
    }
}
