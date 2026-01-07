# API Data Mapping System

## Overview

Exchange-agnostic data transformation layer that converts between internal data models and exchange-specific API formats. Provides unified interface for interacting with multiple exchanges (Binance, Bybit, Kraken, BitGet, KuCoin) and data providers (TAAPI, CoinMarketCap) while handling format differences transparently.

---

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

---

## Core Components

### BaseDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Abstracts\BaseDataMapper` |
| Purpose | Abstract base class for all data mappers |
| Features | Common validation logic, template for transformations, standard interface |
| Usage | Extended by exchange-specific mappers |

---

## Exchange Data Mappers

### BinanceApiDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper` |
| Purpose | Maps data to/from Binance Futures API format |

**Available Mapping Traits**:

| Trait | Purpose |
|-------|---------|
| MapsPlaceOrder | Order placement |
| MapsOrderQuery | Order status query |
| MapsOrderCancel | Order cancellation |
| MapsOrderModify | Order modification |
| MapsOpenOrdersQuery | Open orders query |
| MapsCancelOrders | Batch order cancellation |
| MapsPositionsQuery | Positions query |
| MapsAccountQuery | Account info query |
| MapsAccountBalanceQuery | Balance query |
| MapsAccountQueryTrades | Trade history query |
| MapsExchangeInformationQuery | Symbol metadata |
| MapsLeverageBracketsQuery | Leverage tiers |
| MapsMarkPriceQuery | Current price |
| MapsSymbolMarginType | Margin type (ISOLATED/CROSS) |
| MapsTokenLeverageRatios | Leverage settings |
| MapsKlinesQuery | Candlestick/OHLCV data |

**Direction/Side Mapping**:

| Method | Returns |
|--------|---------|
| `long()` | `'LONG'` |
| `short()` | `'SHORT'` |
| `directionType(canonical)` | Maps internal direction to exchange format |
| `sideType(canonical)` | Maps internal side to BUY/SELL |

**Trading Pair Format**: Simple concatenation (`BTC` + `USDT` = `BTCUSDT`)

---

### BybitApiDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper` |
| Purpose | Maps data to/from Bybit V5 API format |

**Key Differences from Binance**:

| Aspect | Binance | Bybit |
|--------|---------|-------|
| Category Parameter | Not required | Required (linear, inverse, spot) |
| Side Format | `BUY`/`SELL` | `Buy`/`Sell` (capitalized) |
| Order Type Parameter | `type` | `orderType` |
| Quantity Parameter | `quantity` | `qty` |
| Position Side | `positionSide` | `positionIdx` (0=one-way, 1=long, 2=short) |

**USDC Perpetual Symbol Naming**:

| Quote | Format | Example |
|-------|--------|---------|
| USDT | Standard | `BNBUSDT`, `SOLUSDT` |
| USDC | PERP suffix | `BNBPERP`, `SOLPERP` |

The mapper handles PERP suffix automatically when building/parsing symbols.

---

### KrakenApiDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\Kraken\KrakenApiDataMapper` |
| Purpose | Maps data to/from Kraken Futures API format |

**Direction/Side Mapping**:

| Method | Returns |
|--------|---------|
| `long()` | `'long'` (lowercase) |
| `short()` | `'short'` (lowercase) |

**Trading Pair Format**: Prefixed format with special naming

| Internal | Kraken Format |
|----------|---------------|
| BTC/USD | `PI_XBTUSD` (perpetual inverse) |
| BTC/USD | `PF_XBTUSD` (perpetual fixed-margin) |

---

### BitgetApiDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper` |
| Purpose | Maps data to/from BitGet Futures V2 API format |

**Key Differences from Binance**:

| Aspect | Difference |
|--------|------------|
| Product Type | Requires `productType` parameter (e.g., `USDT-FUTURES`) |
| Klines Interval | Uses `granularity` instead of `interval` |
| Response Structure | Nested `data` array in responses |

**Trading Pair Format**: Simple concatenation (`BTCUSDT`)

---

### KucoinApiDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\Kucoin\KucoinApiDataMapper` |
| Purpose | Maps data to/from KuCoin Futures API format |

