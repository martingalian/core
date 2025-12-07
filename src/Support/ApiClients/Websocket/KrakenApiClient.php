<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\Websocket;

use Martingalian\Core\Abstracts\BaseWebsocketClient;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\NotificationThrottler;

/**
 * KrakenApiClient (WebSocket)
 *
 * WebSocket client for Kraken Futures ticker streams.
 * Handles subscription to mark price updates.
 *
 * Kraken Futures WebSocket:
 * - URL: wss://futures.kraken.com/ws/v1
 * - Subscription: {"event": "subscribe", "feed": "ticker", "product_ids": [...]}
 * - Ping: Send periodic ping to keep connection alive
 */
final class KrakenApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    protected array $subscriptionArgs = [];

    protected bool $subscriptionSent = false;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? 'wss://futures.kraken.com',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        $this->exchangeName = 'Kraken';

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

        // Kraken Futures WebSocket URL
        $url = $this->baseURL.'/ws/v1';
        $this->messageCount++;

        // Store symbols for subscription (Kraken uses product_ids)
        $this->subscriptionArgs = $symbols;

        // Use parent's handleCallback which properly manages the event loop
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        // Kraken uses API key authentication for private streams
        $url = $this->baseURL.'/ws/v1';
        $this->handleCallback($url, $callbacks);
    }

    protected function onConnectionEstablished(\Ratchet\Client\WebSocket $conn, array $callback): void
    {
        // IMMEDIATELY send subscription after connection (before event handlers are set up)
        if (! empty($this->subscriptionArgs)) {
            $subscriptionMessage = json_encode([
                'event' => 'subscribe',
                'feed' => 'ticker',
                'product_ids' => $this->subscriptionArgs,
            ]);
            $conn->send($subscriptionMessage);
        }

        // Send periodic ping every 30 seconds as per Kraken requirements
        $this->loop->addPeriodicTimer(30, function () use ($conn) {
            $conn->send(json_encode(['event' => 'ping']));
        });

        // Add a message handler specifically for checking subscription failures
        $conn->on('message', function ($msg) {
            $payload = (string) $msg;
            $decoded = json_decode($payload, true);

            if (is_array($decoded) && isset($decoded['event'])) {
                // Handle subscription error
                if ($decoded['event'] === 'error') {
                    NotificationThrottler::using(NotificationService::class)
                        ->withCanonical('kraken_subscription_failed')
                        ->execute(function () use ($decoded) {
                            NotificationService::send(
                                user: Martingalian::admin(),
                                message: 'Kraken subscription failed: '.json_encode($decoded),
                                title: 'Kraken WebSocket Subscription Error',
                                deliveryGroup: 'exceptions'
                            );
                        });
                }
            }
        });
    }
}
