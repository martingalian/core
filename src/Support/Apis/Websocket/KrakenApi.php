<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\Websocket;

use Martingalian\Core\Support\ApiClients\Websocket\KrakenApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;

/**
 * KrakenApi (WebSocket)
 *
 * High-level WebSocket API for Kraken Futures.
 * Provides methods for subscribing to mark price updates.
 */
final class KrakenApi
{
    private KrakenApiClient $client;

    private ApiCredentials $credentials;

    public function __construct(ApiCredentials $credentials)
    {
        $this->credentials = $credentials;

        $this->client = new KrakenApiClient([
            'base_url' => config('martingalian.api.url.kraken.stream'),
            'api_key' => $credentials->get('kraken_api_key'),
            'private_key' => $credentials->get('kraken_private_key'),
        ]);
    }

    /**
     * Subscribe to mark price updates for the given symbols.
     *
     * @param  array  $symbols  Array of symbol names (e.g., ['PF_XBTUSD', 'PF_ETHUSD'])
     * @param  array  $callbacks  Callbacks for message, ping, pong, close, error events
     */
    public function markPrices(array $symbols, array $callbacks): void
    {
        // Subscribe to ticker stream for all provided symbols
        // Kraken ticker stream includes markPrice in the data
        $this->client->subscribeToStream($symbols, $callbacks);
    }

    /**
     * Subscribe to user stream for account updates.
     *
     * @param  array  $callbacks  Callbacks for message, ping, pong, close, error events
     */
    public function userStream(array $callbacks): void
    {
        // Kraken user streams require API key authentication
        $this->client->subscribeToUserStream('', $callbacks);
    }

    /**
     * Get the event loop for adding periodic timers.
     */
    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->client->getLoop();
    }
}