**Key Differences from Binance**:

| Aspect | Difference |
|--------|------------|
| Symbol Format | `M` suffix for perpetuals (e.g., `XBTUSDTM`) |
| Bitcoin Symbol | Uses `XBT` instead of `BTC` |
| Klines Interval | Uses `granularity` in minutes (integer) |
| Timestamps | Some endpoints use seconds instead of milliseconds |

**Trading Pair Format**: XBT naming with M suffix (`BTC` + `USDT` = `XBTUSDTM`)

---

### TaapiApiDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\Taapi\TaapiApiDataMapper` |
| Purpose | Maps data to/from TAAPI.io indicator API |

**Features**:
- Prepares indicator queries (RSI, MACD, EMA, etc.)
- Resolves indicator responses into IndicatorHistory format
- Handles batch indicator requests
- Parses complex indicator structures (MACD has multiple values)

---

### CoinmarketCapDataMapper

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ApiDataMappers\CoinmarketCap\CoinmarketCapDataMapper` |
| Purpose | Maps data from CoinMarketCap API |

**Features**: Cryptocurrency listings, price quotes, market data, historical data

---

### ApiDataMapperProxy

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\Proxies\ApiDataMapperProxy` |
| Purpose | Routes requests to correct mapper based on API system |
| Pattern | Factory pattern with `make(exchange)` method |

---

## Mapping Patterns

### Request Mapping Pattern

Each API endpoint has a `prepareXXXProperties()` method that transforms internal models to API format.

**Method Signature**: `prepareXXXProperties(Model $model): ApiProperties`

**Responsibilities**:
1. Set relatable model for logging
2. Map model attributes to API parameters
3. Handle order-type specific parameters
4. Format prices and quantities to exchange precision

---

### Response Mapping Pattern

Each API endpoint has a `resolveXXXResponse()` method that transforms API response to normalized format.

**Method Signature**: `resolveXXXResponse(Response $response): array`

**Responsibilities**:
1. Parse JSON response body
2. Transform exchange-specific fields to internal format
3. Normalize symbol format using `identifyBaseAndQuote()`
4. Filter out invalid/empty records

**CRITICAL**: All response mappers must normalize symbols using `identifyBaseAndQuote()`. This ensures consistency with `Position::parsed_trading_pair` for symbol comparison operations.

---

## Klines Query Normalization

### Exchange Differences

| Exchange | Interval Format | Timestamp Unit | Response Structure |
|----------|-----------------|----------------|-------------------|
| Binance | `"5m"` | milliseconds | Array of arrays |
| Bybit | `"5"` (number only) | milliseconds | `{result: {list: [...]}}` |
| KuCoin | `5` (integer minutes) | seconds | `{data: [...]}` |
| BitGet | `"5m"` | milliseconds | `{data: [...]}` |
| Kraken | `"5m"` (in URL path) | seconds | `{candles: [...]}` |

### Supported Timeframes by Exchange

**IMPORTANT**: Not all exchanges support all timeframes. Attempting unsupported timeframes returns errors.

| Timeframe | Binance | Bybit | Kraken | KuCoin | BitGet |
|-----------|---------|-------|--------|--------|--------|
| 1m | ✓ | ✓ | ✓ | ✓ | ✓ |
| 5m | ✓ | ✓ | ✓ | ✓ | ✓ |
| 15m | ✓ | ✓ | ✓ | ✓ | ✓ |
| 30m | ✓ | ✓ | ✓ | ✓ | ✓ |
| 1h | ✓ | ✓ | ✓ | ✓ | ✓ |
| 4h | ✓ | ✓ | ✓ | ✓ | ✓ |
| **6h** | ✓ | ✓ | **✗** | ✓ | ✓ |
| 12h | ✓ | ✓ | ✓ | ✓ | ✓ |
| 1d | ✓ | ✓ | ✓ | ✓ | ✓ |
| 1w | ✓ | ✓ | ✓ | ✓ | ✓ |

**Kraken Limitation**: Kraken Futures API does not support `6h` timeframe. Returns "Invalid resolution" error.

