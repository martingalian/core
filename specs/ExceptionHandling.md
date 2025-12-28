# Exception Handling System

## Overview

Comprehensive exception handling for API interactions, job execution, and error recovery. Provides unified handling across multiple exchanges with intelligent retry logic, throttling, and notification routing.

---

## Architecture

```
API Request/Job Execution
    ↓
Exception Thrown
    ↓
ExceptionParser analyzes exception
    ↓
BaseExceptionHandler classifies error
    ↓
Retry Logic OR Permanent Failure
    ↓
Notification (if warranted)
    ↓
Job Rescheduled OR Marked Failed
```

---

## Core Components

### ExceptionParser

Extracts structured error information from any Throwable:
- Exception class and message
- HTTP status code
- Vendor-specific error codes
- Friendly error messages for notifications
- Source file and line number

### BaseExceptionHandler

Abstract base class for API-specific handlers. Uses factory pattern for creation.

**Key Methods**:
- `isRateLimited(Throwable)` - Detect rate limit errors
- `isForbidden(Throwable)` - Detect auth/permission errors
- `isRecvWindowMismatch(Throwable)` - Detect timestamp errors
- `rateLimitUntil(RequestException)` - Calculate retry time
- `isCurrentlyBanned()` - Check IP ban status
- `isSafeToMakeRequest()` - Pre-flight safety check
- `recordIpBan(int)` - Record IP ban

**Error Classification Arrays**:
- `ignorableHttpCodes` - Safe to ignore
- `retryableHttpCodes` - Temporary, safe to retry
- `forbiddenHttpCodes` - Auth errors (don't retry)
- `rateLimitedHttpCodes` - Rate limits (backoff and retry)
- `accountStatusCodes` - Critical account errors

---

## Exchange Handlers

### BinanceExceptionHandler

**Specialties**:
- Parses Retry-After header
- Handles X-MBX-USED-WEIGHT headers
- Distinguishes IP bans (418) from rate limits (429)
- Handles recvWindow mismatches

**Key Error Codes**:
| Code | Meaning | Action |
|------|---------|--------|
| -1003 | Too many requests | Backoff |
| -1021 | recvWindow mismatch | Sync & retry |
| -2015 | Invalid credentials | Investigate |
| -2018/-2019 | Insufficient balance | Notify |

### BybitExceptionHandler

**Specialties**:
- Clearer error code separation
- Handles Bybit-specific rate limit headers

**Key Error Codes**:
| Code | Meaning | Action |
|------|---------|--------|
| 10001 | Rate limit | Backoff |
| 10003 | Invalid API key | Disable |
| 10010 | IP not whitelisted | Notify |

### KrakenExceptionHandler

**Specialties**:
- HTTP status code based classification
- Handles Retry-After header

**Key HTTP Codes**:
| Code | Meaning | Action |
|------|---------|--------|
| 401 | Auth failed | Disable account |
| 403 | IP blocked | Notify |
| 429 | Rate limit | Backoff |

### KucoinExceptionHandler

**Specialties**:
- HTTP and vendor code classification
- Handles string format vendor codes

**Key Vendor Codes**:
| Code | Meaning | Action |
|------|---------|--------|
| 400100 | Invalid API key | Disable |
| 429000 | Rate limit | Backoff |

### BitgetExceptionHandler

**Specialties**:
- HTTP and vendor code classification
- Handles maintenance and system errors

**Key Vendor Codes**:
| Code | Meaning | Action |
|------|---------|--------|
| 40014 | Invalid API key | Disable |
| 45001 | System maintenance | Retry with backoff |

### TaapiExceptionHandler

**Specialties**:
- 15-second window rate limiting
- Conditional ignore for HTTP 400 (except plan limit errors)

**Plan Limit Handling**:
- Plan limit errors should NOT be ignored
- Job should FAIL to alert about configuration issues

---

## Custom Exceptions

| Exception | Purpose | Behavior |
|-----------|---------|----------|
| `JustEndException` | End without action | No retry, no notification |
| `JustResolveException` | Soft completion | Mark resolved, no failure |
| `NonNotifiableException` | Suppress notification | Fail without notifying |
| `MaxRetriesReachedException` | Exhausted retries | Notify and fail |

---

## Retry Patterns

### Pattern 1: Immediate Retry (Transient Errors)

**When**: Network glitches, temporary unavailability (503, 504)
**Strategy**: Retry up to 3 times with exponential backoff

### Pattern 2: Delayed Retry (Rate Limits)

**When**: Rate limit errors (429, 418)
**Strategy**: Wait until rate limit resets

### Pattern 3: No Retry (Permanent Errors)

**When**: Auth errors, invalid credentials
**Strategy**: Fail immediately, notify, disable account if needed

### Pattern 4: Clock Sync Retry (Timestamp Errors)

**When**: recvWindow mismatch
**Strategy**: Sync clock with exchange server, retry

---

## Job Integration

### BaseQueueableJob

Provides centralized exception handling:
- Routes exceptions through handler
- Applies retry logic
- Records errors to step

### BaseApiableJob

Adds API-specific handling:
- Pre-flight safety checks via `shouldStartOrThrottle()`
- Response header recording
- IP ban coordination
- Model caching to prevent duplicate operations

---

## Notification Integration

Exception handlers classify errors but do NOT send notifications directly.

**Notifications are sent by**:
- ApiRequestLogObserver (when API request is logged)
- ForbiddenHostnameObserver (when IP ban is created)

**Throttle Rules**:
- Rate limit notifications: 30 min throttle
- Connection failures: 15 min throttle
- Account issues: 15 min throttle

---

## Error Code Reference

### Binance

| Code | Meaning | Category |
|------|---------|----------|
| -1000 | Unknown error | Retry |
| -1003 | Too many requests | Rate Limit |
| -1021 | recvWindow mismatch | Sync & Retry |
| -2015 | Invalid API key/IP | Ambiguous |
| -2018 | Insufficient balance | Notify |
| -2023 | In liquidation | Disable |
| -4046 | No margin change needed | Ignore |

### Bybit

| Code | Meaning | Category |
|------|---------|----------|
| 10001 | Rate limit | Rate Limit |
| 10003 | Invalid API key | Disable |
| 10004 | Invalid signature | Disable |
| 10010 | IP not whitelisted | Notify |
| 110007 | Insufficient balance | Notify |

---

## Configuration

### Exception Settings

| Setting | Purpose |
|---------|---------|
| `max_retries` | Maximum retry attempts |
| `backoff_strategy` | linear or exponential |
| `base_backoff_seconds` | Initial backoff duration |
| `max_backoff_seconds` | Maximum backoff cap |
| `notify_on_max_retries` | Send notification on exhaustion |
| `critical_error_codes` | Codes requiring immediate action |
