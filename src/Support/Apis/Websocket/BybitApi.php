<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\Websocket;

use Martingalian\Core\Support\ApiClients\Websocket\BybitApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;

final class BybitApi
{
    private BybitApiClient $client;

    private ApiCredentials $credentials;

    public function __construct(ApiCredentials $credentials)
    {
        $this->credentials = $credentials;

        $this->client = new BybitApiClient([
            'base_url' => config('martingalian.api.url.bybit.stream'),
            'api_key' => $credentials->get('bybit_api_key'),
            'api_secret' => $credentials->get('bybit_api_secret'),
        ]);
    }

    public function markPrices(array $symbols, array $callbacks): void
    {
        // Subscribe to ticker stream for all provided symbols
        // Bybit ticker stream includes markPrice in the data
        $this->client->subscribeToStream($symbols, $callbacks);
    }

    public function userStream(array $callbacks): void
    {
        // Bybit user streams require different authentication than Binance
        // This would need API key-based authentication
        // For now, we'll use a placeholder implementation
        $this->client->subscribeToUserStream('', $callbacks);
    }

    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->client->getLoop();
    }
}
