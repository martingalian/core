<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;

/**
 * BaseWebsocketClient
 *
 * Abstract WebSocket client using Ratchet + ReactPHP.
 *
 * Features:
 * - Manages connection lifecycle and reconnections
 * - Supports auto-ping/pong for keepalive
 * - Implements exponential backoff reconnect logic (max 5 attempts)
 * - User-defined callbacks: message, ping, pong, close, error
 *
 * Recovery strategy:
 * - Internal: Exponential backoff reconnect (1s → 2s → 4s → 8s → 16s)
 * - External: Supervisor restarts process after max attempts exhausted
 * - Notifications: Handled by command-level error callbacks (e.g., websocket_error canonical)
 */
abstract class BaseWebsocketClient
{
    protected string $baseURL;

    protected ?Connector $wsConnector;

    protected ?WebSocket $wsConnection = null;

    protected LoopInterface $loop;

    protected int $reconnectAttempt = 0;

    protected int $maxReconnectAttempts = 5;

    protected string $exchangeName = 'Exchange';

    /**
     * Timestamp of the last message received (microtime).
     * Used to detect stale connections that stay open but stop sending data.
     */
    protected float $lastMessageAt = 0;

    /**
     * Seconds of silence before considering the connection stale and forcing reconnect.
     * Default 60s is conservative. Child classes can override for exchanges with different intervals.
     */
    protected int $staleThresholdSeconds = 60;

    public function __construct(array $args = [])
    {
        $this->baseURL = $args['baseURL'] ?? '';
        $this->loop = LoopFactory::create();
        $this->wsConnector = new Connector($this->loop);
    }

    final public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    final public function ping(): void
    {
        if ($this->wsConnection) {
            $this->wsConnection->send(new Frame('', true, Frame::OP_PING));
        } else {
            error_log("[{$this->exchangeName}] Ping attempted but WebSocket is not connected.");
        }
    }

    protected function handleCallback(string $url, array $callback): void
    {
        error_log("[{$this->exchangeName}] handleCallback() - URL: {$url}");
        $this->attemptConnection($url, $callback);
        error_log("[{$this->exchangeName}] Starting event loop...");
        $this->loop->run();
        error_log("[{$this->exchangeName}] Event loop ended");
    }

    protected function attemptConnection(string $url, array $callback): void
    {
        error_log("[{$this->exchangeName}] attemptConnection() - Creating WebSocket connection...");
        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                error_log("[{$this->exchangeName}] WebSocket connection established!");
                $this->wsConnection = $conn;

                if ($this->reconnectAttempt > 0) {
                    error_log("[{$this->exchangeName}] WebSocket reconnected successfully after {$this->reconnectAttempt} attempt(s)");
                }

                $this->reconnectAttempt = 0;
                $this->setupConnectionHandlers($conn, $callback, $url);
            },
            function ($e) use ($url, $callback) {
                error_log("[{$this->exchangeName}] WebSocket connection failed: {$e->getMessage()}");

                if (isset($callback['error']) && is_callable($callback['error'])) {
                    $callback['error'](null, $e);
                }

                $this->reconnect($url, $callback);
            }
        );
    }

    protected function setupConnectionHandlers(WebSocket $conn, array $callback, string $url): void
    {
        // Allow child classes to perform custom setup immediately after connection
        $this->onConnectionEstablished($conn, $callback);

        // Respond to server PING with PONG
        $conn->on('ping', function () use ($conn) {
            $conn->send(new Frame('', true, Frame::OP_PONG));
        });

        // Periodic keepalive PONG every 15 minutes
        $this->loop->addPeriodicTimer(900, function () use ($conn) {
            if ($this->wsConnection !== null) {
                $conn->send(new Frame('', true, Frame::OP_PONG));
            }
        });

        // Initialize last message timestamp and check for stale connections every 15 seconds
        $this->lastMessageAt = microtime(true);
        $this->loop->addPeriodicTimer(15, function () use ($conn) {
            if ($this->lastMessageAt > 0 && $this->wsConnection !== null) {
                $silentSeconds = (int) (microtime(true) - $this->lastMessageAt);
                if ($silentSeconds > $this->staleThresholdSeconds) {
                    error_log("[{$this->exchangeName}] No messages received for {$silentSeconds}s (threshold: {$this->staleThresholdSeconds}s). Forcing reconnect.");
                    $conn->close();
                }
            }
        });

        // Handle incoming messages
        $conn->on('message', function ($msg) use ($conn, $callback) {
            $this->lastMessageAt = microtime(true);
            $payload = (string) $msg;

            if (is_callable($callback['message'] ?? null)) {
                $callback['message']($conn, $payload);
            }

            // Handle JSON ping-pong (e.g., {"ping": timestamp})
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && isset($decoded['ping'])) {
                $conn->send(json_encode(['pong' => $decoded['ping']]));

                if (is_callable($callback['pong'] ?? null)) {
                    $callback['pong']($conn, $payload);
                }
            }
        });

        // Optional ping handler override
        if (isset($callback['ping']) && is_callable($callback['ping'])) {
            $conn->on('ping', function ($msg) use ($conn, $callback) {
                $callback['ping']($conn, $msg);
            });
        }

        // Handle disconnections
        $conn->on('close', function ($code = null, $reason = null) use ($conn, $url, $callback) {
            $this->wsConnection = null;

            $closeInfo = $code !== null ? " (code: {$code})" : '';
            $closeInfo .= ($reason !== null && $reason !== '') ? " - {$reason}" : '';
            error_log("[{$this->exchangeName}] WebSocket closed{$closeInfo}");

            if (isset($callback['close']) && is_callable($callback['close'])) {
                $callback['close']($conn);
            }

            $this->reconnect($url, $callback);
        });
    }

    /**
     * Hook for child classes to perform custom logic immediately after connection.
     * Called before event handlers are set up, allowing initial subscriptions.
     */
    protected function onConnectionEstablished(WebSocket $conn, array $callback): void
    {
        // Default: no-op. Child classes can override.
    }

    protected function reconnect(string $url, array $callback): void
    {
        $this->reconnectAttempt++;

        if ($this->reconnectAttempt > $this->maxReconnectAttempts) {
            error_log("[{$this->exchangeName}] Max reconnect attempts ({$this->maxReconnectAttempts}) reached. Stopping loop for supervisor restart.");

            if (isset($callback['error']) && is_callable($callback['error'])) {
                $callback['error'](null, new Exception('Max reconnect attempts reached. Supervisor will restart process.'));
            }

            $this->loop->stop();

            return;
        }

        $delay = pow(2, $this->reconnectAttempt - 1);
        error_log("[{$this->exchangeName}] Reconnecting in {$delay}s (attempt {$this->reconnectAttempt}/{$this->maxReconnectAttempts})...");

        $this->loop->addTimer($delay, function () use ($url, $callback) {
            $this->attemptConnection($url, $callback);
        });
    }

    private function createWSConnection(string $url)
    {
        return ($this->wsConnector)($url);
    }
}
