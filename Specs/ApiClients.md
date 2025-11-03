# API Clients

## Overview
External API integration layer supporting cryptocurrency exchanges (Binance, Bybit), market data providers (TAAPI, CoinMarketCap), and sentiment analysis (Alternative.me). All clients include exception handling, rate limiting, and user-friendly error notifications.

## Architecture

### Core Components

1. **BaseApiClient** (`packages/martingalian/core/src/Abstracts/BaseApiClient.php`)
   - Abstract base class for all API clients
   - Provides HTTP client via Guzzle
   - Common configuration (timeout, retry, headers)
   - Abstract methods: `getHeaders()`
   - Automatic request logging to `api_request_logs` table
   - Duration tracking with negative value guard (`max(0, ...)`)
   - Timing: `started_at` captured BEFORE creating log record to prevent race conditions

2. **BaseWebsocketClient** (`packages/martingalian/core/src/Abstracts/BaseWebsocketClient.php`)
   - Abstract base class for WebSocket clients
   - Uses Ratchet + ReactPHP event loop
   - Methods: `subscribeToStream()`, `subscribeToUserStream()`, `onConnectionEstablished()`
   - Handles connection lifecycle

3. **Exception Handlers** (`BaseExceptionHandler`)
   - `BinanceExceptionHandler` - Handles Binance-specific errors, delegates to BinanceThrottler
   - `BybitExceptionHandler` - Handles Bybit-specific errors, delegates to BybitThrottler
   - `TaapiExceptionHandler` - Handles TAAPI errors (no IP ban tracking)
   - `CoinMarketCapExceptionHandler` - Handles CoinMarketCap errors (no IP ban tracking)
   - `AlternativeMeExceptionHandler` - Handles Alternative.me errors (no IP ban tracking)

4. **Throttlers** (IP-based rate limiting coordination)
   - `BinanceThrottler` - Parses Binance headers, IP ban tracking, rate limit proximity (>80%)
   - `BybitThrottler` - Conservative limits (500 req/5s = 83% of 600 limit), IP ban tracking
   - `TaapiThrottler` - Simple throttling (no IP ban tracking)
   - Two-phase throttling: `isSafeToDispatch()` (IP ban, proximity) + `canDispatch()` (window limits, backoff)

## Exchange Clients

### Binance

**REST API Client**: `packages/martingalian/core/src/Support/ApiClients/Rest/BinanceApiClient.php`

Features:
- Spot trading endpoints
- Futures/Margin trading
- Account information
- Market data
- HMAC signature authentication
- Weight-based rate limiting

Rate Limits:
- Weight per minute
- Order limits
- Raw request limits

Common Errors:
- `-2015`: Invalid API key format
- `-1021`: Timestamp outside recv_window
- `-1022`: Invalid signature
- `418`: IP banned for violating rate limits
- `451`: IP not whitelisted

**WebSocket Client**: `packages/martingalian/core/src/Support/ApiClients/Websocket/BinanceApiClient.php`

Streams:
- Ticker streams (`{symbol}@ticker`)
- User data streams (requires listen key)
- Automatic listen key keepalive (every 30 minutes)

### Bybit

**REST API Client**: `packages/martingalian/core/src/Support/ApiClients/Rest/BybitApiClient.php`

Features:
- V5 API (unified margin)
- Spot/Linear/Inverse trading
- Account information
- Market data
- API key authentication

Rate Limits:
- 10 messages per second for WebSocket subscriptions
- REST endpoint specific limits

Common Errors:
- `10003`: Invalid API key
- `10004`: IP not whitelisted
- `10005`: Permission denied
- `10006`: Too many requests
- `33004`: Invalid signature

**WebSocket Client**: `packages/martingalian/core/src/Support/ApiClients/Websocket/BybitApiClient.php`

Streams:
- Public: `wss://stream.bybit.com/v5/public/linear`
- Private: `wss://stream.bybit.com/v5/private`
- Subscription format: `{"op": "subscribe", "args": ["tickers.BTCUSDT"]}`
- Ping every 20 seconds: `{"op": "ping"}`

