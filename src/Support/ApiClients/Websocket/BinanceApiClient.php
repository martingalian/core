<?php

namespace Martingalian\Core\Support\ApiClients\Websocket;

use Martingalian\Core\Abstracts\BaseWebsocketClient;

class BinanceApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    public function __construct(array $config)
    {
        $args = [
            'baseURL' => $config['base_url'] ?? 'wss://fstream.binance.com',
            'wsConnector' => $config['ws_connector'] ?? null,
        ];

        parent::__construct($args);

        // Reset message count every second to adhere to rate limit
        $this->loop->addPeriodicTimer($this->rateLimitInterval, function () {
            $this->messageCount = 0;
        });
    }

    public function subscribeToStream(string $streamName, array $callbacks): void
    {
        if ($this->messageCount >= 10) {
            return;
        }

        $url = $this->baseURL."/ws/{$streamName}";
        $this->messageCount++;
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        $url = $this->baseURL."/ws/{$listenKey}";
        $this->handleCallback($url, $callbacks);
    }

    public function getLoop(): \React\EventLoop\LoopInterface
    {
        return $this->loop;
    }
}
