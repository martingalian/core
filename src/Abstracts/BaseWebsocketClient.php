<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\NotificationThrottler;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;

/*
 * BaseWebsocketClient
 *
 * • Abstract WebSocket client using Ratchet + ReactPHP.
 * • Manages connection lifecycle, reconnections, and event callbacks.
 * • Supports auto-ping/pong and ping-responses to external "ping" messages.
 * • Notifies admins via Pushover on connection failures and warnings.
 * • Allows user-defined callbacks: message, ping, pong, close, error.
 * • Implements exponential backoff reconnect logic (max 5 attempts).
 * • Intended for real-time data feeds (e.g., Binance WebSocket).
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

    public function __construct(array $args = [])
    {
        // Initialize core connection data and event loop.
        $this->baseURL = $args['baseURL'] ?? '';
        $this->loop = LoopFactory::create();
        $this->wsConnector = new Connector($this->loop);
    }

    final public function ping(): void
    {
        // Manual ping trigger for external monitoring.
        if ($this->wsConnection) {
            $this->wsConnection->send(new Frame('', true, Frame::OP_PING));
        } else {
            NotificationThrottler::using(NotificationService::class)
                ->withCanonical('websocket_error')
                ->execute(function () {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: 'Ping attempted but WebSocket is not connected.',
                        title: "{$this->exchangeName} WebSocket Warning",
                        deliveryGroup: 'exceptions'
                    );
                });
        }
    }

    protected function handleCallback(string $url, array $callback): void
    {
        $this->attemptConnection($url, $callback);

        // Run the ReactPHP loop.
        $this->loop->run();
    }

    protected function attemptConnection(string $url, array $callback): void
    {
        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                $this->wsConnection = $conn;
                $wasReconnecting = $this->reconnectAttempt > 0;
                $this->reconnectAttempt = 0;

                if ($wasReconnecting) {
                    NotificationThrottler::using(NotificationService::class)
                        ->withCanonical('websocket_reconnected')
                        ->execute(function () {
                            NotificationService::send(
                                user: Martingalian::admin(),
                                message: "WebSocket successfully reconnected to {$url}",
                                title: "{$this->exchangeName} WebSocket Reconnected",
                                deliveryGroup: 'default'
                            );
                        });
                }

                $this->setupConnectionHandlers($conn, $callback, $url);
            },
            function ($e) use ($url, $callback) {
                /*
                 * Handle failure to connect and notify admins.
                 * Trigger custom error handler if provided.
                 */
                NotificationThrottler::using(NotificationService::class)
                    ->withCanonical('websocket_connection_failed')
                    ->execute(function () {
                        NotificationService::send(
                            user: Martingalian::admin(),
                            message: "Could not connect to {$url}: {$e->getMessage()}",
                            title: "{$this->exchangeName} WebSocket Error",
                            deliveryGroup: 'exceptions'
                        );
                    });

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

        /*
         * Send pong automatically when ping is received.
         * Also add periodic keepalive pong every 15 minutes.
         */
        $conn->on('ping', function () use ($conn) {
            $conn->send(new Frame('', true, Frame::OP_PING));
        });

        $this->loop->addPeriodicTimer(900, function () use ($conn) {
            if ($this->wsConnection !== null) {
                $conn->send(new Frame('', true, Frame::OP_PONG));
            }
        });

        /*
         * Handle incoming messages:
         * - Raw payload is passed to user callback.
         * - Handles client-side ping-pong if structured as {"ping": ...}.
         */
        $conn->on('message', function ($msg) use ($conn, $callback) {
            $payload = (string) $msg;

            if (is_callable($callback['message'] ?? null)) {
                $callback['message']($conn, $payload);
            }

            $decoded = json_decode($payload, true);
            if (is_array($decoded) && isset($decoded['ping']) && is_callable($callback['pong'] ?? null)) {
                $conn->send(json_encode(['pong' => $decoded['ping']]));
            }
        });

        // Optional ping handler override.
        if (isset($callback['ping']) && is_callable($callback['ping'])) {
            $conn->on('ping', fn ($msg) => $callback['ping']($conn, $msg));
        }

        // Handle disconnections and initiate reconnect with exponential delay.
        $conn->on('close', function ($code = null, $reason = null) use ($conn, $url, $callback) {
            $this->wsConnection = null;

            // Build detailed close message
            $closeMessage = 'WebSocket connection closed';
            if ($code !== null) {
                $closeMessage .= " (code: {$code})";
            }
            if ($reason !== null && $reason !== '') {
                $closeMessage .= " - Reason: {$reason}";
            }

            // Send notification with close details
            NotificationThrottler::using(NotificationService::class)
                ->withCanonical('websocket_closed_with_details')
                ->execute(function () {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $closeMessage,
                        title: "{$this->exchangeName} WebSocket Closed",
                        deliveryGroup: 'default'
                    );
                });

            if (isset($callback['close']) && is_callable($callback['close'])) {
                $callback['close']($conn);
            }

            $this->reconnect($url, $callback);
        });
    }

    /**
     * Hook for child classes to perform custom logic immediately after connection is established.
     * This is called before any event handlers are set up, allowing child classes to send
     * initial messages like subscriptions.
     */
    protected function onConnectionEstablished(WebSocket $conn, array $callback): void
    {
        // Default implementation does nothing - child classes can override
    }

    protected function reconnect(string $url, array $callback): void
    {
        /*
         * Reconnect logic with exponential backoff (2^attempt).
         * Stops trying after configured maximum attempts.
         */
        $this->reconnectAttempt++;

        if ($this->reconnectAttempt > $this->maxReconnectAttempts) {
            $maxAttempts = $this->maxReconnectAttempts;
            $exchangeName = $this->exchangeName;
            NotificationThrottler::using(NotificationService::class)
                ->withCanonical('websocket_max_reconnect_attempts_reached')
                ->execute(function () use ($maxAttempts, $url, $exchangeName) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "Max reconnect attempts ({$maxAttempts}) reached for WebSocket: {$url}",
                        title: "{$exchangeName} WebSocket Failure",
                        canonical: 'websocket_max_reconnect_attempts_reached',
                        deliveryGroup: 'exceptions'
                    );
                });

            if (isset($callback['error']) && is_callable($callback['error'])) {
                $callback['error'](null, new Exception('Max reconnect attempts reached. Connection closed.'));
            }

            $this->loop->stop();

            return;
        }

        $delay = pow(2, $this->reconnectAttempt - 1);

        NotificationThrottler::using(NotificationService::class)
            ->withCanonical('websocket_reconnect_attempt')
            ->execute(function () {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "WebSocket reconnecting to {$url} in {$delay} seconds (attempt {$this->reconnectAttempt}/{$this->maxReconnectAttempts})...",
                    title: "{$this->exchangeName} WebSocket Reconnecting",
                    deliveryGroup: 'exceptions'
                );
            });

        $this->loop->addTimer($delay, function () use ($url, $callback) {
            $this->attemptConnection($url, $callback);
        });
    }

    private function createWSConnection(string $url)
    {
        return ($this->wsConnector)($url);
    }
}