**Key Fix**: Removed non-existent `isConnected()` method call in periodic timer (line 85). Timer runs inside `onConnectionEstablished()` callback, so connection is guaranteed.

## Market Data Providers

### TAAPI (Technical Analysis API)

**Client**: `packages/martingalian/core/src/Support/ApiClients/Rest/TaapiApiClient.php`

Features:
- 100+ technical indicators
- Multi-exchange support
- Bulk requests
- Secret authentication

Rate Limits:
- Plan-based (Basic, Pro, Expert)
- Monthly request quota

Common Errors:
- `429`: Rate limit exceeded
- `401`: Invalid API key
- `402`: Insufficient credits

Endpoint Example:
```
GET https://api.taapi.io/rsi?secret=xxx&exchange=binance&symbol=BTC/USDT&interval=1h
```

### CoinMarketCap

**Client**: `packages/martingalian/core/src/Support/ApiClients/Rest/CoinMarketCapApiClient.php`

Features:
- Cryptocurrency listings
- Price data
- Market cap rankings
- Global metrics
- API key in header

Rate Limits:
- Plan-based (Basic, Hobbyist, Startup, Standard, Professional, Enterprise)
- Credit-based system
- Daily/monthly limits

Common Errors:
- `401`: Invalid API key
- `429`: Rate limit exceeded
- `1001`: API key required

Endpoint Example:
```
GET https://pro-api.coinmarketcap.com/v1/cryptocurrency/listings/latest
Headers: X-CMC_PRO_API_KEY: xxx
```

### Alternative.me (Fear & Greed Index)

**Client**: `packages/martingalian/core/src/Support/ApiClients/Rest/AlternativeMeApiClient.php`

Features:
- Crypto Fear & Greed Index
- Historical data
- No authentication required
- Free public API

Endpoint:
```
GET https://api.alternative.me/fng/?limit=1&format=json
```

Response:
```json
{
  "name": "Fear and Greed Index",
  "data": [{
    "value": "45",
    "value_classification": "Fear",
    "timestamp": "1234567890"
  }]
}
```

## Exception Handling

### Exception Handler Pattern

All API clients use dedicated exception handlers (`BaseExceptionHandler`) that:
1. Map HTTP status codes to canonical identifiers
2. Analyze error conditions (rate limits, bans, retryable errors)
3. Coordinate with throttlers for IP ban tracking and rate limit management
4. Provide pre-flight safety checks (`isSafeToMakeRequest()`)

### BaseExceptionHandler Abstract Methods

All exception handlers must implement:
1. `recordResponseHeaders(ResponseInterface $response): void` - Record rate limit headers in cache
2. `isCurrentlyBanned(): bool` - Check if IP is currently banned
3. `recordIpBan(int $retryAfterSeconds): void` - Record IP ban in cache
4. `isSafeToMakeRequest(): bool` - Pre-flight safety check (IP ban, rate limit proximity)

**Implementation**:
- **Exchange handlers** (Binance, Bybit): Delegate to respective throttlers
- **Simple APIs** (TAAPI, CoinMarketCap, Alternative.me): No-op implementations (no IP ban tracking)

### BinanceExceptionHandler

Located: `packages/martingalian/core/src/Support/ExceptionHandlers/BinanceExceptionHandler.php`

Handles:
- IP not whitelisted (`451`)
- Rate limits (`418`, `429`)
- Invalid credentials (`401`, `-2015`)
- Timestamp errors (`-1021`)
- Connection failures

Canonical examples:
- `binance_ip_not_whitelisted`
- `binance_api_rate_limit_exceeded`
- `binance_invalid_api_credentials`
- `binance_api_connection_failed`

### BybitExceptionHandler

Located: `packages/martingalian/core/src/Support/ExceptionHandlers/BybitExceptionHandler.php`

Handles:
- IP not whitelisted (`10004`)
- Rate limits (`10006`)
- Invalid credentials (`10003`)
- Permission denied (`10005`)
- Connection failures

Canonical examples:
- `bybit_ip_not_whitelisted`
- `bybit_api_rate_limit_exceeded`
- `bybit_invalid_api_credentials`

### TaapiExceptionHandler

