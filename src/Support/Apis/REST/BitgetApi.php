<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\REST;

use Martingalian\Core\Support\ApiClients\REST\BitgetApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

/**
 * BitgetApi
 *
 * High-level API class for BitGet Futures V2 endpoints.
 * Read-only methods for balance, positions, and open orders.
 *
 * @see https://www.bitget.com/api-doc/contract/intro
 */
final class BitgetApi
{
    // The REST api client.
    private BitgetApiClient $client;

    // Initializes BitGet API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BitgetApiClient([
            'url' => config('martingalian.api.url.bitget.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('bitget_api_key'),
            'api_secret' => $credentials->get('bitget_api_secret'),
            'passphrase' => $credentials->get('bitget_passphrase'),
        ]);
    }

    /**
     * Get server time.
     *
     * @see https://www.bitget.com/api-doc/common/public/Get-Server-Time
     */
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/public/time'
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get tradeable contracts (exchange information).
     *
     * @see https://www.bitget.com/api-doc/contract/market/Get-All-Symbols-Contracts
     */
    public function getExchangeInformation(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/market/contracts',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    /**
     * Get open positions.
     *
     * @see https://www.bitget.com/api-doc/contract/position/Get-All-Position
     */
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/position/all-position',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account information (balance).
     *
     * @see https://www.bitget.com/api-doc/contract/account/Get-Account-List
     */
    public function getAccountBalance(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/account/accounts',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get current open orders.
     *
     * @see https://www.bitget.com/api-doc/contract/trade/Get-Orders-Pending
     */
    public function getCurrentOpenOrders(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        // Default to USDT-FUTURES for perpetuals
        if (! $properties->has('options.productType')) {
            $properties->set('options.productType', 'USDT-FUTURES');
        }

        $apiRequest = ApiRequest::make(
            'GET',
            '/api/v2/mix/order/orders-pending',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Get account information (alias for getAccountBalance).
     */
    public function account(?ApiProperties $properties = null)
    {
        return $this->getAccountBalance($properties);
    }
}
