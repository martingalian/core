# API Data Mapping System

## Overview
Exchange-agnostic data transformation layer that converts between internal data models and exchange-specific API formats. Provides unified interface for interacting with multiple exchanges (Binance, Bybit, Kraken, BitGet, KuCoin) and data providers (TAAPI, CoinMarketCap) while handling format differences transparently.

## Architecture

### Mapping Flow
```
Internal Model (Order, Position, etc.)
    ↓
ApiDataMapper.prepareXXXProperties()
    ↓
ApiRequest with exchange-specific format
    ↓
API Client sends request
    ↓
Exchange API Response
    ↓
ApiDataMapper.resolveXXXResponse()
    ↓
Normalized array/collection
    ↓
Internal Model updated
```

## Core Components

### BaseDataMapper
**Location**: `Martingalian\Core\Abstracts\BaseDataMapper`
**Purpose**: Abstract base class for all data mappers

**Features**:
- Common validation logic via `ValidatesAttributes` concern
- Template for request/response transformation methods
- Standard interface for all exchange mappers

**Usage**: Extended by exchange-specific mappers

---

### BinanceApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper`
**Purpose**: Maps data to/from Binance Futures API format

**Traits**:
- `MapsPlaceOrder` - Order placement
- `MapsOrderQuery` - Order status query
- `MapsOrderCancel` - Order cancellation
- `MapsOrderModify` - Order modification
- `MapsOpenOrdersQuery` - Open orders query
- `MapsCancelOrders` - Batch order cancellation
- `MapsPositionsQuery` - Positions query
- `MapsAccountQuery` - Account info query
- `MapsAccountBalanceQuery` - Balance query
- `MapsAccountQueryTrades` - Trade history query
- `MapsExchangeInformationQuery` - Symbol metadata
- `MapsLeverageBracketsQuery` - Leverage tiers
- `MapsMarkPriceQuery` - Current price
- `MapsSymbolMarginType` - Margin type (ISOLATED/CROSS)
- `MapsTokenLeverageRatios` - Leverage settings
- `MapsKlinesQuery` - Candlestick/OHLCV data

**Common Methods**:
```php
public function long(): string; // Returns 'LONG'
public function short(): string; // Returns 'SHORT'
public function directionType(string $canonical): string; // Maps direction
public function sideType(string $canonical): string; // Maps BUY/SELL
public function baseWithQuote(string $token, string $quote): string; // Builds trading pair
public function identifyBaseAndQuote(string $token): array; // Parses trading pair
```

**Trading Pair Handling**:
```php
// Build trading pair (simple concatenation)
$pair = $mapper->baseWithQuote('BTC', 'USDT'); // Returns: "BTCUSDT"

// Parse trading pair
$parts = $mapper->identifyBaseAndQuote('BTCUSDT');
// Returns: ['base' => 'BTC', 'quote' => 'USDT']
```

---

### BybitApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper`
**Purpose**: Maps data to/from Bybit V5 API format

**Traits**:
- `MapsPlaceOrder` - Order placement
- `MapsOrderQuery` - Order status query
- `MapsOrderCancel` - Order cancellation
- `MapsOrderModify` - Order modification
- `MapsOpenOrdersQuery` - Open orders query
- `MapsCancelOrders` - Batch order cancellation
- `MapsPositionsQuery` - Positions query
- `MapsAccountQuery` - Account info query
- `MapsAccountBalanceQuery` - Balance query
- `MapsExchangeInformationQuery` - Symbol metadata
- `MapsLeverageBracketsQuery` - Leverage tiers
- `MapsMarkPriceQuery` - Current price
- `MapsSymbolMarginType` - Margin type (ISOLATED/CROSS)
- `MapsTokenLeverageRatios` - Leverage settings
- `MapsKlinesQuery` - Candlestick/OHLCV data

**Differences from Binance**:
- V5 API uses different parameter names
- Category parameter required (linear, inverse, spot)
- Different response structure
- Different error codes
- **PERP suffix** for USDC-settled perpetual contracts