Handles:
- Rate limits (`429`)
- Invalid API key (`401`)
- Insufficient credits (`402`)

### Exception Handler Responsibilities

Exception handlers analyze errors but DO NOT send notifications directly:
- `isRateLimited()` - Check if error is rate-limit related
- `isForbidden()` - Check if error is auth/permission related
- `ignoreException()` - Check if error can be ignored
- `retryException()` - Check if error should be retried
- `extractHttpErrorCodes()` - Extract vendor error codes from response
- `rateLimitUntil()` - Calculate safe retry time using response headers

**Notification Rule**: ALL API error notifications originate from `ApiRequestLog` model via `SendsNotifications` trait (see Notifications spec)

## Rate Limiting

### Implementation Strategies

1. **Weight-based** (Binance)
   - Track request weight
   - Reset every minute
   - Preemptive backoff when approaching limit

2. **Count-based** (Bybit, TAAPI)
   - Track request count
   - Reset per interval (second, minute, day)
   - Queue requests when limit reached

3. **Credit-based** (CoinMarketCap)
   - Track API credits
   - Monthly quota
   - Prioritize high-value requests

### Rate Limit Headers

Clients parse response headers:
- `X-MBX-USED-WEIGHT-1M` (Binance)
- `X-Bapi-Limit-Status` (Bybit)
- `X-CMC-Credits-Remaining` (CoinMarketCap)

## Throttlers (IP-based Rate Limiting Coordination)

### Architecture

**Location**: `packages/martingalian/core/src/Support/Throttlers/`

**Purpose**: Coordinate rate limiting across workers using Redis cache to prevent IP bans

### BinanceThrottler

**Features**:
- Parses response headers (`X-MBX-USED-WEIGHT-*`, `X-MBX-ORDER-COUNT-*`)
- Tracks IP ban state in cache with expiry
- Rate limit proximity detection (>80% threshold triggers `isSafeToDispatch() > 0`)
- Two-phase throttling: pre-flight safety + standard throttling

**Cache Keys**:
- `binance:ip_ban:{hostname}` - IP ban state with retry-after TTL
- `binance:rate_limits:{hostname}` - Current rate limit usage

**Methods**:
- `recordResponseHeaders()` - Parse and cache rate limit headers
- `isCurrentlyBanned()` - Check if IP is banned
- `recordIpBan()` - Record ban with retry-after TTL
- `isSafeToDispatch()` - Returns delay in seconds (0 = safe, >0 = wait)
- `canDispatch()` - Standard throttling (window limits, backoff)

### BybitThrottler

