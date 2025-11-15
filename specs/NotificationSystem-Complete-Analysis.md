# Comprehensive Notification System Analysis

Generated: 2025-11-14

## System Overview

The Martingalian Core notification system is built on three core components:

1. **Notifications Table** - Registry of notification message templates (canonicals)
2. **NotificationService** - Unified interface for sending notifications 
3. **Throttler** - Database-driven throttling system to control notification frequency

---

## Part 1: Notification Canonicals (Master Registry)

### Total: 42 Base Canonicals (from seeder)

These are defined in `MartingalianSeeder::seedNotifications()`:

#### Admin-Only Notifications (System/Infrastructure)
- `stale_price_detected` - Exchange symbol prices not updated within expected timeframe
- `binance_prices_restart` - Binance price monitoring restarts due to symbol changes
- `binance_websocket_error` - Binance WebSocket encounters an error
- `binance_invalid_json` - Binance API returns invalid JSON
- `binance_db_update_error` - Database update fails for Binance price data
- `api_rate_limit_exceeded` - API rate limit is exceeded
- `api_system_error` - Exchange API encounters system errors
- `api_network_error` - Network errors communicating with exchange
- `api_connection_failed` - Unable to connect to exchange API
- `server_ip_whitelisted` - Server IP successfully whitelisted on exchange
- `symbol_synced` - Symbol successfully synced with CoinMarketCap
- `step_error` - Step encounters an error during execution
- `forbidden_hostname_added` - Hostname forbidden from accessing exchange API
- `uncategorized_notification` - Fallback for messages without specific canonical

#### User-Facing Notifications (Require User Action)
- `ip_not_whitelisted` - Server IP not whitelisted on exchange API
- `invalid_api_credentials` - API credentials invalid or API keys locked
- `account_in_liquidation` - User account is in liquidation mode
- `account_reduce_only_mode` - Account in reduce-only mode - cannot open new positions
- `account_trading_banned` - Account trading banned due to risk control
- `account_unauthorized` - Account authentication fails or is unauthorized
- `api_key_expired` - API key has expired and needs renewal
- `api_credentials_or_ip` - API call fails with ambiguous error (credentials, IP, or permissions)
- `invalid_api_key` - API key is invalid (Bybit specific)
- `invalid_signature` - API signature is invalid (Bybit specific)
- `insufficient_permissions` - API key lacks required permissions (Bybit specific)
- `insufficient_balance_margin` - Account has insufficient balance or margin
- `kyc_verification_required` - KYC verification is required to continue trading
- `bounce_alert_to_pushover` - Email delivery failed (sent via Pushover)
- `api_access_denied` - API access is denied (ambiguous 401/403)
- `exchange_maintenance` - Exchange is under maintenance or overloaded
- `symbol_delisting_positions_detected` - Symbol delisting, open positions exist
- `price_spike_check_symbol_error` - Price spike check fails due to missing data
- `exchange_symbol_no_taapi_data` - Exchange symbol auto-deactivated due to no TAAPI data

---

## Part 2: Job-Generated Notifications (Extended Canonicals)

### Total: 64 Additional Canonicals (from codebase via withCanonical/sendToAdminByCanonical)

These are sent by various jobs and background processes, using the Throttler system:

#### Order & Position Lifecycle (30 canonicals)
- `place_order` - Order placement initiated
- `place_limit_order` - Limit order placement initiated
- `place_market_order` - Market order placement initiated
- `place_profit_order` - Profit order (hedge) placement initiated
- `place_stop_loss_order` - Stop loss order placement initiated (Bybit only)
- `stop_loss_placed_successfully` - Stop loss successfully placed
- `stop_loss_precondition_failed` - Stop loss precondition not met
- `stop_loss_placement_error` - Stop loss placement failed
- `limit_order_placement_error` - Limit order placement failed
- `market_order_placement_error` - Market order placement failed
- `market_order_placement_error_no_order` - Market order error - no order created
- `profit_order_placement_error` - Profit order placement failed
- `profit_order_placement_error_no_order` - Profit order error - no order created
- `modify_order` - Order modification initiated
- `sync_order` - Order sync from exchange
- `stop_market_filled_closing_position` - Stop/market order filled, position closing
- `position_residual_amount_detected` - Position has residual amount
- `position_residual_verification_error` - Position residual verification error
- `position_closing_negative_pnl` - Position closing with negative PnL
- `position_price_spike_cooldown_set` - Price spike cooldown applied to position
- `position_validation_inactive_status` - Position validation found inactive status
- `position_validation_unsynced_orders` - Position validation found unsynced orders
- `position_validation_incorrect_limit_count` - Position validation found incorrect limit count
- `position_validation_exception` - Position validation raised exception
- `check_position_order_changes` - Position order changes detected
- `verify_order_notional_market` - Order notional verification for market orders
- `apply_wap` - WAP (Weighted Average Price) calculation applied
- `create_place_limit_orders` - Creating and placing limit orders
- `create_dispatch_position_orders` - Creating and dispatching position orders
- `delete_position_history` - Position history data deleted