**Symbol Naming Convention**:
```php
// USDT perpetuals: Standard format
'BNBUSDT', 'SOLUSDT', 'XRPUSDT'

// USDC perpetuals: PERP suffix
'BNBPERP', 'SOLPERP', 'XRPPERP'
```

**Mapper Handles PERP Automatically**:
```php
// Build symbol for API request
$symbol = $mapper->baseWithQuote('BNB', 'USDC'); // Returns: "BNBPERP"
$symbol = $mapper->baseWithQuote('BNB', 'USDT'); // Returns: "BNBUSDT"

// Parse symbol from API response
$parts = $mapper->identifyBaseAndQuote('BNBPERP');
// Returns: ['base' => 'BNB', 'quote' => 'USDC']

$parts = $mapper->identifyBaseAndQuote('BNBUSDT');
// Returns: ['base' => 'BNB', 'quote' => 'USDT']
```

**Example**:
```php
// Bybit uses category parameter
$properties->set('options.category', 'linear'); // For USDT perpetuals

// Different parameter names
$properties->set('options.symbol', 'BTCUSDT'); // Same
$properties->set('options.side', 'Buy'); // Capitalized (not 'BUY')
$properties->set('options.orderType', 'Market'); // Not 'type'
```

---

### KrakenApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Kraken\KrakenApiDataMapper`
**Purpose**: Maps data to/from Kraken Futures API format

**Traits**:
- `MapsServerTimeQuery` - Server time query
- `MapsExchangeInformationQuery` - Instruments/symbols query
- `MapsPositionsQuery` - Open positions query
- `MapsAccountBalanceQuery` - Account balance query
- `MapsOpenOrdersQuery` - Open orders query
- `MapsAccountQuery` - Account info query
- `MapsPlaceOrder` - Order placement
- `MapsOrderCancel` - Order cancellation
- `MapsOrderModify` - Order modification
- `MapsCancelOrders` - Batch order cancellation
- `MapsMarkPriceQuery` - Current price (via tickers)
- `MapsLeverageBracketsQuery` - Leverage preferences
- `MapsSymbolMarginType` - Margin type settings
- `MapsTokenLeverageRatios` - Leverage settings
- `MapsKlinesQuery` - Candlestick/OHLCV data

**Common Methods**:
```php
public function long(): string; // Returns 'long'
public function short(): string; // Returns 'short'
public function directionType(string $canonical): string; // Maps direction
public function sideType(string $canonical): string; // Maps buy/sell
public function baseWithQuote(string $token, string $quote): string; // Builds trading pair
public function identifyBaseAndQuote(string $token): array; // Parses trading pair
```

**Trading Pair Handling**:
```php
// Kraken Futures uses different symbol formats
// PI_XBTUSD = Bitcoin perpetual inverse
// PF_XBTUSD = Bitcoin perpetual fixed-margin

// Build trading pair
$pair = $mapper->baseWithQuote('BTC', 'USD'); // Returns: "PI_XBTUSD"

// Parse trading pair
$parts = $mapper->identifyBaseAndQuote('PI_XBTUSD');
// Returns: ['base' => 'BTC', 'quote' => 'USD']
```

**API Endpoints**:
```php
// Server time
GET /derivatives/api/v3/time

// Exchange information (instruments)
GET /derivatives/api/v3/instruments

// Open positions
GET /derivatives/api/v3/openpositions

// Account balance
GET /derivatives/api/v3/accounts

// Open orders
GET /derivatives/api/v3/openorders
```

---

### BitgetApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper`
**Purpose**: Maps data to/from BitGet Futures V2 API format

**Traits**:
- `MapsPlaceOrder` - Order placement
- `MapsOrderQuery` - Order status query
- `MapsOrderCancel` - Order cancellation
- `MapsOrderModify` - Order modification
- `MapsOpenOrdersQuery` - Open orders query
- `MapsCancelOrders` - Batch order cancellation
- `MapsPositionsQuery` - Positions query
- `MapsAccountQuery` - Account info query
- `MapsAccountBalanceQuery` - Balance query
- `MapsExchangeInformationQuery` - Symbol metadata
- `MapsLeverageBracketsQuery` - Leverage tiers
- `MapsMarkPriceQuery` - Current price
- `MapsSymbolMarginType` - Margin type (ISOLATED/CROSS)
- `MapsTokenLeverageRatios` - Leverage settings
- `MapsKlinesQuery` - Candlestick/OHLCV data

