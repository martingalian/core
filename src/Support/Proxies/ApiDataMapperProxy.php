<?php

namespace Martingalian\Core\Support\Proxies;

use Martingalian\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;
use Martingalian\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper;
use Martingalian\Core\Support\ApiDataMappers\CoinmarketCap\CoinmarketCapDataMapper;
use Martingalian\Core\Support\ApiDataMappers\Taapi\TaapiApiDataMapper;

/**
 * @method string baseWithQuote(string $base, string $quote)
 * @method \Martingalian\Core\Support\ValueObjects\ApiProperties prepareGroupedQueryIndicatorsProperties(\Martingalian\Core\Models\ExchangeSymbol $exchangeSymbol, \Illuminate\Support\Collection $indicators, string $timeframe)
 * @method array resolveGroupedQueryIndicatorsResponse(\GuzzleHttp\Psr7\Response $response)
 */
class ApiDataMapperProxy
{
    protected $api;

    public function __construct(string $apiCanonical)
    {
        // Instantiate appropriate API class based on the API type
        switch ($apiCanonical) {
            case 'binance':
                $this->api = new BinanceApiDataMapper;
                break;
            case 'taapi':
                $this->api = new TaapiApiDataMapper;
                break;
            case 'coinmarketcap':
                $this->api = new CoinmarketCapDataMapper;
                break;
            case 'bybit':
                $this->api = new BybitApiDataMapper;
                break;
            default:
                throw new \Exception('Unsupported API Mapper: '.$apiCanonical);
        }
    }

    /**
     * Magic method to dynamically call methods on the specific API class.
     */
    public function __call($method, $arguments)
    {
        // Check if the method exists on the instantiated API class
        if (method_exists($this->api, $method)) {
            return call_user_func_array([$this->api, $method], $arguments);
        }

        throw new \Exception("Method {$method} does not exist for this API.");
    }
}
