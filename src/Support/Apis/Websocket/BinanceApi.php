<?php

namespace Martingalian\Core\Support\Apis\Websocket;

use Martingalian\Core\Support\ApiClients\REST\BinanceRestApi;
use Martingalian\Core\Support\ApiClients\Websocket\BinanceApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;

class BinanceApi
{
    protected BinanceApiClient $client;

    protected ApiCredentials $credentials;

    public function __construct(ApiCredentials $credentials)
    {
        $this->credentials = $credentials;

        $this->client = new BinanceApiClient([
            'base_url' => config('martingalian.api.url.binance.stream'),
            'api_key' => $credentials->get('binance_api_key'),
            'api_secret' => $credentials->get('binance_api_secret'),
        ]);
    }

    public function markPrices(array $callbacks, bool $prefersSlowerUpdate = false): void
    {
        $streamName = $prefersSlowerUpdate ? '!markPrice@arr' : '!markPrice@arr@1s';
        $this->client->subscribeToStream($streamName, $callbacks);
    }

    public function userStream(array $callbacks): void
    {
        $rest = new BinanceRestApi($this->credentials);
        $listenKey = $rest->createListenKey();

        $this->client->subscribeToUserStream($listenKey, $callbacks);

        // Keep alive every 25 minutes
        $this->client->getLoop()->addPeriodicTimer(1500, function () use ($rest, $listenKey) {
            $rest->keepAliveListenKey($listenKey);
        });
    }

    /**
     * This is the method expected by the command (explicit version of userStream).
     */
    public function userStreamWithKey(string $listenKey, array $callbacks): void
    {
        $this->client->subscribeToUserStream($listenKey, $callbacks);
    }

    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->client->getLoop();
    }
}