#### Price & Indicator Operations (8 canonicals)
- `price_spike_check_batch_error` - Batch price spike check error
- `price_spike_check_symbol_error` - Individual symbol price spike check error
- `query_indicator` - Indicator query initiated
- `query_indicators_chunk` - Chunk of indicators queried
- `query_all_indicators_chunk` - All indicators for symbols chunk queried
- `wap_calculation_error` - WAP calculation error
- `wap_calculation_invalid_break_even_price` - WAP calc: invalid break-even price
- `wap_calculation_zero_quantity` - WAP calc: zero quantity

#### WAP Order Modification (3 canonicals)
- `wap_calculation_profit_order_missing` - WAP calc: profit order missing
- `wap_profit_order_updated_successfully` - Profit order updated successfully via WAP
- `symbol_cmc_sync_success` - Symbol successfully synced with CoinMarketCap

#### Lifecycle & Account Operations (9 canonicals)
- `resettle_order` - Order resettlement process
- `assign_tokens_positions` - Tokens assigned to new positions
- `launch_created_positions` - Created positions launched
- `launch_positions_watchers` - Position watchers launched
- `dispatch_position` - Position dispatch initiated
- `dispatch_new_positions_tokens_assigned` - New positions dispatched with tokens assigned
- `confirm_price_alignments` - Price alignments confirmed
- `conclude_indicators_error` - Indicator conclusion error

#### Exchange-Specific Surveillance (4 canonicals)
- `orphaned_orders_detected` - Orphaned orders detected in system
- `orphaned_orders_match_error` - Error matching orphaned orders
- `orphaned_positions_detected` - Orphaned positions detected in system
- `orphaned_positions_match_error` - Error matching orphaned positions
- `unknown_orders_detected` - Unknown orders detected from exchange
- `unknown_orders_assessment_error` - Error assessing unknown orders

#### WebSocket/Stream Operations (7 canonicals)
- `websocket_error` - Generic WebSocket error occurred
- `websocket_reconnected` - Successfully reconnected to WebSocket
- `websocket_connection_failed` - WebSocket connection failed
- `websocket_closed_with_details` - WebSocket connection closed with details
- `websocket_reconnect_attempt` - Attempting to reconnect to WebSocket
- `websocket_max_reconnect_attempts_reached` - Max reconnection attempts reached
- `bybit_subscription_failed` - Bybit WebSocket subscription failed

#### System/Job Operations (3 canonicals)
- `job_execution_failed` - Background job execution failed
- `critical_alert` - Critical system alert
- `step_error` - Step encountered an error (throttled via seeder)
- `steps_dispatcher` - Steps dispatcher execution
- `fetch_balance` - Balance fetch operation

---

## Part 3: Throttling Rules (Master Registry)

Defined in `MartingalianSeeder::seedThrottleRules()`:

### General Throttle Intervals
- `throttle_900` - 15 minutes (900 seconds)
- `throttle_1800` - 30 minutes (1800 seconds)
- `throttle_3600` - 1 hour (3600 seconds)

### Notification-Specific Throttles
- `symbol_synced` - 1 hour (3600 seconds)
- `exchange_symbol_no_taapi_data` - **No throttle** (0 seconds) - Send immediately
- `step_error` - 15 minutes (900 seconds)
- `forbidden_hostname_added` - 1 hour (3600 seconds)

### Supervisor Restart Throttles
- `binance_prices_restart` - 1 minute (60 seconds)
- `bybit_prices_restart` - 1 minute (60 seconds)

### WebSocket Notification Throttles (All 15 minutes / 900 seconds)
- `websocket_error`
- `websocket_reconnected`
- `websocket_connection_failed`
- `websocket_closed_with_details`
- `websocket_reconnect_attempt`
- `websocket_error_3`
- `binance_no_symbols`
- `bybit_no_symbols`
- `binance_websocket_error`
- `bybit_websocket_error`
- `binance_invalid_json`
- `bybit_invalid_json`
- `binance_db_update_error`
- `bybit_db_update_error`
- `binance_db_insert_error`
- `bybit_db_insert_error`