### Timeframe Storage Architecture

Timeframes are stored **per-exchange** in the `api_systems.timeframes` column (JSON array). This replaces the previous global `trade_configuration.indicator_timeframes` setting.

**Database**: `api_systems.timeframes`
- Type: JSON array of strings
- Example: `["5m", "1h", "4h", "12h", "1d"]`
- NULL for non-exchanges (taapi, coinmarketcap, alternativeme)

**Current Exchange Timeframes**:

| Exchange | Timeframes |
|----------|------------|
| Binance | 5m, 1h, 4h, 12h, 1d |
| Bybit | 5m, 1h, 4h, 12h, 1d |
| Kraken | 5m, 1h, 4h, 12h, 1d |
| KuCoin | 5m, 1h, 4h, 12h, 1d |
| BitGet | 5m, 1h, 4h, 12h, 1d |

**Accessing Timeframes**:
```php
$exchange = ApiSystem::canonical('binance');
$timeframes = $exchange->timeframes; // ['5m', '15m', ...]
```

### Normalized Output

All klines responses are normalized to:

| Field | Type | Description |
|-------|------|-------------|
| timestamp | integer | Unix milliseconds |
| open | string | Opening price |
| high | string | High price |
| low | string | Low price |
| close | string | Closing price |
| volume | string | Trading volume |

---

## Data Formatting

### Price Formatting

**Function**: `api_format_price(float $price, ExchangeSymbol $symbol): string`

| Feature | Description |
|---------|-------------|
| Precision | Respects `price_precision` from exchange_symbols |
| Tick Size | Rounds to valid tick_size increments |
| Output | String format for API compatibility |

### Quantity Formatting

**Function**: `api_format_quantity(float $quantity, ExchangeSymbol $symbol): string`

| Feature | Description |
|---------|-------------|
| Precision | Respects `quantity_precision` from exchange_symbols |
| Output | String format for API compatibility |

### Timestamp Formatting

| Exchange | Unit | Format |
|----------|------|--------|
| Binance | milliseconds | Unix epoch × 1000 |
| Bybit | milliseconds | Unix epoch × 1000 |
| Kraken | seconds | Unix epoch |
| KuCoin | seconds (some endpoints) | Unix epoch |

---

## Order Placement Differences

### Request Format Comparison

| Field | Binance | Bybit V5 |
|-------|---------|----------|
| Symbol | `symbol: "BTCUSDT"` | `symbol: "BTCUSDT"` |
| Side | `side: "BUY"` | `side: "Buy"` |
| Type | `type: "LIMIT"` | `orderType: "Limit"` |
| Quantity | `quantity: "0.001"` | `qty: "0.001"` |
| Position | `positionSide: "LONG"` | `positionIdx: 1` |
| Category | Not required | `category: "linear"` |

---

## Position Query Normalization

### Field Mapping

| Internal Field | Binance | Bybit |
|----------------|---------|-------|
| symbol | `symbol` | `symbol` |
| quantity | `positionAmt` | `size` |
| entry_price | `entryPrice` | `avgPrice` |
| unrealized_pnl | `unRealizedProfit` | `unrealisedPnl` |
| liquidation_price | `liquidationPrice` | `liqPrice` |
| margin_type | `marginType` | `tradeMode` |
| position_side | `positionSide` | `positionIdx` |

### Response Structure

| Exchange | Structure |
|----------|-----------|
| Binance | Array directly |
| Bybit | Nested: `{result: {list: [...]}}` |

---

## Indicator Data Mapping

### TAAPI Response Structures

| Indicator | Response Fields | Mapped Fields |
|-----------|-----------------|---------------|
| RSI | `value` | `rsi` |
| EMA | `value` | `ema` |
| MACD | `valueMACD`, `valueMACDSignal`, `valueMACDHist` | `macd`, `signal`, `histogram` |

---

## ApiProperties Value Object

| Aspect | Details |
|--------|---------|
| Location | `Martingalian\Core\Support\ValueObjects\ApiProperties` |
| Purpose | Type-safe container for API request parameters |

**Features**:
- Dot notation for nested properties
- Type validation
- Relatable model attachment for logging
- Getter methods with defaults

