<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
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
            NotificationThrottler::sendToAdmin(
                messageCanonical: 'websocket_error',
                message: 'Ping attempted but WebSocket is not connected.',
                title: "{$this->exchangeName} WebSocket Warning",
                deliveryGroup: 'exceptions'
            );
        }
    }

    protected function handleCallback(string $url, array $callback): void
    {
        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                $this->wsConnection = $conn;
                $this->reconnectAttempt = 0;

                /*
                 * Send pong automatically when ping is received.
                 * Also add periodic keepalive pong every 15 minutes.
                 */
                $conn->on('ping', function () use ($conn) {
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                $this->loop->addPeriodicTimer(900, function () use ($conn) {
                    $conn->send(new Frame('', true, Frame::OP_PONG));
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
                $conn->on('close', function () use ($conn, $url, $callback) {
                    if (isset($callback['close']) && is_callable($callback['close'])) {
                        $callback['close']($conn);
                    }

                    $this->reconnect($url, $callback);
                });
            },
            function ($e) use ($url, $callback) {
                /*
                 * Handle failure to connect and notify admins.
                 * Trigger custom error handler if provided.
                 */
                NotificationThrottler::sendToAdmin(
                    messageCanonical: 'websocket_error_2',
                    message: "Could not connect to {$url}: {$e->getMessage()}",
                    title: "{$this->exchangeName} WebSocket Error",
                    deliveryGroup: 'exceptions'
                );

                if (isset($callback['error']) && is_callable($callback['error'])) {
                    $callback['error'](null, $e);
                }

                $this->reconnect($url, $callback);
            }
        );

        // Run the ReactPHP loop.
        $this->loop->run();
    }

    protected function reconnect(string $url, array $callback): void
    {
        /*
         * Reconnect logic with exponential backoff (2^attempt).
         * Stops trying after configured maximum attempts.
         */
        if ($this->reconnectAttempt >= $this->maxReconnectAttempts) {
            NotificationThrottler::sendToAdmin(
                messageCanonical: 'websocket_error_3',
                message: "Max reconnect attempts reached for WebSocket: {$url}",
                title: "{$this->exchangeName} WebSocket Failure",
                deliveryGroup: 'exceptions'
            );

            if (isset($callback['error']) && is_callable($callback['error'])) {
                $callback['error'](null, new Exception('Max reconnect attempts reached. Connection closed.'));
            }

            return;
        }

        $delay = pow(2, $this->reconnectAttempt);

        if ($this->reconnectAttempt > 0) {
            NotificationThrottler::sendToAdmin(
                messageCanonical: 'websocket_error_4',
                message: "WebSocket reconnecting to {$url} in {$delay} seconds (attempt {$this->reconnectAttempt})...",
                title: "{$this->exchangeName} WebSocket Reconnect",
                deliveryGroup: 'exceptions'
            );
        }
        $this->loop->addTimer($delay, function () use ($url, $callback) {
            $this->reconnectAttempt++;
            $this->handleCallback($url, $callback);
        });
    }

    private function createWSConnection(string $url)
    {
        return ($this->wsConnector)($url);
    }
}
