# Notification System

**Status**: Production

---

## Overview

Unified notification framework built on Laravel's notification system with dual throttling mechanisms (database and cache-based), support for real users and virtual admin, and comprehensive audit logging.

---

## Architecture

```
Notification Triggers
    ↓
NotificationService::send()
    ↓
┌─────────────────┐  ┌──────────────────────┐  ┌─────────────────────────────┐
│ Throttle Check  │→ │ NotificationMessage  │→ │ $user->notify(Alert...)     │
│ (cache or DB)   │  │ Builder::build()     │  │                             │
└─────────────────┘  └──────────────────────┘  └─────────────────────────────┘
    ↓
AlertNotification (via Pushover/Mail)
    ↓
NotificationLogListener (audit trail)
```

---

## Core Components

### NotificationService

Unified entry point for all notifications. Handles throttling, message building, and dispatch.

**Parameters**:
- `user` - Real or virtual admin user
- `canonical` - Notification template identifier
- `referenceData` - Data for template interpolation
- `relatable` - Context model (Account, ApiSystem, etc.)
- `duration` - Throttle duration override
- `cacheKeys` - Cache key data for cache-based throttling

**Returns**: `true` if sent, `false` if throttled/blocked

### NotificationMessageBuilder

Pure function transforming canonicals into user-friendly content using a match statement.

**Output**:
- Severity level
- Title
- Email message
- Pushover message
- Optional action URL and label

**Special Markers** (parsed by Blade template):
- `[COPY]text[/COPY]` → Styled copyable box
- `[CMD]text[/CMD]` → Styled command block

### AlertNotification

Laravel Notification class supporting Pushover and Mail channels.

**Key Characteristics**:
- NOT queued - sent synchronously
- Multi-channel based on user preferences
- Inactive users filtered out
- Severity-based priority (Critical → emergency Pushover)

### NotificationLogListener

Event subscriber creating audit trail entries for all notifications.

**Listens To**:
- `NotificationSent` → creates log with status `delivered`
- `NotificationFailed` → creates log with status `failed`

**Extracts**: canonical, user ID, relatable, recipient, gateway response, raw email content

---

## Throttling Mechanisms

### Database-Based (Default)

When no `cacheKeys` provided:
1. Queries `notification_logs` table
2. Checks for last notification with same canonical + relatable
3. Blocks if within throttle window

**Use Case**: Throttling based on context (one per Account per canonical per time window)

### Cache-Based

When `cacheKeys` provided:
1. Builds cache key from template
2. Uses atomic Redis SETNX
3. First worker wins, others throttled

**Use Case**: Race condition prevention across multiple workers

**Cache Key Format**: `{canonical}-{key}:{value},{key}:{value}`

---

## Virtual Admin User

Accessed via `Martingalian::admin()`. A virtual User instance with:
- Admin email from martingalian config
- Admin Pushover key from martingalian config
- Protected from accidental database save

**Important**: Admin notifications have `user_id = NULL` in notification_logs.

---

## Notification Triggers

### ApiRequestLogObserver
- Triggers on API errors (HTTP 4xx/5xx)
- Uses NotificationHandler to get canonical
- Sends via NotificationService

### ForbiddenHostnameObserver
- Triggers on IP bans and blocks
- Routes to admin (IP banned/rate limited) or user (not whitelisted/account blocked)

### HeartbeatObserver
- Triggers on WebSocket status changes
- Notifies for: connected, disconnected, stale, inactive
- Skips: reconnecting, unknown

### Cronjob Commands
- CheckStaleDataCommand detects stale steps/data
- Sends admin notifications for system issues

---

## Notification Canonicals

### API Error Notifications

| Canonical | Severity | Trigger |
|-----------|----------|---------|
| `server_ip_forbidden` | Critical | IP blocked by exchange |
| `server_rate_limit_exceeded` | Info | Rate limit hit |
| `exchange_symbol_no_taapi_data` | Info | Symbol auto-deactivated |

### System Notifications

| Canonical | Severity | Trigger |
|-----------|----------|---------|
| `stale_dispatched_steps_detected` | Critical | Steps stuck in Dispatched |
| `stale_price_detected` | High | Price data stale |
| `token_delisting` | High | Exchange delisting token |
| `websocket_status_change` | High | WebSocket connection change |

### ForbiddenHostname Notifications

| Canonical | Sent To | Trigger |
|-----------|---------|---------|
| `server_ip_not_whitelisted` | User | IP not in API whitelist |
| `server_ip_rate_limited` | Admin | Temporary rate limit |
| `server_ip_banned` | Admin | Permanent IP ban |
| `server_account_blocked` | User | API key issue |

---

## Severity Levels

| Severity | Pushover Priority | Email Priority |
|----------|-------------------|----------------|
| Critical | Emergency (2) | High |
| High | Normal | High |
| Medium | Normal | Normal |
| Info | Low | Normal |

---

## Database Schema

### notifications table

| Column | Purpose |
|--------|---------|
| `canonical` | Unique identifier |
| `title` | Display title |
| `default_severity` | NotificationSeverity enum |
| `cache_key` | JSON array of required cache key fields |
| `cache_duration` | Default throttle in seconds |
| `is_active` | Per-notification enable/disable toggle |

### notification_logs table

| Column | Purpose |
|--------|---------|
| `notification_id` | FK to notifications |
| `canonical` | Template identifier |
| `user_id` | WHO received (null for admin) |
| `relatable_type/id` | WHAT it's about (polymorphic) |
| `channel` | mail, pushover |
| `status` | delivered, failed |
| `gateway_response` | JSON gateway response |

**Critical Separation**:
- `user_id` = WHO received the notification
- `relatable` = WHAT it's about (context model)

---

## Configuration

### Global Toggle

Disable all notifications via config: `martingalian.notifications_enabled`

### Per-Notification Toggle

Individual notifications can be disabled via `is_active` column.

**Behavior**:
- `is_active = true` (default) → Notification sent normally
- `is_active = false` → Notification blocked
- Global toggle takes precedence

### Supervisor Restart Required

After changing notification config in `.env`, restart `schedule-work` supervisor. Long-running PHP processes cache config in memory.

---

## Troubleshooting

### Notification Not Sent
- Check `notifications_enabled` config
- Check `is_active` column for this canonical
- Check `notification_logs` for throttling

### Relatable Not Saved
- Ensure `relatable` parameter passed to `NotificationService::send()`

### user_id Not NULL for Admin
- Use `Martingalian::admin()` for admin notifications

### Cache Throttling Not Working
- Verify Redis configuration
- Check `notifications.cache_key` has template
