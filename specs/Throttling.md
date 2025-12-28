# API Throttling System

## Overview

Redis-based rate limiting coordination across multiple workers to prevent API throttling and IP bans. Supports both IP-based (REQUEST_WEIGHT) and per-account (ORDER) rate limiting with real-time response header tracking.

---

## Performance Benchmarks

| Exchange | Throughput Achieved | Limit Configuration |
|----------|--------------------|--------------------|
| Bybit | 92 req/sec sustained | 550 req/5s (92% of 600 hard limit) |
| Binance | 5.9 req/sec sustained | 2,040 weight/min (85% of 2,400) |

---

## Architecture

```
BaseApiableJob
    ↓ shouldStartOrThrottle()
BaseExceptionHandler
    ↓ isSafeToMakeRequest()
Throttler (BinanceThrottler, BybitThrottler, etc.)
    ↓ Checks cache
Redis Cache (coordinated across all workers on same IP)
```

---

## Exchange Throttlers

### BinanceThrottler

**Unique Feature**: Dual rate limit system

1. **REQUEST_WEIGHT (IP-based)**: Shared across all workers on same server
2. **ORDERS (UID-based)**: Per trading account, tracked separately

**Configuration**:
| Setting | Default | Purpose |
|---------|---------|---------|
| `safety_threshold` | 0.85 | Stop at 85% of limit |
| `weight_1m` | 2,040 | Weight per minute (85% of 2,400) |
| `weight_10s` | 255 | Weight per 10 seconds (85% of 300) |

**Response Headers Tracked**:
- `X-MBX-USED-WEIGHT-1M` - Weight used in last minute
- `X-MBX-ORDER-COUNT-10S` - Orders in last 10 seconds

### BybitThrottler

**Configuration**:
| Setting | Default | Purpose |
|---------|---------|---------|
| `safety_threshold` | 0.15 | Throttle when <15% remaining |
| `requests_per_window` | 550 | Requests per 5s (92% of 600) |

**Response Headers Tracked**:
- `X-Bapi-Limit-Status` - Requests remaining
- `X-Bapi-Limit` - Total limit

### KrakenThrottler

**Configuration**:
| Setting | Default | Purpose |
|---------|---------|---------|
| `requests_per_window` | 500 | Requests per 10 seconds |
| `safety_threshold` | 0.85 | Stop at 85% of limit |

### Simple Throttlers

TAAPI, CoinMarketCap, and Alternative.me use basic window-based throttling with no IP ban tracking.

---

## Cache Keys

| Pattern | Purpose | TTL |
|---------|---------|-----|
| `{exchange}:{ip}:banned_until` | IP ban state | Variable (from Retry-After) |
| `binance:{ip}:weight:1m` | Weight consumption | 60s |
| `binance:{ip}:uid:{id}:orders:10s` | Per-account orders | 10s |
| `bybit:{ip}:limit:status` | Remaining requests | 5s |

---

## Throttling Flow

### Pre-flight Check

Before each API request:
1. Check if IP is banned
2. Check rate limit proximity (safety threshold)
3. Return delay in seconds (0 = safe to proceed)

### Recording Response Headers

After each successful API request:
1. Extract rate limit headers
2. Store values in Redis cache
3. TTL matches rate limit window

### IP Ban Handling

When IP ban detected (418 Binance, 403 Bybit):
1. Record ban in cache with Retry-After TTL
2. All workers on same IP see ban immediately
3. Workers on different IPs unaffected

---

## Worker Coordination

### Shared Limits (Same IP)

All workers on same server share REQUEST_WEIGHT:
- Worker 1 makes request → weight cached
- Worker 2 checks cache → sees current weight
- Worker 25 checks cache → may be throttled if near limit

### Independent Limits (Per Account)

Each account has separate ORDER limits:
- Account A: 15/1020 orders
- Account B: 8/1020 orders

### IP Ban Coordination

IP ban affects all workers on same server:
- Worker 15 gets banned → records in cache
- All 25 workers see ban → throttle for duration
- Other servers (different IPs) continue normally

---

## Capacity Planning

### Single Server (1 IP)

| Exchange | Capacity |
|----------|----------|
| Binance (REST polling) | ~90 accounts |
| Binance (WebSocket) | ~150 accounts |
| Bybit | ~200-300 accounts |

### Multi-Server (5 IPs)

| Exchange | Capacity |
|----------|----------|
| Binance (REST polling) | ~450 accounts |
| Binance (WebSocket) | ~750 accounts |
| Bybit | ~1000+ accounts |

**Key insight**: Adding workers on same IP doesn't help. Adding servers (more IPs) does.

---

## Configuration

### Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `BINANCE_THROTTLER_SAFETY_THRESHOLD` | 0.85 | % of limit to stop at |
| `BYBIT_THROTTLER_REQUESTS_PER_WINDOW` | 550 | Requests per 5s |
| `KRAKEN_THROTTLER_REQUESTS_PER_WINDOW` | 500 | Requests per 10s |

### Horizon Workers

Recommended per CX22 server (4GB RAM):
- 25 total workers
- ~80MB per worker
- 3GB for workers, 1GB headroom
- 75 database connections (within MySQL 151 limit)

---

## Scaling Strategy

### REST Polling vs WebSocket

| Approach | Weight per Account/Min | Accounts per 5 IPs |
|----------|------------------------|--------------------|
| REST Polling (30s) | ~22 weight | ~370 |
| WebSocket | ~5 weight | ~750 |

### Adding Capacity

1. **Add more servers (IPs)** - Linear capacity increase
2. **Use WebSocket** - Reduces weight by 75%
3. **Optimize polling interval** - Reduce weight per account
