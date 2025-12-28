# API Clients

## Overview

External API integration layer supporting cryptocurrency exchanges and market data providers. All clients include exception handling, rate limiting coordination, and automatic request logging.

---

## Supported Services

### Exchanges
| Exchange | Type | Authentication | Key Features |
|----------|------|----------------|--------------|
| Binance | Futures | HMAC | Weight-based rate limiting, recvWindow |
| Bybit | V5 Unified | HMAC | 600 req/5s limit |
| Kraken | Futures | HMAC-SHA512 | Nonce-based signing |
| KuCoin | Futures | HMAC-SHA256 | Encrypted passphrase |
| BitGet | Futures V2 | HMAC-SHA256 | Plain text passphrase |

### Data Providers
| Provider | Purpose | Authentication |
|----------|---------|----------------|
| TAAPI | Technical indicators | Secret key |
| CoinMarketCap | Market data, rankings | API key header |
| Alternative.me | Fear & Greed Index | None (public) |

---

## Architecture

### BaseApiClient

Abstract base class providing:
- HTTP client via Guzzle
- Automatic request logging to `api_request_logs` table
- Duration tracking
- Response header recording for rate limiting

### Exception Handlers

Each exchange has a dedicated exception handler that:
- Classifies HTTP/vendor error codes
- Detects rate limits, auth errors, IP bans
- Coordinates with throttlers for rate limit management
- Provides pre-flight safety checks

### Throttlers

Redis-based rate limit coordination:
- Track usage across multiple workers on same IP
- Coordinate IP bans
- Pre-flight safety checks before making requests

---

## Exchange Details

### Binance

**Authentication**: HMAC signature with timestamp

**Rate Limits**:
- REQUEST_WEIGHT: 2400/minute (IP-based)
- ORDERS: 1200/minute (per account)

**Key Errors**:
| Code | Meaning |
|------|---------|
| -1003 | Too many requests |
| -1021 | Timestamp outside recvWindow |
| -2015 | Invalid API key/IP/permissions |
| 418 | IP banned |

### Bybit

**Authentication**: API key with HMAC signature

**Rate Limits**: 600 requests per 5 seconds per IP

**Key Errors**:
| Code | Meaning |
|------|---------|
| 10003 | Invalid API key |
| 10004 | IP not whitelisted |
| 10006 | Too many requests |

### Kraken

**Authentication**: HMAC-SHA512 with nonce

**Important**: Signature endpoint path must NOT include `/derivatives` prefix

**Rate Limits**: ~500 requests per 10 seconds

**Key Errors**: HTTP 401 (auth), 403 (IP blocked), 429 (rate limit)

### KuCoin

**Authentication**: HMAC-SHA256 with **encrypted passphrase**

**Rate Limits**: 500 requests per 10 seconds

**Key Errors**:
| Code | Meaning |
|------|---------|
| 400100 | Invalid API key |
| 429000 | Rate limit exceeded |

### BitGet

**Authentication**: HMAC-SHA256 with **plain text passphrase**

**Rate Limits**: 6000 requests per minute

**Key Errors**:
| Code | Meaning |
|------|---------|
| 40014 | Invalid API key |
| 45001 | System maintenance |

---

## Klines (Candlestick Data)

All exchanges support fetching OHLCV candlestick data via REST API.

### Exchange Differences

| Exchange | Interval Format | Timestamp Unit | Symbol Format |
|----------|-----------------|----------------|---------------|
| Binance | `5m`, `1h` | milliseconds | `BTCUSDT` |
| Bybit | `5` (number only) | milliseconds | `BTCUSDT` |
| KuCoin | `5` (minutes int) | milliseconds | `XBTUSDTM` |
| BitGet | `5m`, `1h` | milliseconds | `BTCUSDT` |
| Kraken | `5m`, `1h` | **seconds** | `PF_XBTUSD` |

---

## Notification Integration

**Important**: Exception handlers do NOT send notifications directly.

All API error notifications originate from:
- `ApiRequestLogObserver` - when error is logged to database
- `ForbiddenHostnameObserver` - when IP ban is created

---

## Debugging

### Test API Connectivity Command

Tests signed endpoints to verify credentials work.

**Options**:
- `--account={id}` - Test specific account credentials
- `--admin --canonical={exchange}` - Test admin credentials

**Displays**: Masked credentials, success/failure with helpful hints for common errors.

---

## Configuration

### Environment Variables

| Exchange | Required Variables |
|----------|-------------------|
| Binance | `BINANCE_API_KEY`, `BINANCE_API_SECRET` |
| Bybit | `BYBIT_API_KEY`, `BYBIT_API_SECRET` |
| Kraken | `KRAKEN_API_KEY`, `KRAKEN_PRIVATE_KEY` |
| KuCoin | `KUCOIN_API_KEY`, `KUCOIN_API_SECRET`, `KUCOIN_PASSPHRASE` |
| BitGet | `BITGET_API_KEY`, `BITGET_API_SECRET`, `BITGET_PASSPHRASE` |

---

## Common Issues

| Issue | Symptoms | Solution |
|-------|----------|----------|
| IP Not Whitelisted | 451 (Binance), 10004 (Bybit) | Add server IP to exchange whitelist |
| Rate Limit | 429 errors | Implement backoff, reduce frequency |
| Invalid Credentials | 401 errors | Verify API key/secret/passphrase |
| Timestamp Errors | -1021 (Binance) | Sync server time via NTP |
