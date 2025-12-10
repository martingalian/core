<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\Websocket;

use Martingalian\Core\Abstracts\BaseWebsocketClient;

/**
 * KucoinApiClient (WebSocket)
 *
 * WebSocket client for KuCoin Futures mark price streams.
 * Handles subscription to mark price updates via /contract/instrument topic.
 *
 * KuCoin Futures WebSocket:
 * - URL: Dynamic - obtained from REST API /api/v1/bullet-public
 * - Subscription: {"type": "subscribe", "topic": "/contract/instrument:{symbol}", "privateChannel": false}
 * - Response (mark price): {"topic": "/contract/instrument:XBTUSDTM", "subject": "mark.index.price", "data": {"markPrice": ...}}
 * - Ping: Send {"type": "ping", "id": "{timestamp}"} every pingInterval from token response
 *
 * Connection flow:
 * 1. Call REST API /api/v1/bullet-public to get WebSocket token and endpoint
 * 2. Connect to wss://{endpoint}?token={token}&connectId={uuid}
 * 3. Subscribe to instrument topics for mark price
 */
final class KucoinApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    protected array $subscriptionArgs = [];

    protected bool $subscriptionSent = false;

    protected ?string $connectId = null;

    protected int $pingInterval = 30000;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? '',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        $this->exchangeName = 'KuCoin';
        $this->connectId = $config['connect_id'] ?? $this->generateConnectId();
        $this->pingInterval = (int) ($config['ping_interval'] ?? 30000);

        // Reset message count every second to adhere to rate limit
        $this->loop->addPeriodicTimer($this->rateLimitInterval, function () {
            $this->messageCount = 0;
        });
    }

    public function subscribeToStream(array $symbols, array $callbacks): void
    {
        if ($this->messageCount >= 10) {
            return;
        }

        // KuCoin WebSocket URL should include token
        $url = $this->baseURL;
        $this->messageCount++;

        // Store symbols for subscription
        $this->subscriptionArgs = $symbols;

        // Use parent's handleCallback which properly manages the event loop
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        // KuCoin uses token-based authentication for private streams
        $url = $this->baseURL;
        $this->handleCallback($url, $callbacks);
    }

    protected function onConnectionEstablished(\Ratchet\Client\WebSocket $conn, array $callback): void
    {
        // IMMEDIATELY send subscriptions after connection (before event handlers are set up)
        if (! empty($this->subscriptionArgs)) {
            // KuCoin /contract/instrument topic for mark price updates
            // Format: /contract/instrument:{symbol1},{symbol2},...
            $symbols = implode(',', $this->subscriptionArgs);
            $subscriptionMessage = json_encode([
                'id' => $this->generateMessageId(),
                'type' => 'subscribe',
                'topic' => '/contract/instrument:'.$symbols,
                'privateChannel' => false,
                'response' => true,
            ]);
            $conn->send($subscriptionMessage);
        }

        // Send periodic ping based on pingInterval from token response
        // KuCoin requires ping to keep connection alive
        $pingIntervalSeconds = max(1, (int) ($this->pingInterval / 1000));
        $this->loop->addPeriodicTimer($pingIntervalSeconds, function () use ($conn) {
            $conn->send(json_encode([
                'id' => $this->generateMessageId(),
                'type' => 'ping',
            ]));
        });
    }

    /**
     * Generate a unique connection ID.
     */
    protected function generateConnectId(): string
    {
        return (string) hrtime(true);
    }

    /**
     * Generate a unique message ID for KuCoin protocol.
     */
    protected function generateMessageId(): string
    {
        return (string) hrtime(true);
    }
}
