<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Support\ApiClients\REST\KrakenApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

/**
 * KrakenApi
 *
 * High-level API class for Kraken Futures endpoints.
 * Read-only methods for balance, positions, and open orders.
 *
 * @see https://docs.kraken.com/api/docs/guides/futures-rest/
 */
final class KrakenApi
{
    // The REST api client.
    private KrakenApiClient $client;

    // Initializes Kraken API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new KrakenApiClient([
            'url' => config('martingalian.api.url.kraken.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('kraken_api_key'),

            // All ApiCredentials keys need to arrive encrypted.
            'private_key' => $credentials->get('kraken_private_key'),
        ]);
    }

    /**
     * Get server time.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-server-time
     */
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/derivatives/api/v3/time'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get tradeable instruments (exchange information).
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-instruments
     */
    public function getExchangeInformation(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/derivatives/api/v3/instruments',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get open positions.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-openpositions
     */
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/derivatives/api/v3/openpositions',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account balances (wallet overview).
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-wallets
     */
    public function getAccountBalance(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/derivatives/api/v3/accounts',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get current open orders.
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-openorders
     */
    public function getCurrentOpenOrders(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/derivatives/api/v3/openorders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account information (full account details).
     *
     * @see https://docs.kraken.com/api/docs/futures-api/trading/get-wallets
     */
    public function account(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/derivatives/api/v3/accounts',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }
}