---

## Exchange-Specific Minimum Order Data

### Purpose

Exchanges have different ways of expressing minimum order requirements. Some provide `min_notional` directly (Binance), others require calculation from component values.

### Exchange Approaches

| Exchange | Approach | Fields Available |
|----------|----------|------------------|
| **Binance** | Direct `minNotional` | `min_notional` stored directly |
| **Bybit** | Direct `minNotional` | `min_notional` stored directly |
| **BitGet** | Direct `minNotional` | `min_notional` stored directly |
| **Kraken** | Contract-based | `contractSize` (always 1) |
| **KuCoin** | Lot + Multiplier | `lotSize`, `multiplier` |

### Exchange-Specific Columns

For exchanges without direct `min_notional`, we store component values:

| Column | Exchange | Description |
|--------|----------|-------------|
| `kraken_min_order_size` | Kraken | Contract size (minimum order = 1 contract) |
| `kucoin_lot_size` | KuCoin | Minimum contract increment |
| `kucoin_multiplier` | KuCoin | Contract value multiplier |

### Calculating Minimum Order Value

**Binance/Bybit/BitGet**: Use `min_notional` directly.

**Kraken**: Minimum order = `kraken_min_order_size` contracts (typically 1). Value = contracts × current price.

**KuCoin**: Minimum order value = `kucoin_lot_size` × `kucoin_multiplier` × current price.

### Data Population

These fields are populated during `refresh-exchange-symbols` command execution via:
1. `UpsertExchangeSymbolsFromExchangeJob` fetches symbol metadata
2. Exchange-specific mappers extract relevant fields
3. Values stored on `exchange_symbols` table

---

## Leverage Brackets Synchronization

### Architecture

| Component | Description |
|-----------|-------------|
| Parent Orchestrator | `SyncLeverageBracketsJob` (ApiSystem level) |
| Binance | Single API call for all symbols |
| Bybit | Per-symbol API calls with parent-child job pattern |

### Data Structure

All exchanges normalize to identical JSON structure stored in `exchange_symbols.leverage_brackets`:

| Field | Type | Description |
|-------|------|-------------|
| bracket | integer | Tier number |
| initialLeverage | integer | Max leverage for bracket |
| notionalCap | float | Max position size |
| notionalFloor | float | Min position size |
| maintMarginRatio | float | Maintenance margin % |
| cum | float | Cumulative value |

### Bybit Per-Symbol Query

**Problem**: Bybit's API returns ~15 random symbols without a symbol parameter.

**Solution**: Query each symbol individually using parent-child job pattern with `child_block_uuid`.

### RefreshDataCommand Integration

| Mode | Description |
|------|-------------|
| Full Refresh (`--clean`) | Truncates tables, rebuilds entire dataset |
| Incremental Update (default) | Syncs new symbols, updates existing data |

### Perpetual Contract Filtering

Bybit returns multiple contract types. Filter for perpetual futures only by checking `contractType === 'LinearPerpetual'`.

---

## Common Pitfalls

### String Conversion

| Issue | Solution |
|-------|----------|
| Exchanges expect string parameters | Always cast numeric values to string |

### Precision Loss

| Issue | Solution |
|-------|----------|
| Float precision errors | Use `api_format_price()` and `api_format_quantity()` helpers |

### Symbol Name Mapping

| Issue | Solution |
|-------|----------|
| Inconsistent symbol building | Always use mapper's `baseWithQuote()` for consistency |

---

## Testing

### Unit Tests

**Location**: `tests/Unit/ApiDataMappers/`

**Coverage**:
- Request property mapping
- Response transformation
- Price/quantity formatting
- Trading pair parsing

### Integration Tests

**Location**: `tests/Integration/ApiDataMappers/`

**Coverage**:
- Full request/response cycle
- Multi-exchange compatibility
- Error handling

---

## Future Enhancements

- GraphQL API support
- WebSocket data mapping
- Response caching layer
- Automatic retry with adjusted parameters
- Schema validation (JSON Schema)
- Multi-version API support
- Mapper versioning for API changes
