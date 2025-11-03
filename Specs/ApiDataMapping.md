# API Data Mapping System

## Overview
Exchange-agnostic data transformation layer that converts between internal data models and exchange-specific API formats. Provides unified interface for interacting with multiple exchanges (Binance, Bybit) and data providers (TAAPI, CoinMarketCap) while handling format differences transparently.

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
// Build trading pair
$pair = $mapper->baseWithQuote('BTC', 'USDT'); // Returns: "BTCUSDT"

// Handle special cases via BaseAssetMapper
// 1000PEPEUSDT → PEPEUSDT (exchange uses 1000x multiplier)

// Parse trading pair
$parts = $mapper->identifyBaseAndQuote('BTCUSDT');
// Returns: ['base' => 'BTC', 'quote' => 'USDT']
```

---

### BybitApiDataMapper
**Location**: `Martingalian\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper`
**Purpose**: Maps data to/from Bybit V5 API format

**Similar Structure to Binance**:
- Trait-based mapping for each API endpoint
- Direction/side type conversions
- Trading pair formatting

**Differences from Binance**:
- V5 API uses different parameter names
- Category parameter required (linear, inverse, spot)
- Different response structure
- Different error codes

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
    $data = json_decode((string) $response->getBody(), true);

    return array_map(function ($position) {
        return [
            'symbol' => $position['symbol'],
            'side' => $position['positionSide'], // LONG/SHORT
            'quantity' => (float) $position['positionAmt'],
            'entry_price' => (float) $position['entryPrice'],
            'unrealized_pnl' => (float) $position['unRealizedProfit'],
            'leverage' => (int) $position['leverage'],
            'liquidation_price' => (float) $position['liquidationPrice'],
            'margin_type' => $position['marginType'], // isolated/cross
        ];
    }, $data);
}
```

---

### BaseAssetMapper Pattern
Handles exchange-specific symbol naming differences.

**Problem**: Exchanges use different naming conventions for the same asset.
- Binance: `1000PEPEUSDT` (1000x multiplier for low-priced coins)
- Our system: `PEPEUSDT` (canonical name)

**Solution**: BaseAssetMapper model stores mappings.

**Schema**:
- `api_system_id` - Exchange (Binance, Bybit)
- `symbol_id` - Our canonical symbol (PEPE)
- `exchange_base_asset` - Exchange's name (1000PEPE)
- `canonical_base_asset` - Our name (PEPE)

**Usage in Mapper**:
```php
public function baseWithQuote(string $token, string $quote): string
{
    $apiSystem = ApiSystem::firstWhere('canonical', 'binance');

    // Check for special mapping
    $mappedToken = BaseAssetMapper::where('api_system_id', $apiSystem->id)
        ->where('symbol_token', $token)
        ->first()
        ->exchange_token ?? $token;

    return $mappedToken . $quote; // "1000PEPEUSDT"
}
```

**Examples**:
| Canonical | Binance | Bybit |
|-----------|---------|-------|
| PEPE | 1000PEPEUSDT | PEPEUSDT |
| SHIB | 1000SHIBUSDT | SHIBUSDT |
| FLOKI | 1000FLOKIUSDT | FLOKIUSDT |

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
- BaseAssetMapper lookups
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
**Problem**: Forgetting BaseAssetMapper
**Solution**: Always use mapper's `baseWithQuote()`
```php
// Wrong
$symbol = $token . $quote; // May miss 1000PEPE mapping

// Right
$symbol = $mapper->baseWithQuote($token, $quote);
```

---

## Future Enhancements
- GraphQL API support
- WebSocket data mapping
- Response caching layer
- Automatic retry with adjusted parameters
- Schema validation (JSON Schema)
- Multi-version API support
- Mapper versioning for API changes
