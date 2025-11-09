# API Clients - Codebase Examples

Real-world API client implementation patterns from production code.

## Table of Contents

1. [BinanceApiClient Implementation](#binanceapiclient-implementation)
2. [Request Signing](#request-signing)
3. [BaseApiClient Request Flow](#baseapiclient-request-flow)
4. [API Request Logging](#api-request-logging)
5. [Exception Handling](#exception-handling)
6. [Using API Clients](#using-api-clients)

---

## BinanceApiClient Implementation

**Location**: `packages/martingalian/core/src/Support/ApiClients/REST/BinanceApiClient.php`

### Constructor

```php
final class BinanceApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        // Load API system model
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'binance');

        // Create exception handler
        $this->exceptionHandler = BaseExceptionHandler::make('binance');

        // Build credentials value object
        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
        ]);

        // Call parent with base URL and credentials
        parent::__construct($config['url'], $credentials);
    }

    public function getHeaders(): array
    {
        return [
            'X-MBX-APIKEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }
}
```

**Key Points:**
- Loads `ApiSystem` model for configuration
- Creates exchange-specific exception handler
- Uses value objects for credentials
- Implements abstract `getHeaders()` method

---

## Request Signing

### Signed Request (Binance)

**From: BinanceApiClient (line 35)**

```php
public function signRequest(ApiRequest $apiRequest)
{
    // 1. Set recvWindow (time tolerance for request)
    $apiRequest->properties->set(
        'options.recvWindow',
        ApiSystem::firstWhere('canonical', 'binance')->recvwindow_margin
    );

    // 2. Set timestamp (milliseconds since epoch)
    $apiRequest->properties->set(
        'options.timestamp',
        round(microtime(true) * 1000)
    );

    // 3. Build query string from options
    $query = Url::buildQuery($apiRequest->properties->getOr('options', []));

    // 4. Generate HMAC-SHA256 signature
    $signature = hash_hmac(
        'sha256',
        $query,
        $this->credentials->get('api_secret')
    );

    // 5. Add signature to request
    $apiRequest->properties->set(
        'options.signature',
        $signature
    );

    // 6. Process the signed request
    return $this->processRequest($apiRequest);
}
```

**Signature Components:**
- `recvWindow`: Time tolerance (e.g., 5000ms)
- `timestamp`: Current Unix timestamp in milliseconds
- `signature`: HMAC-SHA256 of query string using API secret

### Public Request (No Signature)

```php
public function publicRequest(ApiRequest $apiRequest)
{
    return $this->processRequest($apiRequest);
}
```

**Usage:**
```php
// Public endpoint (no auth)
$response = $client->publicRequest($apiRequest);

// Private endpoint (requires signature)
$response = $client->signRequest($apiRequest);
```

---

## BaseApiClient Request Flow

**Location**: `packages/martingalian/core/src/Abstracts/BaseApiClient.php`

### processRequest() Method

**From: BaseApiClient (line 52)**

```php
protected function processRequest(ApiRequest $apiRequest, bool $sendAsJson = false)
{
    // 1. Merge headers
    $headers = array_merge(
        $this->getHeaders(),
        (array) ($apiRequest->properties->getOr('headers', []))
    );

    // 2. Prepare log data
    $logData = $this->prepareLogData($apiRequest, $headers);
    $options = $this->prepareRequestOptions($apiRequest, $sendAsJson, $headers);

    // 3. Create API request log BEFORE making request
    $startTime = microtime(true);
    $logData['started_at'] = now();
    $this->apiRequestLog = ApiRequestLog::create($logData);

    try {
        // 4. Execute HTTP request
        $response = $this->executeHttpRequest(
            $apiRequest->method,
            $apiRequest->path,
            $options
        );

        // 5. Record successful response
        $this->recordSuccessfulResponse($response, $logData, $startTime);

        return $response;
    } catch (RequestException $e) {
        // 6. Handle HTTP exceptions (4xx, 5xx)
        return $this->handleRequestException($e, $apiRequest, $options, $logData, $startTime);
    } catch (Throwable $e) {
        // 7. Handle other exceptions
        $this->updateRequestLogData([
            'error_message' => $e->getMessage().' (line '.$e->getLine().')',
        ]);
        throw $e;
    }
}
```

**Flow:**
1. Merge headers (client defaults + request-specific)
2. Prepare log data and options
3. **Create log entry BEFORE request** (records attempt)
4. Execute HTTP request via Guzzle
5. Record response (updates log entry)
6. Handle exceptions (updates log entry)
7. Rethrow for higher-level handling

---

## API Request Logging

### Log Entry Creation

**From: BaseApiClient**

```php
protected function prepareLogData(ApiRequest $apiRequest, array $headers): array
{
    $properties = $apiRequest->properties->toArray();

    $logData = [
        'api_system_id' => $this->apiSystem->id,
        'account_id' => $apiRequest->properties->getOr('account_id'),
        'relatable_type' => $apiRequest->properties->getOr('relatable_type'),
        'relatable_id' => $apiRequest->properties->getOr('relatable_id'),
        'hostname' => gethostname(),
        'method' => mb_strtoupper($apiRequest->method),
        'path' => $apiRequest->path,
        'payload' => json_encode($properties['options'] ?? []),
        'http_request_headers' => $headers,
    ];

    return $logData;
}
```

**Fields Logged:**
- `api_system_id` - Which API (Binance, Bybit, etc.)
- `account_id` - Associated account (NULL for admin)
- `relatable` - Polymorphic relation (Order, Position, etc.)
- `hostname` - Which server made request
- `method` - HTTP method (GET, POST, etc.)
- `path` - API endpoint path
- `payload` - Request body/query parameters
- `http_request_headers` - Headers sent

### Response Recording

```php
protected function recordSuccessfulResponse($response, array $logData, float $startTime): void
{
    $duration = (microtime(true) - $startTime) * 1000;  // milliseconds

    $this->updateRequestLogData([
        'http_response_code' => $response->getStatusCode(),
        'http_response_headers' => $response->getHeaders(),
        'response' => (string) $response->getBody(),
        'duration_ms' => round($duration, 2),
        'completed_at' => now(),
    ]);

    // Record headers in throttler
    if ($this->exceptionHandler) {
        $this->exceptionHandler->recordResponseHeaders($response);
    }
}
```

**Additional Fields:**
- `http_response_code` - Status code (200, 429, etc.)
- `http_response_headers` - Response headers (rate limits, etc.)
- `response` - Response body (JSON)
- `duration_ms` - Request duration in milliseconds
- `completed_at` - Timestamp when completed

**Throttler Integration:**
- Calls `exceptionHandler->recordResponseHeaders($response)`
- Extracts rate limit headers (X-MBX-USED-WEIGHT-*, etc.)
- Updates throttler cache state

---

## Exception Handling

### Request Exception Handling

**From: BaseApiClient**

```php
protected function handleRequestException(
    RequestException $e,
    ApiRequest $apiRequest,
    array $options,
    array $logData,
    float $startTime
): mixed {
    $duration = (microtime(true) - $startTime) * 1000;

    // Extract response details
    $statusCode = $e->getResponse()?->getStatusCode();
    $responseBody = (string) ($e->getResponse()?->getBody() ?? '');
    $responseHeaders = $e->getResponse()?->getHeaders() ?? [];

    // Update log with error details
    $this->updateRequestLogData([
        'http_response_code' => $statusCode,
        'http_response_headers' => $responseHeaders,
        'response' => $responseBody,
        'duration_ms' => round($duration, 2),
        'error_message' => $e->getMessage(),
        'completed_at' => now(),
    ]);

    // Record headers even on failure (for throttling)
    if ($this->exceptionHandler && $e->getResponse()) {
        $this->exceptionHandler->recordResponseHeaders($e->getResponse());
    }

    // Rethrow for job-level handling
    throw $e;
}
```

**Key Points:**
- Logs error details before rethrowing
- **Still records headers** (rate limit info even on errors)
- Rethrows exception for job to handle (retry logic, etc.)

---

## Using API Clients

### Creating an API Client

```php
use Martingalian\Core\Support\ApiClients\REST\BinanceApiClient;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

// Create client
$client = new BinanceApiClient([
    'url' => 'https://fapi.binance.com',
    'api_key' => $account->api_key,
    'api_secret' => $account->api_secret,
]);
```

### Making Requests

#### Public Endpoint (No Auth)

```php
$apiRequest = ApiRequest::make([
    'method' => 'GET',
    'path' => '/fapi/v1/time',
]);

$response = $client->publicRequest($apiRequest);
$data = json_decode($response->getBody(), true);
```

#### Private Endpoint (Signed)

```php
$apiRequest = ApiRequest::make([
    'method' => 'POST',
    'path' => '/fapi/v1/order',
    'options' => [
        'symbol' => 'BTCUSDT',
        'side' => 'BUY',
        'type' => 'MARKET',
        'quantity' => 0.001,
    ],
    'account_id' => $account->id,
    'relatable_type' => Order::class,
    'relatable_id' => $order->id,
]);

$response = $client->signRequest($apiRequest);
$data = json_decode($response->getBody(), true);
```

**ApiRequest Properties:**
- `method` - HTTP method (GET, POST, DELETE)
- `path` - API endpoint path
- `options` - Query params or request body
- `account_id` - For logging (optional)
- `relatable_type/id` - For logging (optional)

### With Relatable Models

```php
$apiRequest = ApiRequest::make([
    'method' => 'GET',
    'path' => '/fapi/v2/account',
    'account_id' => $account->id,
    'relatable_type' => Step::class,
    'relatable_id' => $step->id,
]);

$response = $client->signRequest($apiRequest);
```

**Benefit:**
- API request log linked to Step
- Can trace which step triggered which API call
- Audit trail for debugging

---

## BybitApiClient Differences

**Location**: `packages/martingalian/core/src/Support/ApiClients/REST/BybitApiClient.php`

### Signature Method

```php
public function signRequest(ApiRequest $apiRequest)
{
    $timestamp = round(microtime(true) * 1000);

    $apiRequest->properties->set('options.timestamp', $timestamp);

    // Bybit uses different signing approach
    $paramStr = json_encode($apiRequest->properties->getOr('options', []));

    $signature = hash_hmac(
        'sha256',
        $timestamp . $this->credentials->get('api_key') . $paramStr,
        $this->credentials->get('api_secret')
    );

    $apiRequest->properties->set('options.sign', $signature);

    return $this->processRequest($apiRequest, sendAsJson: true);
}
```

**Differences:**
- Bybit signs: `timestamp + apiKey + jsonParams`
- Binance signs: `queryString`
- Bybit sends JSON body (`sendAsJson: true`)
- Binance sends query params

---

## Testing API Clients

### Mocking in Tests

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

// Create mock responses
$mock = new MockHandler([
    new Response(200, [], json_encode(['orderId' => 123])),
    new Response(429, ['Retry-After' => '60'], json_encode(['code' => -1003])),
]);

$handlerStack = HandlerStack::create($mock);
$mockClient = new Client(['handler' => $handlerStack]);

// Bind to container
app()->instance(Client::class, $mockClient);

// Now API clients will use mock
$client = new BinanceApiClient($config);
$response = $client->signRequest($apiRequest);
```

---

## Summary

**Standard Pattern:**
1. Create client with config (URL, credentials)
2. Build ApiRequest with method/path/options
3. Call `publicRequest()` or `signRequest()`
4. API client logs request/response
5. Throttler records headers
6. Exception thrown if error (caught by job)

**Key Files:**
- `BinanceApiClient.php` - Binance REST client
- `BybitApiClient.php` - Bybit REST client
- `BaseApiClient.php` - Shared request/logging logic
- `ApiRequest.php` - Value object for requests
- `ApiCredentials.php` - Value object for credentials

**Logging:**
- Every request logged in `api_request_logs` table
- Includes request/response, headers, duration
- Links to account and relatable models
- Triggers notifications via observer

**Never:**
- Make API calls without logging
- Skip signature on private endpoints
- Ignore response headers
- Catch exceptions in client (rethrow to job)