**Differences from Binance**:
- Requires `productType` parameter (e.g., `USDT-FUTURES`)
- Uses `granularity` instead of `interval` for klines
- Different response structure with nested `data` array

**Trading Pair Handling**:
```php
// Build trading pair (simple concatenation)
$pair = $mapper->baseWithQuote('BTC', 'USDT'); // Returns: "BTCUSDT"

// Parse trading pair
$parts = $mapper->identifyBaseAndQuote('BTCUSDT');
// Returns: ['base' => 'BTC', 'quote' => 'USDT']
```

---

### KucoinApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Kucoin\KucoinApiDataMapper`
**Purpose**: Maps data to/from KuCoin Futures API format

**Traits**:
- `MapsPlaceOrder` - Order placement
- `MapsOrderQuery` - Order status query
- `MapsOrderCancel` - Order cancellation
- `MapsOrderModify` - Order modification
- `MapsOpenOrdersQuery` - Open orders query
- `MapsCancelOrders` - Batch order cancellation
- `MapsPositionsQuery` - Positions query
- `MapsAccountQuery` - Account info query
- `MapsAccountBalanceQuery` - Balance query
- `MapsExchangeInformationQuery` - Symbol metadata
- `MapsLeverageBracketsQuery` - Leverage tiers
- `MapsMarkPriceQuery` - Current price
- `MapsSymbolMarginType` - Margin type (ISOLATED/CROSS)
- `MapsTokenLeverageRatios` - Leverage settings
- `MapsKlinesQuery` - Candlestick/OHLCV data

**Differences from Binance**:
- Symbol format uses `M` suffix for perpetuals (e.g., `XBTUSDTM` instead of `BTCUSDT`)
- Uses `XBT` instead of `BTC` for Bitcoin
- Uses `granularity` in minutes (integer) instead of interval string for klines
- Timestamps in seconds for some endpoints

**Trading Pair Handling**:
```php
// Build trading pair (adds M suffix)
$pair = $mapper->baseWithQuote('BTC', 'USDT'); // Returns: "XBTUSDTM"

// Parse trading pair
$parts = $mapper->identifyBaseAndQuote('XBTUSDTM');
// Returns: ['base' => 'BTC', 'quote' => 'USDT']
```

---

### TaapiApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Taapi\TaapiApiDataMapper`
**Purpose**: Maps data to/from TAAPI.io indicator API

**Features**:
- Prepares indicator queries (RSI, MACD, EMA, etc.)
- Resolves indicator responses into IndicatorHistory format
- Handles batch indicator requests
- Parses complex indicator structures (MACD has multiple values)

**Example**:
```php
// Prepare RSI request
$properties = $mapper->prepareRSIQuery($exchangeSymbol, '1h', 14);
// {
//   "symbol": "BTCUSDT",
//   "interval": "1h",
//   "period": 14
// }

// Resolve RSI response
$data = $mapper->resolveRSIResponse($response);
// {
//   "rsi": 65.43
// }
```

---

### CoinmarketCapDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\CoinmarketCap\CoinmarketCapDataMapper`
**Purpose**: Maps data from CoinMarketCap API

**Features**:
- Cryptocurrency listings
- Price quotes
- Market data
- Historical data

**Usage**: Market analysis, portfolio valuation

---

### ApiDataMapperProxy
**Location**: `Martingalian\Core\Support\Proxies\ApiDataMapperProxy`
**Purpose**: Routes requests to correct mapper based on API system

**Factory Pattern**:
```php
$mapper = ApiDataMapperProxy::make('binance');
// Returns: BinanceApiDataMapper instance

$mapper = ApiDataMapperProxy::make('bybit');
// Returns: BybitApiDataMapper instance

$mapper = ApiDataMapperProxy::make('kraken');
// Returns: KrakenApiDataMapper instance

$mapper = ApiDataMapperProxy::make('bitget');
// Returns: BitgetApiDataMapper instance

$mapper = ApiDataMapperProxy::make('kucoin');
// Returns: KucoinApiDataMapper instance

// Use mapper
$properties = $mapper->preparePlaceOrderProperties($order);
```

