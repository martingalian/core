<?php

declare(strict_types=1);

return [

    /**
     * Small safety tolerance to lower the leverage bracket in case is
     * falls inside that percentage gap, to avoid last limit order rejections.
     */
    'bracket_headroom_pct' => '0.004',

    'send_pushover_notifications' => env('SEND_PUSHOVER_NOTIFICATIONS', true),

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
    */
    'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 2500),
    'info_if' => env('INFO_IF', false),
    'can_trade' => env('CAN_TRADE', false),
    'detect_orphan_positions' => env('DETECT_ORPHAN_POSITIONS', true),
    'can_open_positions' => env('CAN_OPEN_POSITIONS', false),

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

        // Pushover application tokens per channel/type.
        'pushover' => [
            'nidavellir' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR'),
            'nidavellir_cronjobs' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_CRONJOBS'),
            'nidavellir_positions' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_POSITIONS'),
            'nidavellir_orders' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_ORDERS'),
            'nidavellir_users' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_USERS'),
            'nidavellir_errors' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_ERRORS'),
            'nidavellir_warnings' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_WARNINGS'),
            'nidavellir_monitor' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_MONITOR'),
            'nidavellir_rate_limit' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_RATELIMIT'),
            'nidavellir_symbols' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_SYMBOLS'),
            'nidavellir_profit' => env('PUSHOVER_APPLICATION_TOKEN_NIDAVELLIR_PROFIT'),
        ],

        'credentials' => [

            // Live Binance keys (service-level; NOT user account keys used to place orders).
            'binance' => [
                'api_key' => env('BINANCE_API_KEY'),
                'api_secret' => env('BINANCE_API_SECRET'),
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