**Features**:
- Conservative limits (500 req/5s = 83% of Bybit's 600 req/5s limit)
- IP ban tracking via cache
- Pre-flight safety checks
- Two-phase throttling

**Cache Keys**:
- `bybit:ip_ban:{hostname}` - IP ban state
- Request tracking via `BaseApiThrottler`

**Methods**: Same interface as BinanceThrottler

### Two-Phase Throttling Pattern

**Phase 1** - Pre-flight Safety (`isSafeToDispatch()`):
- Check IP ban status (immediate rejection if banned)
- Check rate limit proximity (reject if >80% capacity)
- Returns delay in seconds (0 = safe, >0 = wait time)

**Phase 2** - Standard Throttling (`canDispatch()`):
- Window-based limits (requests per interval)
- Minimum delay between requests
- Exponential backoff on errors

**Usage in Jobs**:
```php
// Phase 1: Pre-flight check
$delay = BinanceThrottler::isSafeToDispatch();
if ($delay > 0) {
    $this->release($delay); // Postpone job
    return;
}

// Phase 2: Standard throttling
if (!BinanceThrottler::canDispatch()) {
    $this->release(5); // Brief delay
    return;
}

// Safe to proceed with API call
```

## WebSocket Architecture

### ReactPHP Event Loop

All WebSocket clients use ReactPHP's event loop:
```php
$this->loop = \React\EventLoop\Loop::get();
```

Operations:
- `addPeriodicTimer()`: Recurring tasks (pings, keepalives)
- `addTimer()`: One-time delayed tasks
- `run()`: Start event loop (blocking)

### Connection Lifecycle

1. **Connect**: `\Ratchet\Client\connect($url, [], [], $loop)`
2. **On Open**: `onConnectionEstablished()` callback
3. **On Message**: Message handlers via `$conn->on('message', ...)`
4. **On Close**: Cleanup and reconnect logic
5. **On Error**: Error handlers and notifications

### Ratchet WebSocket

Key methods:
- `$conn->send($json)`: Send message
- `$conn->on('message', callable)`: Register message handler
- `$conn->close()`: Close connection

**Important**: `$conn->isConnected()` does NOT exist (was causing bug in BybitApiClient)

## Configuration

### Environment Variables

```env
# Binance
BINANCE_API_KEY=xxx
BINANCE_API_SECRET=xxx
BINANCE_BASE_URL=https://api.binance.com

# Bybit
BYBIT_API_KEY=xxx
BYBIT_API_SECRET=xxx
BYBIT_BASE_URL=https://api.bybit.com

# TAAPI
TAAPI_API_SECRET=xxx
TAAPI_BASE_URL=https://api.taapi.io

# CoinMarketCap
COINMARKETCAP_API_KEY=xxx
COINMARKETCAP_BASE_URL=https://pro-api.coinmarketcap.com

# Alternative.me
ALTERNATIVEME_BASE_URL=https://api.alternative.me
```

### Config Files

Located: `config/services.php` or `config/martingalian.php`

Example:
```php
'binance' => [
    'api_key' => env('BINANCE_API_KEY'),
    'api_secret' => env('BINANCE_API_SECRET'),
    'base_url' => env('BINANCE_BASE_URL', 'https://api.binance.com'),
    'testnet' => env('BINANCE_TESTNET', false),
],
```

## Testing

### Mocking External APIs

Use HTTP fake for REST clients:
```php
Http::fake([
    'api.binance.com/*' => Http::response(['price' => '50000'], 200),
    'api.bybit.com/*' => Http::response(['result' => []], 200),
]);
```

### Testing Exception Handlers

```php
it('handles IP not whitelisted error', function () {
    $exception = new \GuzzleHttp\Exception\ClientException(
        'IP not whitelisted',
        new \GuzzleHttp\Psr7\Request('GET', 'test'),
        new \GuzzleHttp\Psr7\Response(451)
    );

    expect(fn() => BinanceExceptionHandler::handle($exception, [
        'exchange' => 'binance',
        'ip' => '1.2.3.4',
    ]))->toThrow(\GuzzleHttp\Exception\ClientException::class);

    // Assert notification was sent (use Mail/Pushover fake)
});
```

### Testing WebSocket Clients

Use mock connector:
```php
$mockConnector = $this->mock(\Ratchet\Client\Connector::class);
$client = new BybitApiClient(['ws_connector' => $mockConnector]);
```

## Common Issues

### Issue: IP Not Whitelisted
- **Symptoms**: 451 (Binance) or 10004 (Bybit) errors
- **Solution**: Add server IP to exchange API whitelist
- **Notification**: Critical severity, includes IP address on separate line

### Issue: Rate Limit Exceeded
- **Symptoms**: 429 errors, slow responses
- **Solution**: Implement backoff, reduce request frequency
- **Notification**: High severity, auto-resolves

### Issue: Invalid Credentials
- **Symptoms**: 401 errors, -2015 (Binance), 10003 (Bybit)
- **Solution**: Verify API key/secret, check permissions
- **Notification**: Critical severity, includes account name

### Issue: WebSocket Disconnects
- **Symptoms**: Connection drops, no data updates
- **Solution**: Implement reconnect logic, check ping/pong
- **Notification**: High severity, includes hostname

### Issue: Timestamp Errors
- **Symptoms**: -1021 errors (Binance)
- **Solution**: Sync server time via NTP
- **Notification**: High severity

## Future Enhancements

- Automatic retry with exponential backoff
- Circuit breaker pattern for failing APIs
- Request queuing for rate limit management
- Connection pooling for WebSockets
- Metrics/observability (request counts, latencies)
- Multi-region failover
- API response caching
