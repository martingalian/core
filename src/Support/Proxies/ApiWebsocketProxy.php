<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Proxies;

use Exception;
use Martingalian\Core\Support\Apis\Websocket\BinanceApi;
use Martingalian\Core\Support\Apis\Websocket\BitgetApi;
use Martingalian\Core\Support\Apis\Websocket\BybitApi;
use Martingalian\Core\Support\Apis\Websocket\KrakenApi;
use Martingalian\Core\Support\Apis\Websocket\KucoinApi;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;

/**
 * @method void markPrices(array $callbacksOrSymbols, array|bool $callbacksOrPrefersSlowerUpdate = false)
 */
final class ApiWebsocketProxy
{
    private $api;

    public function __construct(string $apiType, ApiCredentials $credentials)
    {
        // Instantiate appropriate WebSocket API class based on the API type
        switch ($apiType) {
            case 'binance':
                $this->api = new BinanceApi($credentials);
                break;
            case 'bybit':
                $this->api = new BybitApi($credentials);
                break;
            case 'kraken':
                $this->api = new KrakenApi($credentials);
                break;
            case 'kucoin':
                $this->api = new KucoinApi($credentials);
                break;
            case 'bitget':
                $this->api = new BitgetApi($credentials);
                break;
            default:
                throw new Exception("Unsupported WebSocket API: {$apiType}");
        }
    }

    /**
     * Magic method to dynamically call methods on the specific WebSocket API class.
     */
    public function __call($method, $arguments)
    {
        // Check if the method exists on the instantiated WebSocket API class
        if (method_exists($this->api, $method)) {
            return call_user_func_array([$this->api, $method], $arguments);
        }

        throw new Exception("Method {$method} does not exist for this WebSocket API.");
    }

    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->api->getLoop();
    }
}