---

## Mapping Patterns

### Request Mapping Pattern
Each API endpoint has a `prepareXXXProperties()` method that transforms internal models to API format.

**Pattern**:
```php
public function prepareXXXProperties(Model $model): ApiProperties
{
    $properties = new ApiProperties;

    // Set relatable model for logging
    $properties->set('relatable', $model);

    // Map model attributes to API parameters
    $properties->set('options.symbol', $this->formatSymbol($model));
    $properties->set('options.quantity', $this->formatQuantity($model));
    // ... more mappings

    return $properties;
}
```

**Example**: MapsPlaceOrder::preparePlaceOrderProperties()
```php
public function preparePlaceOrderProperties(Order $order): ApiProperties
{
    // Generate client order ID if missing
    if (is_null($order->client_order_id)) {
        $order->updateSaving(['client_order_id' => Str::uuid()->toString()]);
    }

    $properties = new ApiProperties;
    $properties->set('relatable', $order);

    // Basic order parameters
    $properties->set('options.symbol', (string) $order->position->exchangeSymbol->parsed_trading_pair);
    $properties->set('options.side', (string) $this->sideType($order->side)); // BUY/SELL
    $properties->set('options.newClientOrderId', (string) $order->client_order_id);
    $properties->set('options.positionSide', (string) $order->position_side); // LONG/SHORT
    $properties->set('options.quantity', (string) api_format_quantity($order->quantity, $order->position->exchangeSymbol));

    // Order type specific parameters
    switch ($order->type) {
        case 'LIMIT':
            $properties->set('options.timeInForce', 'GTC');
            $properties->set('options.type', 'LIMIT');
            $properties->set('options.price', (string) api_format_price($order->price, $order->position->exchangeSymbol));
            break;

        case 'MARKET':
            $properties->set('options.type', 'MARKET');
            break;

        case 'STOP-MARKET':
            $properties->set('options.type', 'STOP_MARKET');
            $properties->set('options.timeInForce', 'GTC');
            $properties->set('options.stopPrice', (string) api_format_price($order->price, $order->position->exchangeSymbol));
            break;
    }

    return $properties;
}
```

---

### Response Mapping Pattern
Each API endpoint has a `resolveXXXResponse()` method that transforms API response to normalized format.

**Pattern**:
```php
public function resolveXXXResponse(Response $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    // Transform response to internal format
    return [
        'internal_field' => $data['api_field'],
        'normalized_value' => $this->transformValue($data['raw_value']),
        // ... more transformations
    ];
}
```

**Example**: MapsPositionsQuery::resolvePositionsQueryResponse()
```php
public function resolvePositionsQueryResponse(Response $response): array
{
    $positions = collect(json_decode((string) $response->getBody(), true))
        ->map(function ($position) {
            // Normalize symbol from raw 'BTCUSDT' to internal 'BTC/USDT' format
            if (isset($position['symbol'])) {
                $parts = $this->identifyBaseAndQuote($position['symbol']);
                $position['symbol'] = $parts['base'].'/'.$parts['quote'];
            }

            return $position;
        })
        ->keyBy('symbol')
        ->toArray();

    // Remove false positive positions (positionAmt = 0.0)
    $positions = array_filter($positions, function ($position) {
        return (float) $position['positionAmt'] !== 0.0;
    });

    return $positions;
}
```

**Symbol Normalization**: The mapper transforms raw exchange format to internal format:
- **Input** (Binance raw): `'BTCUSDT'`
- **Output** (Internal): `'BTC/USDT'`

This ensures consistency with `Position::parsed_trading_pair` and enables symbol comparison in `Position::apiClose()`.

**CRITICAL**: All response mappers must normalize symbols using `identifyBaseAndQuote()`. This was a production bug - `MapsPositionsQuery` initially didn't normalize symbols, causing `apiClose()` to fail position matching.

---

