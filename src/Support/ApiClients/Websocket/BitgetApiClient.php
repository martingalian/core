<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\Websocket;

use Martingalian\Core\Abstracts\BaseWebsocketClient;
use React\EventLoop\TimerInterface;

/**
 * BitgetApiClient (WebSocket)
 *
 * WebSocket client for BitGet Futures ticker streams.
 * Handles subscription to mark price updates.
 *
 * BitGet V2 Futures WebSocket:
 * - URL: wss://ws.bitget.com/v2/ws/public (public streams)
 * - Subscription: {"op": "subscribe", "args": [{"instType": "USDT-FUTURES", "channel": "ticker", "instId": "BTCUSDT"}]}
 * - Ping: Send "ping" string every 30 seconds to keep connection alive
 *
 * Connection flow (simpler than KuCoin - direct connect):
 * 1. Connect directly to wss://ws.bitget.com/v2/ws/public
 * 2. Subscribe to ticker channels
 * 3. Send ping every 30 seconds
 */
final class BitgetApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    protected array $subscriptionArgs = [];

    protected bool $subscriptionSent = false;

    protected int $pingInterval = 30;

    /**
     * Reference to the ping timer so we can cancel it on reconnection.
     * This prevents timer accumulation (zombie timers pinging closed connections).
     */
    protected ?TimerInterface $pingTimer = null;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? 'wss://ws.bitget.com/v2/ws/public',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        $this->exchangeName = 'BitGet';
        $this->pingInterval = (int) ($config['ping_interval'] ?? 30);

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

        // BitGet WebSocket URL is static (direct connect, no token needed)
        $url = $this->baseURL;
        $this->messageCount++;

        // Store symbols for subscription
        $this->subscriptionArgs = $symbols;

        // Use parent's handleCallback which properly manages the event loop
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        // BitGet private streams require authentication handshake
        // For now, we only support public streams
        $url = $this->baseURL;
        $this->handleCallback($url, $callbacks);
    }

    protected function onConnectionEstablished(\Ratchet\Client\WebSocket $conn, array $callback): void
    {
        // CRITICAL: Cancel any existing ping timer before creating a new one.
        // This prevents timer accumulation (zombie timers pinging closed connections).
        if ($this->pingTimer !== null) {
            $this->loop->cancelTimer($this->pingTimer);
            $this->pingTimer = null;
        }

        // IMMEDIATELY send subscriptions after connection
        if (! empty($this->subscriptionArgs)) {
            // BitGet allows multiple subscriptions in a single message
            // Format: {"op": "subscribe", "args": [{"instType": "USDT-FUTURES", "channel": "ticker", "instId": "SYMBOL"}]}
            $args = array_map(static function ($symbol) {
                return [
                    'instType' => 'USDT-FUTURES',
                    'channel' => 'ticker',
                    'instId' => $symbol,
                ];
            }, $this->subscriptionArgs);

            $subscriptionMessage = json_encode([
                'op' => 'subscribe',
                'args' => $args,
            ]);
            $conn->send($subscriptionMessage);
        }

        // Send immediate ping to establish keepalive, then periodic pings every 30 seconds.
        // BitGet disconnects after 120 seconds without a ping - sending immediately
        // ensures we don't hit the timeout boundary on the first cycle.
        $conn->send('ping');

        // Create periodic ping timer and store reference for cleanup on reconnection
        $this->pingTimer = $this->loop->addPeriodicTimer($this->pingInterval, function () use ($conn) {
            // Safety check: only send ping if this is still the active connection
            if ($this->wsConnection === $conn) {
                $conn->send('ping');
            }
        });
    }
}
