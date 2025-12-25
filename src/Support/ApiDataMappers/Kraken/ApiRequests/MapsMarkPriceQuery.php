<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsMarkPriceQuery
{
    /**
     * Prepare properties for querying mark price on Kraken Futures.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-tickers/
     */
    public function prepareQueryMarkPriceProperties(ExchangeSymbol $exchangeSymbol): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);
        $properties->set('options.symbol', (string) $exchangeSymbol->parsed_trading_pair);

        return $properties;
    }

    /**
     * Resolve the mark price response from Kraken.
     *
     * Kraken tickers response structure:
     * {
     *     "result": "success",
     *     "tickers": [
     *         {
     *             "symbol": "pf_xbtusd",
     *             "markPrice": 45000.50,
     *             "indexPrice": 44980.20,
     *             "bid": 45000.0,
     *             "ask": 45001.0,
     *             ...
     *         }
     *     ]
     * }
     *
     * Note: This method expects the response to be filtered for the specific symbol,
     * or the caller should handle finding the right ticker.
     */
    public function resolveQueryMarkPriceResponse(Response $response): ?string
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // If response is a single ticker or already filtered
        if (array_key_exists(key: 'markPrice', array: $data)) {
            return (string) $data['markPrice'];
        }

        // If response contains tickers array
        $tickers = $data['tickers'] ?? [];

        if (! empty($tickers)) {
            // Return first ticker's mark price (assumes pre-filtered or single result)
            $ticker = is_array($tickers[0] ?? null) ? $tickers[0] : $tickers;

            if (isset($ticker['markPrice'])) {
                return (string) $ticker['markPrice'];
            }
        }

        return null;
    }
}