### API Exception Handler Throttles (Exchange-Specific)

**Binance** (all prefixed `binance_`)
- `binance_ip_not_whitelisted` - 15 minutes (900 seconds)
- `binance_api_rate_limit_exceeded` - 30 minutes (1800 seconds)
- `binance_api_connection_failed` - 15 minutes (900 seconds)
- `binance_invalid_api_credentials` - 30 minutes (1800 seconds)
- `binance_exchange_maintenance` - 1 hour (3600 seconds)

**Bybit** (all prefixed `bybit_`)
- `bybit_ip_not_whitelisted` - 15 minutes (900 seconds)
- `bybit_api_rate_limit_exceeded` - 30 minutes (1800 seconds)
- `bybit_api_connection_failed` - 15 minutes (900 seconds)
- `bybit_invalid_api_credentials` - 30 minutes (1800 seconds)
- `bybit_exchange_maintenance` - 1 hour (3600 seconds)

**Taapi** (all prefixed `taapi_`)
- `taapi_ip_not_whitelisted` - 15 minutes
- `taapi_api_rate_limit_exceeded` - 30 minutes
- `taapi_api_connection_failed` - 15 minutes
- `taapi_invalid_api_credentials` - 30 minutes
- `taapi_exchange_maintenance` - 1 hour

**AlternativeMe** (all prefixed `alternativeme_`)
- `alternativeme_ip_not_whitelisted` - 15 minutes
- `alternativeme_api_rate_limit_exceeded` - 30 minutes
- `alternativeme_api_connection_failed` - 15 minutes
- `alternativeme_invalid_api_credentials` - 30 minutes
- `alternativeme_exchange_maintenance` - 1 hour

**CoinMarketCap** (all prefixed `coinmarketcap_`)
- `coinmarketcap_ip_not_whitelisted` - 15 minutes
- `coinmarketcap_api_rate_limit_exceeded` - 30 minutes
- `coinmarketcap_api_connection_failed` - 15 minutes
- `coinmarketcap_invalid_api_credentials` - 30 minutes
- `coinmarketcap_exchange_maintenance` - 1 hour

### Critical Account Status Notifications (Exchange-Specific)

**Binance** (all prefixed `binance_`)
- `binance_api_key_expired` - 30 minutes (1800 seconds)
- `binance_account_in_liquidation` - 15 minutes (900 seconds)
- `binance_account_reduce_only_mode` - 15 minutes (900 seconds)
- `binance_account_trading_banned` - 30 minutes (1800 seconds)
- `binance_insufficient_balance_margin` - 15 minutes (900 seconds)
- `binance_kyc_verification_required` - 30 minutes (1800 seconds)
- `binance_account_unauthorized` - 15 minutes (900 seconds)
- `binance_api_system_error` - 15 minutes (900 seconds)
- `binance_api_network_error` - 15 minutes (900 seconds)

**Bybit** (all prefixed `bybit_`)
- `bybit_api_key_expired` - 30 minutes (1800 seconds)
- `bybit_account_in_liquidation` - 15 minutes (900 seconds)
- `bybit_account_reduce_only_mode` - 15 minutes (900 seconds)
- `bybit_account_trading_banned` - 30 minutes (1800 seconds)
- `bybit_insufficient_balance_margin` - 15 minutes (900 seconds)
- `bybit_kyc_verification_required` - 30 minutes (1800 seconds)
- `bybit_account_unauthorized` - 15 minutes (900 seconds)
- `bybit_api_system_error` - 15 minutes (900 seconds)
- `bybit_api_network_error` - 15 minutes (900 seconds)

### User System Throttles
- `bounce_alert_to_pushover` - 1 hour (3600 seconds)
- `symbol_delisting_positions_detected` - 30 minutes (1800 seconds)
- `price_spike_check_symbol_error` - 15 minutes (900 seconds)

---

## Part 4: Notification Trigger Points

### Files Sending Notifications via Throttler::using(NotificationService::class)