### Klines Query Pattern
Each exchange has a `MapsKlinesQuery` trait that handles candlestick/OHLCV data fetching.

**Preparation Method**: `prepareQueryKlinesProperties()`
```php
public function prepareQueryKlinesProperties(
    ExchangeSymbol $exchangeSymbol,
    string $interval = '5m',
    ?int $startTime = null,
    ?int $endTime = null,
    ?int $limit = null
): ApiProperties
```

**Resolution Method**: `resolveQueryKlinesResponse()`
Returns normalized array regardless of exchange:
```php
[
    [
        'timestamp' => 1703520000000, // Unix milliseconds
        'open' => '42000.50',
        'high' => '42150.00',
        'low' => '41900.00',
        'close' => '42100.00',
        'volume' => '1234.567',
    ],
    // ... more candles
]
```

**Exchange-Specific Differences**:

| Exchange | Interval Format | Timestamp Unit | Response Structure |
|----------|-----------------|----------------|-------------------|
| Binance | `"5m"` | milliseconds | `[[openTime, o, h, l, c, v, ...], ...]` |
| Bybit | `"5"` (number only) | milliseconds | `{result: {list: [[ts, o, h, l, c, v, turnover], ...]}}` |
| KuCoin | `5` (integer minutes) | seconds | `{data: [[time, o, h, l, c, v], ...]}` |
| BitGet | `"5m"` | milliseconds | `{data: [["ts", "o", "h", "l", "c", "vol", "quoteVol"], ...]}` |
| Kraken | `"5m"` (in URL path) | seconds | `{candles: [{time, open, high, low, close, volume}, ...]}` |

**Key Normalization**:
- All responses normalized to milliseconds (Kraken/KuCoin convert from seconds)
- All numeric values cast to strings for consistency
- Empty/malformed candles filtered out

---

## Data Formatting Helpers

### Price Formatting
**Function**: `api_format_price(float $price, ExchangeSymbol $symbol): string`
**Purpose**: Format price to exchange's precision

```php
$price = 45678.123456789;
$formatted = api_format_price($price, $exchangeSymbol);
// Returns: "45678.12" (if price_precision = 2)

// Respects tick_size
$price = 45678.7;
$formatted = api_format_price($price, $exchangeSymbol);
// Returns: "45678.5" (if tick_size = 0.5)
```

### Quantity Formatting
**Function**: `api_format_quantity(float $quantity, ExchangeSymbol $symbol): string`
**Purpose**: Format quantity to exchange's precision

```php
$quantity = 0.123456789;
$formatted = api_format_quantity($quantity, $exchangeSymbol);
// Returns: "0.123" (if quantity_precision = 3)
```

### Timestamp Formatting
**Binance**: Milliseconds since epoch
```php
$timestamp = round(microtime(true) * 1000); // 1699876543210
```

**Bybit**: Milliseconds since epoch (same as Binance)

---

## Exchange-Specific Differences

### Order Placement

**Binance**:
```json
{
  "symbol": "BTCUSDT",
  "side": "BUY",
  "positionSide": "LONG",
  "type": "LIMIT",
  "timeInForce": "GTC",
  "quantity": "0.001",
  "price": "45000.00"
}
```

**Bybit V5**:
```json
{
  "category": "linear",
  "symbol": "BTCUSDT",
  "side": "Buy",
  "orderType": "Limit",
  "qty": "0.001",
  "price": "45000.00",
  "timeInForce": "GTC",
  "positionIdx": 1
}
```

**Differences**:
- Bybit requires `category` parameter
- Bybit uses `orderType` instead of `type`
- Bybit capitalizes sides: `Buy`/`Sell` (not `BUY`/`SELL`)
- Bybit uses `qty` instead of `quantity`
- Bybit uses `positionIdx` (0=one-way, 1=long, 2=short) instead of `positionSide`

---

### Position Query

**Binance Response**:
```json
[
  {
    "symbol": "BTCUSDT",
    "positionAmt": "0.100",
    "entryPrice": "45000.00",
    "markPrice": "46000.00",
    "unRealizedProfit": "100.00",
    "liquidationPrice": "40000.00",
    "leverage": "10",
    "marginType": "isolated",
    "positionSide": "LONG"
  }
]
```

