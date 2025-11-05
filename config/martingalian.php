<?php

declare(strict_types=1);

return [

    /**
     * Small safety tolerance to lower the leverage bracket in case is
     * falls inside that percentage gap, to avoid last limit order rejections.
     */
    'bracket_headroom_pct' => '0.004',

    /*
    |--------------------------------------------------------------------------
    | Performance / Feature Toggles
    |--------------------------------------------------------------------------
    |
    | slow_query_threshold_ms:  Log queries slower than this (in milliseconds).
    | info_if:                  When true, emit extra info-level logs (useful in staging).
    | can_trade:                Global kill-switch. If false, the bot NEVER places live orders.
    | detect_orphan_positions:  If true, background jobs try to reconcile orphan positions.
    | can_open_positions:       If false, existing positions can be managed/closed, but no new ones open.
    | notifications_enabled:    If false, no notifications will be sent (useful for testing).
    */
    'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 2500),
    'info_if' => env('INFO_IF', false),
    'can_trade' => env('CAN_TRADE', false),
    'detect_orphan_positions' => env('DETECT_ORPHAN_POSITIONS', true),
    'can_open_positions' => env('CAN_OPEN_POSITIONS', false),
    'notifications_enabled' => env('NOTIFICATIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Indicator Batch Processing
    |--------------------------------------------------------------------------
    |
    | jobs_per_index_batch: Number of parallel QueryAllIndicatorsForSymbolsChunkJob
    |                       jobs that can run simultaneously in the same index group.
    |                       Higher values = faster execution but more API rate limit risk.
    |                       Default: 20 (balanced between speed and safety)
    |                       Conservative: 10 (0% rate limiting)
    |                       Aggressive: 30+ (faster but may hit rate limits)
    */
    'indicators' => [
        'jobs_per_index_batch' => (int) env('INDICATORS_JOBS_PER_INDEX_BATCH', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Throttle Seconds
    |--------------------------------------------------------------------------
    |
    | Default throttle window for auto-created throttle rules.
    | When a throttle rule doesn't exist in the database, this default is used.
    |
    | default: 300 seconds (5 minutes)
    */
    'default_throttle_seconds' => (int) env('DEFAULT_THROTTLE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | API Throttlers
    |--------------------------------------------------------------------------
    |
    | Rate limiting configuration for external API providers.
    | Each throttler enforces requests per window and minimum delays between requests.
    |
    | TAAPI.IO Throttler:
    | - Expert Plan: 75 requests per 15 seconds
    | - Adjust based on your plan tier
    |
    | CoinMarketCap Throttler:
    | - Free/Hobbyist: 30 requests per minute
    | - Startup: 60 requests per minute
    | - Standard: 90 requests per minute
    | - Professional: 120 requests per minute
    | - Enterprise: 120+ requests per minute
    | - Adjust based on your plan tier
    |
    | Binance Throttler (IP-based coordination):
    | - Uses dynamic rate limiting based on response headers
    | - Three limit types: RAW_REQUESTS, REQUEST_WEIGHT, ORDERS
    | - Multiple intervals: 1S, 10S, 1M, 2M, 5M, 10M, 1H, 1D
    | - Defaults below are conservative for standard Binance Futures API
    | - Rate limits are IP-based and coordinated across workers via Cache
    */
    'throttlers' => [
        'taapi' => [
            'requests_per_window' => (int) env('TAAPI_THROTTLER_REQUESTS_PER_WINDOW', 75),
            'window_seconds' => (int) env('TAAPI_THROTTLER_WINDOW_SECONDS', 15),
            'min_delay_between_requests_ms' => (int) env('TAAPI_THROTTLER_MIN_DELAY_MS', 200),
        ],

        'coinmarketcap' => [
            'requests_per_window' => (int) env('COINMARKETCAP_THROTTLER_REQUESTS_PER_WINDOW', 30),
            'window_seconds' => (int) env('COINMARKETCAP_THROTTLER_WINDOW_SECONDS', 60),
            'min_delay_between_requests_ms' => (int) env('COINMARKETCAP_THROTTLER_MIN_DELAY_MS', 2000),
        ],

        'binance' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('BINANCE_THROTTLER_MIN_DELAY_MS', 200),

            // Safety threshold: stop making requests when reaching this percentage of limit (0.0-1.0)
            // 0.8 = stop at 80% to leave 20% buffer before hitting the limit
            'safety_threshold' => (float) env('BINANCE_THROTTLER_SAFETY_THRESHOLD', 0.8),

            // Rate limit definitions for pre-flight safety checks
            // These are checked against response header values stored in Cache
            // Type can be: REQUEST_WEIGHT or ORDERS
            // Interval format: {number}{unit} where unit is s/m/h/d (e.g., "10s", "1m")
            'rate_limits' => [
                [
                    'type' => 'REQUEST_WEIGHT',
                    'interval' => '1m',
                    'limit' => (int) env('BINANCE_WEIGHT_LIMIT_1M', 1200),
                ],
                [
                    'type' => 'REQUEST_WEIGHT',
                    'interval' => '10s',
                    'limit' => (int) env('BINANCE_WEIGHT_LIMIT_10S', 100),
                ],
                [
                    'type' => 'ORDERS',
                    'interval' => '10s',
                    'limit' => (int) env('BINANCE_ORDERS_LIMIT_10S', 50),
                ],
            ],
        ],

        'bybit' => [
            // Minimum delay between requests in milliseconds
            'min_delay_ms' => (int) env('BYBIT_THROTTLER_MIN_DELAY_MS', 200),

            // Safety threshold: stop making requests when remaining falls below this percentage (0.0-1.0)
            // 0.1 = stop when less than 10% of requests remaining to leave buffer
            // Note: Bybit uses "remaining" not "used", so lower threshold means more conservative
            'safety_threshold' => (float) env('BYBIT_THROTTLER_SAFETY_THRESHOLD', 0.1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Live API Credentials (NOT per-account exchange creds)
    |--------------------------------------------------------------------------
    |
    | api.credentials.*: Service-level API keys used by background jobs (market data,
    | indicators, metadata, notifications, etc.). These are NOT your trading sub-accounts.
    */
    'api' => [
        'url' => [
            'binance' => [
                'rest' => 'https://fapi.binance.com',
                'stream' => 'wss://fstream.binance.com',
            ],

            'bybit' => [
                'rest' => 'https://api.bybit.com',
                'stream' => 'wss://stream.bybit.com',
            ],

            'alternativeme' => [
                'rest' => 'https://api.alternative.me',
            ],

            'coinmarketcap' => [
                'rest' => 'https://pro-api.coinmarketcap.com',
            ],

            'taapi' => [
                'rest' => 'https://api.taapi.io',
            ],
        ],

        // Pushover configuration for notifications
        'pushover' => [
            // Delivery groups
            // Each group maps to a Pushover delivery group with its configuration
            // Priority levels: -2 (lowest), -1 (low), 0 (normal), 1 (high), 2 (emergency)
            'delivery_groups' => [
                'exceptions' => [
                    'group_key' => env('PUSHOVER_DG_EXCEPTIONS'),
                    'priority' => 2, // Emergency priority with siren sound
                ],
                'default' => [
                    'group_key' => env('PUSHOVER_DG_DEFAULT'),
                    'priority' => 0, // Normal priority
                ],
                'indicators' => [
                    'group_key' => env('PUSHOVER_DG_INDICATORS'),
                    'priority' => 0, // Normal priority
                ],
            ],
        ],

        // Webhook URLs for notification delivery confirmations
        // Used by external gateways to confirm delivery/bounces/opens
        'webhooks' => [
            // Zeptomail webhook URL (receives: hard bounce, soft bounce, open events)
            // Configure in Zeptomail dashboard: Settings > Webhooks
            // Example: https://your-domain.com/api/webhooks/zeptomail/events
            'zeptomail' => env('ZEPTOMAIL_WEBHOOK_URL'),

            // Zeptomail webhook secret for signature verification
            // Get this from Zeptomail dashboard: Settings > Webhooks > Secret Key
            'zeptomail_secret' => env('ZEPTOMAIL_WEBHOOK_SECRET'),

            // Pushover callback URL (for emergency-priority receipt acknowledgment)
            // Used as 'callback' parameter when sending emergency notifications
            // Example: https://your-domain.com/api/webhooks/pushover/receipt
            'pushover' => env('PUSHOVER_WEBHOOK_URL'),
        ],

        'credentials' => [

            // Live Binance keys (service-level; NOT user account keys used to place orders).
            'binance' => [
                'api_key' => env('BINANCE_API_KEY'),
                'api_secret' => env('BINANCE_API_SECRET'),
            ],

            // Live Bybit keys (service-level; NOT user account keys used to place orders).
            'bybit' => [
                'api_key' => env('BYBIT_API_KEY'),
                'api_secret' => env('BYBIT_API_SECRET'),
            ],

            // TAAPI indicator provider.
            'taapi' => [
                'secret' => env('TAAPI_SECRET'),
            ],

            // CoinMarketCap metadata provider.
            'coinmarketcap' => [
                'api_key' => env('COINMARKETCAP_API_KEY'),
            ],
        ],
    ],
];
