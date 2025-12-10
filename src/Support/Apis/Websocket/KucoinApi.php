<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\Websocket;

use Martingalian\Core\Support\ApiClients\Websocket\KucoinApiClient;
use Martingalian\Core\Support\Apis\REST\KucoinApi as KucoinRestApi;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;

/**
 * KucoinApi (WebSocket)
 *
 * High-level WebSocket API for KuCoin Futures.
 * Provides methods for subscribing to mark price updates.
 *
 * KuCoin requires fetching a WebSocket token first via REST API:
 * 1. POST /api/v1/bullet-public (or /api/v1/bullet-private for auth)
 * 2. Response contains: token, endpoint, pingInterval
 * 3. Connect to wss://{endpoint}?token={token}&connectId={uuid}
 */
final class KucoinApi
{
    private ?KucoinApiClient $client = null;

    private ApiCredentials $credentials;

    private ?array $tokenData = null;

    public function __construct(ApiCredentials $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * Subscribe to mark price updates for the given symbols.
     *
     * @param  array  $symbols  Array of symbol names (e.g., ['XBTUSDTM', 'ETHUSDTM'])
     * @param  array  $callbacks  Callbacks for message, ping, pong, close, error events
     */
    public function markPrices(array $symbols, array $callbacks): void
    {
        // Get WebSocket token and endpoint first
        $this->fetchWebSocketToken();

        if ($this->tokenData === null) {
            throw new \RuntimeException('Failed to fetch KuCoin WebSocket token');
        }

        // Initialize client with the dynamic WebSocket URL
        $this->initializeClient();

        // Subscribe to ticker stream for all provided symbols
        // KuCoin ticker stream includes markPrice in the data
        $this->client->subscribeToStream($symbols, $callbacks);
    }

    /**
     * Subscribe to user stream for account updates.
     *
     * @param  array  $callbacks  Callbacks for message, ping, pong, close, error events
     */
    public function userStream(array $callbacks): void
    {
        // Get private WebSocket token
        $this->fetchPrivateWebSocketToken();

        if ($this->tokenData === null) {
            throw new \RuntimeException('Failed to fetch KuCoin private WebSocket token');
        }

        // Initialize client with the dynamic WebSocket URL
        $this->initializeClient();

        // KuCoin user streams require token authentication
        $this->client->subscribeToUserStream('', $callbacks);
    }

    /**
     * Get the event loop for adding periodic timers.
     */
    public function getLoop(): \React\EventLoop\LoopInterface
    {
        // Ensure we have a token and client initialized
        if ($this->client === null) {
            $this->fetchWebSocketToken();
            $this->initializeClient();
        }

        return $this->client->getLoop();
    }

    /**
     * Fetch public WebSocket token from KuCoin REST API.
     */
    protected function fetchWebSocketToken(): void
    {
        $restApi = new KucoinRestApi($this->credentials);
        $response = $restApi->getPublicWebSocketToken();

        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['data']['token'], $data['data']['instanceServers'][0])) {
            $server = $data['data']['instanceServers'][0];
            $this->tokenData = [
                'token' => $data['data']['token'],
                'endpoint' => $server['endpoint'] ?? '',
                'pingInterval' => $server['pingInterval'] ?? 30000,
                'pingTimeout' => $server['pingTimeout'] ?? 10000,
            ];
        }
    }

    /**
     * Fetch private WebSocket token from KuCoin REST API.
     */
    protected function fetchPrivateWebSocketToken(): void
    {
        $restApi = new KucoinRestApi($this->credentials);
        $response = $restApi->getPrivateWebSocketToken();

        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['data']['token'], $data['data']['instanceServers'][0])) {
            $server = $data['data']['instanceServers'][0];
            $this->tokenData = [
                'token' => $data['data']['token'],
                'endpoint' => $server['endpoint'] ?? '',
                'pingInterval' => $server['pingInterval'] ?? 30000,
                'pingTimeout' => $server['pingTimeout'] ?? 10000,
            ];
        }
    }

    /**
     * Initialize the WebSocket client with token data.
     */
    protected function initializeClient(): void
    {
        if ($this->tokenData === null) {
            throw new \RuntimeException('Token data not available');
        }

        // Build WebSocket URL with token
        $connectId = (string) hrtime(true);
        $wsUrl = $this->tokenData['endpoint'].'?token='.$this->tokenData['token'].'&connectId='.$connectId;

        $this->client = new KucoinApiClient([
            'base_url' => $wsUrl,
            'api_key' => $this->credentials->get('kucoin_api_key'),
            'api_secret' => $this->credentials->get('kucoin_api_secret'),
            'passphrase' => $this->credentials->get('kucoin_passphrase'),
            'connect_id' => $connectId,
            'ping_interval' => $this->tokenData['pingInterval'],
        ]);
    }
}