**Bybit V5 Response**:
```json
{
  "result": {
    "list": [
      {
        "symbol": "BTCUSDT",
        "side": "Buy",
        "size": "0.100",
        "avgPrice": "45000.00",
        "markPrice": "46000.00",
        "unrealisedPnl": "100.00",
        "liqPrice": "40000.00",
        "leverage": "10",
        "tradeMode": 1,
        "positionIdx": 1
      }
    ]
  }
}
```

**Mapping Logic**:
```php
// Binance
$positions = $response; // Array directly

// Bybit
$positions = $response['result']['list']; // Nested

// Normalize
foreach ($positions as $position) {
    $normalized = [
        'symbol' => $position['symbol'],
        'quantity' => $binance ? $position['positionAmt'] : $position['size'],
        'entry_price' => $binance ? $position['entryPrice'] : $position['avgPrice'],
        'unrealized_pnl' => $binance ? $position['unRealizedProfit'] : $position['unrealisedPnl'],
        // ... etc
    ];
}
```

---

## Indicator Data Mapping

### TAAPI.io Response Structures

**Simple Indicator** (RSI):
```json
{
  "value": 65.43
}
```
**Mapped to**:
```json
{
  "rsi": 65.43
}
```

---

**Complex Indicator** (MACD):
```json
{
  "valueMACD": 123.45,
  "valueMACDSignal": 120.30,
  "valueMACDHist": 3.15
}
```
**Mapped to**:
```json
{
  "macd": 123.45,
  "signal": 120.30,
  "histogram": 3.15
}
```

---

**Single Value** (EMA):
```json
{
  "value": 45678.90
}
```
**Mapped to**:
```json
{
  "ema": 45678.90
}
```

---

## ApiProperties Value Object

**Location**: `Martingalian\Core\Support\ValueObjects\ApiProperties`
**Purpose**: Type-safe container for API request parameters

**Features**:
- Dot notation for nested properties
- Type validation
- Immutability after set
- Relatable model attachment

**Usage**:
```php
$properties = new ApiProperties;

// Set values
$properties->set('options.symbol', 'BTCUSDT');
$properties->set('options.quantity', '0.001');
$properties->set('relatable', $order); // For logging

// Get values
$symbol = $properties->get('options.symbol'); // "BTCUSDT"
$symbol = $properties->getOr('options.symbol', 'DEFAULT'); // With default

// Check existence
$has = $properties->has('options.symbol'); // true
```

---

## Integration with Jobs

### BaseApiableJob Usage
```php
class PlaceOrderJob extends BaseApiableJob
{
    public function handle()
    {
        // Get mapper for this API system
        $mapper = ApiDataMapperProxy::make($this->apiSystem);

        // Prepare request
        $properties = $mapper->preparePlaceOrderProperties($this->order);

        // Make API request (base job handles this)
        $response = $this->makeRequest('/fapi/v1/order', 'POST', $properties);

        // Resolve response
        $data = $mapper->resolvePlaceOrderResponse($response);

        // Update order with exchange data
        $this->order->update([
            'exchange_order_id' => $data['orderId'],
            'status' => $data['status'],
            'filled_quantity' => $data['executedQty'],
        ]);
    }
}
```

---

## Testing

### Unit Tests
**Location**: `tests/Unit/ApiDataMappers/`
- Request property mapping
- Response transformation
- Price/quantity formatting
- Trading pair parsing

**Example**:
```php
it('maps order to Binance format', function () {
    $order = Order::factory()->create([
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 0.1,
        'price' => 45000,
    ]);

    $mapper = new BinanceApiDataMapper;
    $properties = $mapper->preparePlaceOrderProperties($order);

    expect($properties->get('options.type'))->toBe('LIMIT');
    expect($properties->get('options.side'))->toBe('BUY');
    expect($properties->get('options.quantity'))->toBeString();
});
```

### Integration Tests
**Location**: `tests/Integration/ApiDataMappers/`
- Full request/response cycle
- Multi-exchange compatibility
- Error handling

