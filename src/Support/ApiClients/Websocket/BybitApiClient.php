<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\Websocket;

use Martingalian\Core\Abstracts\BaseWebsocketClient;
use React\EventLoop\TimerInterface;

final class BybitApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    protected array $subscriptionArgs = [];

    protected bool $subscriptionSent = false;

    /**
     * Reference to the ping timer so we can cancel it on reconnection.
     * This prevents timer accumulation (zombie timers pinging closed connections).
     */
    protected ?TimerInterface $pingTimer = null;

    /**
     * Connection ID for logging purposes (increments on each connection).
     */
    protected int $connectionId = 0;

    /**
     * Ping counter for this connection (resets on reconnection).
     */
    protected int $pingCount = 0;

    /**
     * Pong counter for this connection (resets on reconnection).
     */
    protected int $pongCount = 0;

    /**
     * Last pong received timestamp.
     */
    protected float $lastPongAt = 0;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? 'wss://stream.bybit.com',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        $this->exchangeName = 'Bybit';

        log_on('bybit-websocket.log', '=== BYBIT CLIENT INITIALIZED ===');
        log_on('bybit-websocket.log', 'PID: '.getmypid().' | Base URL: '.$this->baseURL);

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

        // Bybit requires subscription via WebSocket messages, not URL parameters
        // Connect to the base URL for linear contracts
        $url = $this->baseURL.'/v5/public/linear';
        $this->messageCount++;

        // Build subscription args for all symbols
        $this->subscriptionArgs = [];
        foreach ($symbols as $symbol) {
            $this->subscriptionArgs[] = "tickers.{$symbol}";
        }

        // Use parent's handleCallback which properly manages the event loop
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        // Bybit uses API key authentication for private streams
        // User streams require different handling than Binance
        $url = $this->baseURL.'/v5/private';
        $this->handleCallback($url, $callbacks);
    }

    protected function onConnectionEstablished(\Ratchet\Client\WebSocket $conn, array $callback): void
    {
        // Increment connection ID and reset counters for this new connection
        $this->connectionId++;
        $this->pingCount = 0;
        $this->pongCount = 0;
        $this->lastPongAt = 0;

        $connId = $this->connectionId;

        log_on('bybit-websocket.log', "[CONN#{$connId}] Connection established");

        // CRITICAL: Cancel any existing ping timer before creating a new one.
        // This prevents timer accumulation - zombie timers that keep running
        // and try to ping closed connections after reconnection.
        if ($this->pingTimer !== null) {
            $this->loop->cancelTimer($this->pingTimer);
            log_on('bybit-websocket.log', "[CONN#{$connId}] Cancelled previous ping timer (preventing accumulation)");
            $this->pingTimer = null;
        }

        // Send subscription after connection
        if (! empty($this->subscriptionArgs)) {
            $subscriptionMessage = json_encode([
                'op' => 'subscribe',
                'args' => $this->subscriptionArgs,
            ]);
            $conn->send($subscriptionMessage);
            log_on('bybit-websocket.log', "[CONN#{$connId}] Sent subscription for ".count($this->subscriptionArgs)." symbols");
        }

        // Send immediate ping to establish keepalive
        $conn->send(json_encode(['op' => 'ping']));
        $this->pingCount++;
        log_on('bybit-websocket.log', "[CONN#{$connId}] Sent initial ping (#{$this->pingCount})");

        // Create periodic ping timer every 20 seconds (Bybit spec: timeout after ~30s without ping)
        // Store the timer reference so we can cancel it on reconnection
        $this->pingTimer = $this->loop->addPeriodicTimer(20, function () use ($conn, $connId) {
            // Safety check: only send ping if this is still the active connection
            if ($this->wsConnection !== $conn) {
                log_on('bybit-websocket.log', "[CONN#{$connId}] TIMER ORPHAN DETECTED - timer running for closed connection, cancelling");
                if ($this->pingTimer !== null) {
                    $this->loop->cancelTimer($this->pingTimer);
                    $this->pingTimer = null;
                }

                return;
            }

            $this->pingCount++;
            $timeSinceLastPong = $this->lastPongAt > 0 ? round(microtime(true) - $this->lastPongAt, 1) : 'never';

            $conn->send(json_encode(['op' => 'ping']));
            log_on('bybit-websocket.log', "[CONN#{$connId}] Ping #{$this->pingCount} sent | Pongs received: {$this->pongCount} | Last pong: {$timeSinceLastPong}s ago");
        });

        log_on('bybit-websocket.log', "[CONN#{$connId}] Ping timer started (20s interval)");
    }

    /**
     * Track pong responses from Bybit.
     * Called from the message handler when we receive a pong.
     */
    public function recordPong(): void
    {
        $this->pongCount++;
        $this->lastPongAt = microtime(true);
    }

    /**
     * Get connection diagnostics for logging.
     *
     * @return array{connection_id: int, ping_count: int, pong_count: int, last_pong_ago: float|null}
     */
    public function getDiagnostics(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'ping_count' => $this->pingCount,
            'pong_count' => $this->pongCount,
            'last_pong_ago' => $this->lastPongAt > 0 ? round(microtime(true) - $this->lastPongAt, 1) : null,
        ];
    }
}
