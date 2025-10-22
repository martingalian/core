<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Apis\REST;

use Illuminate\Validation\Rule;
use Martingalian\Core\Concerns\HasPropertiesValidation;
use Martingalian\Core\Support\ApiClients\REST\BinanceApiClient;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiProperties;
use Martingalian\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class BinanceApi
{
    use HasPropertiesValidation;

    // The REST api client.
    protected $client;

    // Initializes CoinMarketCap API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BinanceApiClient([
            'url' => config('martingalian.api.url.binance.rest'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_key' => $credentials->get('binance_api_key'),

            // All ApiCredentials keys need to arrive encrypted.
            'api_secret' => $credentials->get('binance_api_secret'),
        ]);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Check-Server-Time
    public function serverTime()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/time'
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Notional-and-Leverage-Brackets
    public function getLeverageBrackets(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/leverageBracket',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Exchange-Information
    public function getExchangeInformation(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/exchangeInfo',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Current-All-Open-Orders
    public function getCurrentOpenOrders(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/openOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/All-Orders
    public function getAllOrders(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/allOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Query-Order
    public function getOrder(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Cancel-Order
    public function cancelOrder(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'DELETE',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Cancel-All-Open-Orders
    public function cancelAllOpenOrders(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'DELETE',
            '/fapi/v1/allOpenOrders',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    public function updateMarginType(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.margintype' => ['required', Rule::in(['ISOLATED', 'CROSSED'])],
        ]);

        $apiRequest = ApiRequest::make(
            'POST',
            '/fapi/v1/marginType',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Position-Information-V3
    public function getPositions(?ApiProperties $properties = null)
    {
        $properties = $properties ?? new ApiProperties;

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v3/positionRisk',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Futures-Account-Balance-V3
    public function getAccountBalance()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v3/balance'
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Change-Initial-Leverage
    public function changeInitialLeverage(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.leverage' => 'required|integer',
        ]);

        $apiRequest = ApiRequest::make(
            'POST',
            '/fapi/v1/leverage',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Mark-Price
    public function getMarkPrice(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/premiumIndex',
            $properties
        );

        return $this->client->publicRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api
    public function placeOrder(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.side' => 'required|string',
            'options.type' => 'required|string',
            'options.quantity' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'POST',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Query-Order
    public function orderQuery(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Modify-Order
    public function modifyOrder(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required',
            'options.quantity' => 'required',
            'options.price' => 'required',
        ]);

        $apiRequest = ApiRequest::make(
            'PUT',
            '/fapi/v1/order',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/trade/rest-api/Account-Trade-List
    public function accountTrades(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/userTrades',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Account-Information-V3
    public function account(ApiProperties $properties)
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v3/account',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Get-Income-History
    public function income(ApiProperties $properties)
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.incomeType' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make(
            'GET',
            '/fapi/v1/income',
            $properties
        );

        return $this->client->signRequest($apiRequest);
    }

    // https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/User-Data-Stream
    public function createListenKey(): string
    {
        $apiRequest = ApiRequest::make('POST', '/fapi/v1/listenKey');
        $response = $this->client->publicRequest($apiRequest);   // ← unsigned

        $body = $response instanceof ResponseInterface
          ? json_decode((string) $response->getBody(), true)
          : $response;                                         // array in tests

        return $body['listenKey'] ?? throw new RuntimeException('No listenKey returned');
    }

    public function refreshListenKey(?string $currentKey = null): string
    {
        $apiRequest = ApiRequest::make('POST', '/fapi/v1/listenKey');
        $response = $this->client->publicRequest($apiRequest);

        /** @var string $body */
        if ($response instanceof ResponseInterface) {
            $body = (string) $response->getBody();              // raw JSON (or empty)
        } elseif (is_array($response)) {                        // unit-test stub
            return $response['listenKey']
            ?? throw new RuntimeException('Stub missing listenKey.');
        } else {
            throw new RuntimeException('Unexpected response type: '.get_debug_type($response));
        }

        // empty body  ⇒ Binance kept the same key alive
        if ($body === '') {
            return $currentKey
            ?? throw new RuntimeException('Empty listenKey body and no current key supplied.');
        }

        $data = json_decode($body, true);

        // Binance error payload?
        if (isset($data['code'], $data['msg'])) {
            throw new RuntimeException("Binance error {$data['code']}: {$data['msg']}");
        }

        if (! isset($data['listenKey'])) {
            throw new RuntimeException("No listenKey in Binance response: {$body}");
        }

        return $data['listenKey'];
    }

    public function keepAliveListenKey(string $key): void
    {
        $apiRequest = ApiRequest::make('PUT', '/fapi/v1/listenKey', [
            'listenKey' => $key,
        ]);

        $this->client->publicRequest($apiRequest);     // response body is empty
    }

    public function closeListenKey()
    {
        $apiRequest = ApiRequest::make(
            'DELETE',
            '/fapi/v1/listenKey'
        );

        return $this->client->signRequest($apiRequest);
    }
}