---

## Common Pitfalls

### String Conversion
**Problem**: Exchanges expect string parameters, not floats.
**Solution**: Always cast to string
```php
// Wrong
$properties->set('options.quantity', 0.001);

// Right
$properties->set('options.quantity', '0.001');
```

### Precision Loss
**Problem**: Float precision errors
**Solution**: Use helper functions
```php
// Wrong
$price = (string) 45678.123456789; // May have precision errors

// Right
$price = api_format_price(45678.123456789, $exchangeSymbol);
```

### Symbol Name Mapping
**Problem**: Inconsistent symbol building across codebase
**Solution**: Always use mapper's `baseWithQuote()` for consistency
```php
// Consistent approach
$symbol = $mapper->baseWithQuote($token, $quote);
// Handles exchange-specific formatting (e.g., Bybit PERP suffix for USDC pairs)
```

---

## Leverage Brackets Synchronization

### Overview
Leverage brackets define position size limits and required margin for different leverage levels. Each exchange provides this data, which must be synced regularly as it changes frequently.

### Architecture

**Parent Orchestrator**:
- `SyncLeverageBracketsJob` (ApiSystem level)
- Routes to exchange-specific child jobs
- Uses parent-child job pattern for Bybit (per-symbol API limitation)

**Exchange-Specific Implementations**:

**Binance** (single API call):
- `ExchangeSymbol\Binance\SyncLeverageBracketsJob`
- Fetches ALL leverage brackets in one API request
- Updates all exchange_symbols in a loop

**Bybit** (per-symbol API calls):
- `ExchangeSymbol\Bybit\SyncLeverageBracketsJob`
- Bybit's `/v5/market/risk-limit` requires `symbol` parameter
- One child job per exchange_symbol
- Parent creates child block with `child_block_uuid`

### Data Structure

Both exchanges normalize to identical JSON structure:
```json
{
  "bracket": 1,
  "initialLeverage": 75,
  "notionalCap": 10000,
  "notionalFloor": 0,
  "maintMarginRatio": 0.005,
  "cum": 0
}
```

**Stored in**: `exchange_symbols.leverage_brackets` (JSON column)

### Bybit Per-Symbol Query

**Problem**: Bybit's API doesn't return all symbols without pagination. When queried without a symbol parameter, it returns ~15 random symbols.

**Solution**: Query each symbol individually using the symbol's token and quote:
```php
// Build symbol string using mapper (handles PERP suffix)
$mapper = $this->exchangeSymbol->apiSystem->apiMapper();
$symbolString = $mapper->baseWithQuote(
    $this->exchangeSymbol->token,
    $this->exchangeSymbol->quote
);
// For USDC: Returns "BNBPERP"
// For USDT: Returns "BNBUSDT"

// Query Bybit API with specific symbol
$properties = $mapper->prepareQueryLeverageBracketsDataProperties(
    $this->exchangeSymbol->apiSystem,
    $symbolString
);
```

### Parent-Child Job Pattern (Bybit)

```php
protected function dispatchBybitChildJobs(): array
{
    // Get all exchange symbols for Bybit
    $exchangeSymbols = ExchangeSymbol::query()
        ->where('api_system_id', $this->apiSystem->id)
        ->get();

    // Generate child block UUID
    $childBlockUuid = $this->uuid();

    // Set on parent step (makes parent wait for children)
    $this->step->update(['child_block_uuid' => $childBlockUuid]);

    $index = 1;
    foreach ($exchangeSymbols as $exchangeSymbol) {
        Step::create([
            'class' => BybitSyncLeverageBracketsJob::class,
            'queue' => 'cronjobs',
            'block_uuid' => $childBlockUuid, // Links to parent
            'index' => $index++,
            'arguments' => ['exchangeSymbolId' => $exchangeSymbol->id],
        ]);
    }

    return ['dispatched' => $exchangeSymbols->count()];
}
```

**Flow**:
1. Parent `SyncLeverageBracketsJob` (Bybit) starts
2. Creates child block UUID
3. Updates self with `child_block_uuid`
4. Creates N child `BybitSyncLeverageBracketsJob` steps with matching `block_uuid`
5. Parent waits for all children to complete
6. Next job in chain (`CheckPriceSpikeAndCooldownJob`) waits for parent

