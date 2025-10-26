<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\Websocket;

use Martingalian\Core\Abstracts\BaseWebsocketClient;
use Martingalian\Core\Support\Martingalian;

final class BybitApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    protected array $subscriptionArgs = [];

    protected bool $subscriptionSent = false;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? 'wss://stream.bybit.com',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        $this->exchangeName = 'Bybit';

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

        // Override handleCallback to inject subscription logic
        $this->handleCallbackWithSubscription($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        // Bybit uses API key authentication for private streams
        // User streams require different handling than Binance
        $url = $this->baseURL.'/v5/private';
        $this->handleCallback($url, $callbacks);
    }

    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->loop;
    }

    public function handleCallbackWithSubscription(string $url, array $callback): void
    {
        $subscriptionArgs = $this->subscriptionArgs;
        $loop = $this->loop;
        $connector = $this->wsConnector;

        $connector($url)->then(
            function ($conn) use ($callback, $url, $subscriptionArgs, $loop) {
                $this->wsConnection = $conn;
                $this->reconnectAttempt = 0;

                // IMMEDIATELY send subscription after connection
                $subscriptionMessage = json_encode([
                    'op' => 'subscribe',
                    'args' => $subscriptionArgs,
                ]);
                $conn->send($subscriptionMessage);

                // Send periodic ping every 20 seconds as per Bybit requirements
                $loop->addPeriodicTimer(20, function () use ($conn) {
                    $conn->send(json_encode(['op' => 'ping']));
                });

                // Handle incoming messages
                $conn->on('message', function ($msg) use ($conn, $callback) {
                    $payload = (string) $msg;

                    // Check for subscription failures
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded) && isset($decoded['op']) && $decoded['op'] === 'subscribe') {
                        if (isset($decoded['success']) && $decoded['success'] === false) {
                            Martingalian::notifyAdmins(
                                message: 'Bybit subscription failed: '.json_encode($decoded),
                                title: 'Bybit WebSocket Subscription Error',
                                deliveryGroup: 'exceptions'
                            );
                        }
                    }

                    // Pass to user callback
                    if (is_callable($callback['message'] ?? null)) {
                        $callback['message']($conn, $payload);
                    }

                    // Handle pong responses
                    if (is_array($decoded) && isset($decoded['op']) && $decoded['op'] === 'pong') {
                        if (is_callable($callback['pong'] ?? null)) {
                            $callback['pong']($conn, $payload);
                        }
                    }
                });

                // Optional ping handler override
                if (isset($callback['ping']) && is_callable($callback['ping'])) {
                    $conn->on('ping', fn ($msg) => $callback['ping']($conn, $msg));
                }

                // Handle disconnections and initiate reconnect
                $conn->on('close', function () use ($conn, $url, $callback) {
                    if (isset($callback['close']) && is_callable($callback['close'])) {
                        $callback['close']($conn);
                    }

                    $this->reconnect($url, $callback);
                });
            },
            function ($e) use ($url, $callback) {
                // Handle connection failure
                Martingalian::notifyAdmins(
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

        // Run the ReactPHP loop
        $loop->run();
    }
}