**Core Support:**
1. `/home/bruno/ingestion/packages/martingalian/core/src/Observers/ApiRequestLogObserver.php` - API request error logging
2. `/home/bruno/ingestion/packages/martingalian/core/src/Observers/NotificationLogObserver.php` - Notification delivery logging
3. `/home/bruno/ingestion/packages/martingalian/core/src/Support/ApiClients/Websocket/BybitApiClient.php` - Bybit WebSocket errors
4. `/home/bruno/ingestion/packages/martingalian/core/src/Concerns/ApiExceptionHelpers.php` - API exception handling
5. `/home/bruno/ingestion/packages/martingalian/core/src/Models/StepsDispatcher.php` - Step dispatcher execution
6. `/home/bruno/ingestion/packages/martingalian/core/src/Concerns/ApiRequestLog/SendsNotifications.php` - Comprehensive API error mapping

**WebSocket/Network:**
7. `/home/bruno/ingestion/packages/martingalian/core/src/Abstracts/BaseWebsocketClient.php` - WebSocket errors & reconnection
   - WebSocket error, reconnection failed, connection failed, closed with details, reconnect attempt

**Base Job Handler:**
8. `/home/bruno/ingestion/packages/martingalian/core/src/Abstracts/BaseQueueableJob.php` - Job exception handling
9. `/home/bruno/ingestion/packages/martingalian/core/src/Concerns/BaseQueueableJob/HandlesStepExceptions.php` - Step execution errors

**Order Lifecycle Jobs (9 files):**
10. `Jobs/Models/Order/PlaceOrderJob.php` - Order placement
11. `Jobs/Models/Order/PlaceLimitOrderJob.php` - Limit order placement
12. `Jobs/Models/Order/PlaceMarketOrderJob.php` - Market order placement
13. `Jobs/Models/Order/PlaceStopLossOrderJob.php` - Stop loss order (Bybit only)
14. `Jobs/Models/Order/PlaceProfitOrderJob.php` - Profit/hedge order placement
15. `Jobs/Models/Order/ModifyOrderJob.php` - Order modification
16. `Jobs/Models/Order/ProcessOrderChangesJob.php` - Order change processing
17. `Jobs/Models/Order/SyncOrderJob.php` - Order sync from exchange
18. `Jobs/Lifecycles/Orders/ResettleOrderJob.php` - Order resettlement

**Position Lifecycle Jobs (8 files):**
19. `Jobs/Lifecycles/Positions/DispatchPositionJob.php` - Position dispatch
20. `Jobs/Lifecycles/Positions/ValidatePositionJob.php` - Position validation (4 canonicals)
21. `Jobs/Lifecycles/Positions/VerifyOrderNotionalForMarketOrderJob.php` - Market order notional verification
22. `Jobs/Lifecycles/Positions/CheckPositionOrderChangesJob.php` - Position order change detection
23. `Jobs/Lifecycles/Positions/ApplyWAPJob.php` - WAP calculation
24. `Jobs/Lifecycles/Positions/CreateAndPlaceLimitOrdersJob.php` - Create & place limit orders
25. `Jobs/Lifecycles/Positions/VerifyPositionResidualAmountJob.php` - Residual amount verification

**Position Modification Jobs (3 files):**
26. `Jobs/Models/Position/CalculateWAPAndModifyProfitOrderJob.php` - WAP & profit order modification (6 canonicals)
27. `Jobs/Models/Position/CreateAndDispatchPositionOrdersJob.php` - Position orders creation
28. `Jobs/Models/Position/ClosePositionAtomicallyJob.php` - Position closing (2 canonicals)
29. `Jobs/Models/Position/DeletePositionHistoryDataJob.php` - Position history cleanup

**Indicator/Symbol Jobs (4 files):**
30. `Jobs/Models/Indicator/QueryIndicatorJob.php` - Individual indicator query
31. `Jobs/Models/Indicator/QueryIndicatorsByChunkJob.php` - Indicators chunk query
32. `Jobs/Models/Indicator/QueryAllIndicatorsForSymbolsChunkJob.php` - All indicators for symbols
33. `Jobs/Models/ExchangeSymbol/CheckPriceSpikeAndCooldownJob.php` - Price spike checking (2 canonicals)
34. `Jobs/Models/Symbol/SyncSymbolJob.php` - Symbol sync

**Lifecycle/Account Jobs (6 files):**
35. `Jobs/Lifecycles/Accounts/LaunchCreatedPositionsJob.php` - Launch positions
36. `Jobs/Lifecycles/Accounts/LaunchPositionsWatchersJob.php` - Launch watchers
37. `Jobs/Lifecycles/Accounts/DispatchNewPositionsWithTokensAssignedJob.php` - Dispatch positions with tokens
38. `Jobs/Lifecycles/ExchangeSymbols/ConfirmPriceAlignmentsJob.php` - Confirm price alignments
39. `Jobs/Lifecycles/ExchangeSymbols/ConcludeIndicatorsJob.php` - Conclude indicators
40. `Jobs/Models/Account/AssignTokensToNewPositionsJob.php` - Assign tokens