### RefreshDataCommand Integration

**Lifecycle**:
```
RefreshDataCommand
  ↓
WaitSyncSymbolsAndTriggerExchangeSyncsJob
  ↓
[Parallel per exchange]
  ↓
SyncMarketDataJob (Binance) | SyncMarketDataJob (Bybit)
  ↓
SyncLeverageBracketsJob (Binance) | SyncLeverageBracketsJob (Bybit)
  ↓                                   ↓
Single child job (all symbols)     6 child jobs (one per symbol)
  ↓                                   ↓
CheckPriceSpikeAndCooldownJob      CheckPriceSpikeAndCooldownJob
```

### Updates Handling

The system handles leverage bracket updates automatically:
- Jobs run regularly via RefreshDataCommand (crontab)
- Each run completely overwrites the JSON column with fresh API data
- Changes from exchange (new brackets, modified values, removed brackets) are reflected immediately
- Idempotent: Same data overwrites itself with no side effects

**RefreshDataCommand Modes**:

1. **Full Refresh** (`--clean` flag):
   - Truncates all related tables (candles, exchange_symbols, notification_logs)
   - Rebuilds entire dataset from scratch
   - Use for database resets or major schema changes

2. **Incremental Update** (default, no flags):
   - Syncs new symbols added to the `symbols` table (by cmc_id only)
   - Updates existing exchange_symbols with latest API data
   - Corrects any manually modified leverage brackets
   - Creates new exchange_symbols for newly synced symbols
   - Preserves existing data, only updates what changed
   - Use for regular cron runs and continuous operation

**Incremental Update Behavior**:
- New symbols with only `cmc_id` populated are synced from CoinMarketCap API
- SyncExchangeInformationJob creates exchange_symbols for newly synced symbols
- Existing exchange_symbols are updated with latest API data (leverage brackets, min_notional, tick_size, etc.)
- Any corrupted or manually modified data is automatically corrected from exchange APIs
- No data loss - existing exchange_symbols remain intact

### Data Mapper Responsibilities

**Binance** (`MapsLeverageBracketsQuery`):
```php
public function resolveQueryMarketDataResponse(Response $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    // Binance returns array directly
    return $data;
}
```

**Bybit** (`MapsLeverageBracketsQuery`):
```php
public function resolveLeverageBracketsDataResponse(Response $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    // Bybit V5 structure: {result: {list: [...]}}
    $riskLimits = $data['result']['list'] ?? [];

    // Group by symbol and transform to Binance-compatible structure
    $grouped = [];
    foreach ($riskLimits as $risk) {
        $symbol = $risk['symbol'] ?? null;
        if (!$symbol) continue;

        if (!isset($grouped[$symbol])) {
            $grouped[$symbol] = [
                'symbol' => $symbol,
                'brackets' => [],
            ];
        }

        // Transform Bybit format to match Binance
        $grouped[$symbol]['brackets'][] = [
            'bracket' => (int) ($risk['id'] ?? 0),
            'initialLeverage' => (int) ($risk['maxLeverage'] ?? 0),
            'notionalCap' => (float) ($risk['riskLimitValue'] ?? 0),
            'notionalFloor' => 0,
            'maintMarginRatio' => (float) ($risk['maintenanceMargin'] ?? 0),
            'cum' => 0,
        ];
    }

    return array_values($grouped);
}
```

### Filtering Perpetual Contracts

Bybit returns multiple contract types. Filter for perpetual futures only:
```php
->filter(function ($symbolData) {
    return ($symbolData['contractType'] ?? null) === 'LinearPerpetual';
})
```

This excludes:
- Dated futures (e.g., `BTCUSDT-31OCT25`)
- Inverse perpetuals
- Spot pairs
- Options

---

## Future Enhancements
- GraphQL API support
- WebSocket data mapping
- Response caching layer
- Automatic retry with adjusted parameters
- Schema validation (JSON Schema)
- Multi-version API support
- Mapper versioning for API changes
