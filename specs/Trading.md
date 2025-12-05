# Trading Algorithm Specification

> This document describes the position opening and trading execution system for the Martingalian crypto trading bot.

---

## Overview

Martingalian is a **martingale-based crypto trading bot** with a substantial risk-management strategy. The objective is to make profit using a very small percentage per trade via a **"laddered" strategy**.

The bot works with **scheduled cron jobs** that:
1. Open positions (long and short) based on market conditions
2. Maintain balanced and controlled position states
3. Regularly synchronize data between the system and exchanges

---

## Trade Structure

Each trade consists of the following order configuration:

| Order Type | Quantity | Purpose |
|------------|----------|---------|
| **Profit Order** | 1 | Take-profit limit order to close position at target profit |
| **Limit Orders** | X | Laddered entry orders (martingale averaging down/up) |
| **Market Order** | 1 | Initial entry order to open the position |
| **Stop-Market Order** | 1 | Stop-loss protection to limit downside risk |

---

## Configuration Sources

Trade configuration is distributed across three tables:

### 1. `martingalian` Table (Global Settings)

| Column | Type | Description |
|--------|------|-------------|
| `allow_opening_positions` | boolean | Global kill switch - if false, no new positions can be opened |
| `is_cooling_down` | boolean | Circuit breaker - pauses new position opening during cooldown |
| `binance_api_key` | encrypted | Global Binance API credentials |
| `binance_api_secret` | encrypted | Global Binance API credentials |
| `bybit_api_key` | encrypted | Global Bybit API credentials |
| `bybit_api_secret` | encrypted | Global Bybit API credentials |
| `taapi_secret` | encrypted | TAAPI indicator service credentials |

### 2. `accounts` Table (Per-Account Settings)

| Column | Type | Description |
|--------|------|-------------|
| **Identity** |||
| `uuid` | char | Unique identifier for the account |
| `name` | varchar | Human-readable account name |
| `user_id` | bigint | Owner of this account |
| `api_system_id` | bigint | Exchange system (Binance/Bybit) |
| `trade_configuration_id` | bigint | Link to trade configuration profile |
| **Quotes** |||
| `portfolio_quote_id` | bigint | Quote currency for portfolio valuation |
| `trading_quote_id` | bigint | Quote currency for trading (e.g., USDT) |
| **Margin & Leverage** |||
| `margin` | decimal | Available margin for trading |
| `position_leverage_long` | int | Leverage multiplier for long positions |
| `position_leverage_short` | int | Leverage multiplier for short positions |
| **Position Limits** |||
| `total_positions_long` | int | Maximum number of concurrent long positions |
| `total_positions_short` | int | Maximum number of concurrent short positions |
| **Order Configuration** |||
| `market_order_margin_percentage_long` | decimal | % of margin used for long market orders |
| `market_order_margin_percentage_short` | decimal | % of margin used for short market orders |
| `profit_percentage` | decimal | Target profit % for take-profit orders |
| `stop_market_initial_percentage` | decimal | Initial stop-loss distance % |
| `stop_market_wait_minutes` | int | Minutes to wait before placing stop-market order |
| **Notifications** |||
| `margin_ratio_threshold_to_notify` | decimal | Margin ratio % that triggers notification |
| `total_limit_orders_filled_to_notify` | tinyint | Number of filled limit orders before notifying |
| **Status** |||
| `can_trade` | boolean | Whether this account is allowed to trade |
| `is_active` | boolean | Whether this account is active |
| `disabled_reason` | varchar | Reason if account was disabled |
| `disabled_at` | timestamp | When account was disabled |
| **API Credentials** |||
| `binance_api_key` | encrypted | Account-specific Binance API key (overrides global) |
| `binance_api_secret` | encrypted | Account-specific Binance API secret |
| `bybit_api_key` | encrypted | Account-specific Bybit API key (overrides global) |
| `bybit_api_secret` | encrypted | Account-specific Bybit API secret |

### 3. `trade_configuration` Table (Trading Strategy Profile)

| Column | Type | Description |
|--------|------|-------------|
| `canonical` | varchar | Unique identifier (e.g., "conservative", "aggressive") |
| `description` | varchar | Human-readable description of this profile |
| `is_default` | boolean | Whether this is the default configuration |
| `indicator_timeframes` | json | Timeframes used for indicator analysis |
| `least_timeframe_index_to_change_indicator` | int | Minimum timeframe index required to change direction |
| `fast_trade_position_duration_seconds` | int | Duration threshold for "fast trade" detection |
| `fast_trade_position_closed_age_seconds` | int | Age threshold for recently closed positions |
| `disable_exchange_symbol_from_negative_pnl_position` | boolean | Disable symbol trading after negative PnL |

---

## Position Opening Logic

### Prerequisites for Opening a Position

1. **Global checks:**
   - `martingalian.allow_opening_positions` = true
   - `martingalian.is_cooling_down` = false

2. **Account checks:**
   - `accounts.can_trade` = true
   - `accounts.is_active` = true
   - Available position slots (current open < `total_positions_long` or `total_positions_short`)

3. **Market conditions:**
   - Direction signal exists on ExchangeSymbol (LONG or SHORT)
   - Symbol is eligible for trading

### Position Types

| Direction | Trigger | Max Positions | Leverage | Margin % |
|-----------|---------|---------------|----------|----------|
| **LONG** | ExchangeSymbol direction = LONG | `total_positions_long` | `position_leverage_long` | `market_order_margin_percentage_long` |
| **SHORT** | ExchangeSymbol direction = SHORT | `total_positions_short` | `position_leverage_short` | `market_order_margin_percentage_short` |

---

## Order Execution Flow

```
1. Market Order (Entry)
   └── Opens position at current market price

2. Profit Order (Take-Profit)
   └── Placed at entry price ± profit_percentage

3. Limit Orders (Ladder)
   └── Placed at intervals below/above entry (martingale averaging)

4. Stop-Market Order (Stop-Loss)
   └── Placed after stop_market_wait_minutes at stop_market_initial_percentage
```

---

## Synchronization

Scheduled cron jobs regularly:
- Sync open positions with exchange
- Update order states
- Reconcile balances
- Check for filled orders
- Adjust stop-loss levels as needed

---

*[Document in progress - to be expanded with implementation details]*
