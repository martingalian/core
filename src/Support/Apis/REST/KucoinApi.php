<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Support\ApiClients\REST\KucoinApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

/**
 * KucoinApi
 *
 * High-level API class for KuCoin Futures endpoints.
 * Read-only methods for balance, positions, and open orders.
 *
 * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-open-contract-list
 */
final class KucoinApi
{
    // The REST api client.
    private KucoinApiClient $client;

    // Initializes KuCoin API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new KucoinApiClient([
            'url' => config('martingalian.api.url.kucoin.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('kucoin_api_key'),
            'api_secret' => $credentials->get('kucoin_api_secret'),
            'passphrase' => $credentials->get('kucoin_passphrase'),
        ]);
    }

    /**
     * Get server time.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-server-time
     */
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/timestamp'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get tradeable contracts (exchange information).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/market-data/get-open-contract-list
     */
    public function getExchangeInformation(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/contracts/active',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get open positions.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/positions/get-position-list
     */
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/positions',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account overview (balance information).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/account/get-account-overview
     */
    public function getAccountBalance(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        // KuCoin requires currency parameter (default USDT)
        if (! $properties->has('options.currency')) {
            $properties->set('options.currency', 'USDT');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/account-overview',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get current open orders.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/orders/get-order-list
     */
    public function getCurrentOpenOrders(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        // Filter for active orders only
        $properties->set('options.status', 'active');

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v1/orders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account information (alias for getAccountBalance).
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/account/get-account-overview
     */
    public function account(?ApiProperties $properties = null)
    {
        return $this->getAccountBalance($properties);
    }

    /**
     * Get WebSocket token for public channels.
     * Required before connecting to WebSocket - KuCoin uses dynamic endpoints.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/websocket/apply-connect-token
     */
    public function getPublicWebSocketToken()
    {
        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v1/bullet-public'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get WebSocket token for private channels.
     * Required before connecting to authenticated WebSocket streams.
     *
     * @see https://www.kucoin.com/docs/rest/futures-trading/websocket/apply-connect-token
     */
    public function getPrivateWebSocketToken()
    {
        $apiRequest = ApiRequest::make(
            'POST',
            '/api/v1/bullet-private'
        );

        return $this->client->signRequest($apiRequest);
    }
}
