<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\Websocket;

use Martingalian\Core\Support\ApiClients\Websocket\BitgetApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;

/**
 * BitgetApi (WebSocket)
 *
 * High-level WebSocket API for BitGet Futures.
 * Provides methods for subscribing to mark price updates.
 *
 * BitGet V2 WebSocket is simpler than KuCoin - direct connect without token:
 * 1. Connect directly to wss://ws.bitget.com/v2/ws/public
 * 2. Subscribe to ticker channels
 * 3. Send ping every 30 seconds
 */
final class BitgetApi
{
    private ?BitgetApiClient $client = null;

    private ApiCredentials $credentials;

    public function __construct(ApiCredentials $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * Subscribe to mark price updates for the given symbols.
     *
     * @param  array  $symbols  Array of symbol names (e.g., ['BTCUSDT', 'ETHUSDT'])
     * @param  array  $callbacks  Callbacks for message, ping, pong, close, error events
     */
    public function markPrices(array $symbols, array $callbacks): void
    {
        // Initialize client (BitGet uses direct connect - no token needed)
        $this->initializeClient();

        // Subscribe to ticker stream for all provided symbols
        // BitGet ticker stream includes markPrice in the data
        $this->client->subscribeToStream($symbols, $callbacks);
    }

    /**
     * Subscribe to user stream for account updates.
     *
     * @param  array  $callbacks  Callbacks for message, ping, pong, close, error events
     */
    public function userStream(array $callbacks): void
    {
        // Initialize client
        $this->initializeClient();

        // BitGet user streams require authentication handshake
        $this->client->subscribeToUserStream('', $callbacks);
    }

    /**
     * Get the event loop for adding periodic timers.
     */
    public function getLoop(): \React\EventLoop\LoopInterface
    {
        // Ensure client is initialized
        if ($this->client === null) {
            $this->initializeClient();
        }

        return $this->client->getLoop();
    }

    /**
     * Initialize the WebSocket client.
     * BitGet uses direct connect - no token fetching required.
     */
    private function initializeClient(): void
    {
        if ($this->client !== null) {
            return;
        }

        // BitGet WebSocket URL is static - no need to fetch token
        $wsUrl = config('martingalian.api.url.bitget.stream', 'wss://ws.bitget.com/v2/ws/public');

        $this->client = new BitgetApiClient([
            'base_url' => $wsUrl,
            'api_key' => $this->credentials->get('bitget_api_key'),
            'api_secret' => $this->credentials->get('bitget_api_secret'),
            'passphrase' => $this->credentials->get('bitget_passphrase'),
            'ping_interval' => 30, // BitGet requires ping every 30 seconds
        ]);
    }
}
