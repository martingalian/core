<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\Websocket;

use App\Support\NotificationService;
use App\Support\Throttler;
use Martingalian\Core\Abstracts\BaseWebsocketClient;

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

    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->loop;
    }

    protected function onConnectionEstablished(\Ratchet\Client\WebSocket $conn, array $callback): void
    {
        // IMMEDIATELY send subscription after connection (before event handlers are set up)
        if (! empty($this->subscriptionArgs)) {
            $subscriptionMessage = json_encode([
                'op' => 'subscribe',
                'args' => $this->subscriptionArgs,
            ]);
            $conn->send($subscriptionMessage);
        }

        // Send periodic ping every 20 seconds as per Bybit requirements
        $this->loop->addPeriodicTimer(20, function () use ($conn) {
            if ($conn->isConnected()) {
                $conn->send(json_encode(['op' => 'ping']));
            }
        });

        // Add a message handler specifically for checking subscription failures
        $conn->on('message', function ($msg) {
            $payload = (string) $msg;
            $decoded = json_decode($payload, true);

            if (is_array($decoded) && isset($decoded['op']) && $decoded['op'] === 'subscribe') {
                if (isset($decoded['success']) && $decoded['success'] === false) {
                    Throttler::using(NotificationService::class)
                        ->withCanonical('bybit_subscription_failed')
                        ->execute(function () {
                            NotificationService::sendToAdmin(
                                message: 'Bybit subscription failed: '.json_encode($decoded),
                                title: 'Bybit WebSocket Subscription Error',
                                deliveryGroup: 'exceptions'
                            );
                        });
                }
            }
        });
    }
}