**Surveillance Jobs (3 files):**
41. `Jobs/Support/Surveillance/MatchOrphanedExchangeOrdersJob.php` - Match orphaned orders (2 canonicals)
42. `Jobs/Support/Surveillance/MatchOrphanedExchangePositionsJob.php` - Match orphaned positions (2 canonicals)
43. `Jobs/Support/Surveillance/AssessExchangeUnknownOrdersJob.php` - Assess unknown orders (2 canonicals)

---

## Part 5: Throttling Patterns

### Pattern 1: Simple Throttled Notification
```php
Throttler::using(NotificationService::class)
    ->withCanonical('canonical_name')
    ->execute(function () use ($context) {
        NotificationService::send(
            user: $user,
            message: 'Your message',
            title: 'Title',
            canonical: 'canonical_name',
            // ... other params
        );
    });
```

### Pattern 2: Exchange-Specific Throttled Notification
```php
$throttleCanonical = $apiSystem.'_api_rate_limit_exceeded';

Throttler::using(NotificationService::class)
    ->withCanonical($throttleCanonical)
    ->execute(function () {
        NotificationService::send(
            user: Martingalian::admin(),
            message: $messageData['emailMessage'],
            canonical: 'api_rate_limit_exceeded',  // Base canonical
            exchange: $apiSystem
        );
    });
```

### Pattern 3: Account-Contextual Throttle
```php
Throttler::using(NotificationService::class)
    ->withCanonical('canonical_name')
    ->for($account)  // Throttle per account
    ->execute(function () {
        NotificationService::send(/* ... */);
    });
```

### Pattern 4: Manual Throttle Override
```php
Throttler::using(NotificationService::class)
    ->withCanonical('canonical_name')
    ->throttleFor(300)  // Override to 5 minutes
    ->execute(function () {
        NotificationService::send(/* ... */);
    });
```

### Pattern 5: No Throttle (Zero Seconds)
```php
// Defined in seeder with throttle_seconds = 0
// Executes immediately without any throttle checking
Throttler::using(NotificationService::class)
    ->withCanonical('exchange_symbol_no_taapi_data')
    ->execute(function () {
        NotificationService::send(/* ... */);
    });
```

---

## Part 6: Throttle Rule Lookup

### Database Schema
- **Table:** `throttle_rules`
- **Key Fields:**
  - `canonical` - Unique identifier
  - `throttle_seconds` - Throttle interval in seconds
  - `is_active` - Whether rule is active
  - `description` - Human-readable description

### Dynamic Rule Creation
- If a `withCanonical()` is called without a matching rule, and `auto_create_missing_throttle_rules` config is true:
  - Rule is auto-created with auto-generated description
  - Strategy class is stored for audit purposes
- If rule doesn't exist and auto-create is disabled: execution is throttled (not executed)

---

## Summary Statistics

- **Base Notification Canonicals (Seeder):** 42
- **Job-Generated Canonicals (withCanonical):** 64
- **Unique Throttle Rules (Seeder):** 85+
- **Files Using Throttler::using(NotificationService::class):** 43
- **Total Unique Notification Types:** 106+

### Throttle Interval Distribution
- **0 seconds (No throttle):** 1 canonical
- **60 seconds (1 minute):** 2 canonicals
- **900 seconds (15 minutes):** ~35 canonicals
- **1800 seconds (30 minutes):** ~20 canonicals
- **3600 seconds (1 hour):** ~15 canonicals

---

## Key Design Principles

1. **Separation of Concerns:**
   - Notifications table = WHAT to say
   - Throttle rules = HOW OFTEN to say it
   - NotificationService = HOW to deliver it

2. **Throttle Granularity:**
   - Global throttles (no context)
   - Per-account throttles (for account-specific issues)
   - Per-user throttles (for user-specific issues)
   - Per-relatable-model throttles (for any relatable model)

3. **No Throttle Execution:**
   - Throttle rules with 0 seconds execute immediately without DB logging
   - Improves performance for critical notifications

4. **Exchange-Specific Throttles:**
   - Each exchange (Binance, Bybit, Taapi, AlternativeMe, CoinMarketCap) has its own throttle prefixes
   - Prevents cross-API throttling interference

5. **Virtual Admin User:**
   - `Martingalian::admin()` is a virtual user for system notifications
   - Works seamlessly with NotificationService::send()
