<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Models\TradeConfiguration;
use Martingalian\Core\Models\User;

final class MartingalianSeeder extends Seeder
{
    /**
     * Seed the application's database with all core data.
     * This consolidated seeder contains the final state of all database records.
     */
    public function run(): void
    {
        $now = now();

        // ============================================================
        // API SYSTEMS
        // ============================================================
        $binance = ApiSystem::create([
            'name' => 'Binance',
            'canonical' => 'binance',
            'is_exchange' => true,
            'taapi_canonical' => 'binancefutures',
        ]);

        $bybit = ApiSystem::create([
            'name' => 'Bybit',
            'canonical' => 'bybit',
            'is_exchange' => true,
            'taapi_canonical' => 'bybit',
        ]);

        ApiSystem::create([
            'name' => 'CoinmarketCap',
            'canonical' => 'coinmarketcap',
            'is_exchange' => false,
        ]);

        ApiSystem::create([
            'name' => 'AlternativeMe',
            'canonical' => 'alternativeme',
            'is_exchange' => false,
        ]);

        ApiSystem::create([
            'name' => 'Taapi',
            'canonical' => 'taapi',
            'is_exchange' => false,
        ]);

        // ============================================================
        // QUOTES
        // ============================================================
        $usdt = Quote::create([
            'canonical' => 'USDT',
            'name' => 'USDT (Tether)',
        ]);

        Quote::create([
            'canonical' => 'USDC',
            'name' => 'USDC (USD Coin)',
        ]);

        Quote::create([
            'canonical' => 'BFUSDT',
            'name' => 'BFUSDT (USD Coin)',
        ]);

        // ============================================================
        // USER
        // ============================================================
        $trader = User::create([
            'name' => env('TRADER_NAME'),
            'email' => env('TRADER_EMAIL'),
            'password' => bcrypt('password'),
            'is_active' => true,
            'pushover_key' => env('PUSHOVER_USER_KEY'),
            'notification_channels' => ['mail', 'pushover'],
        ]);

        // ============================================================
        // TRADE CONFIGURATION
        // ============================================================
        TradeConfiguration::create([
            'is_default' => true,
            'canonical' => 'standard',
            'description' => 'Standard trade configuration, default for all tokens',
            'indicator_timeframes' => ['1h', '4h', '6h', '12h', '1d'],
        ]);

        // ============================================================
        // INDICATORS (final state)
        // ============================================================
        Indicator::create([
            'canonical' => 'emas-same-direction',
            'is_active' => true,
            'class' => "Martingalian\Core\Indicators\RefreshData\EMAsSameDirection",
            'is_computed' => false,
        ]);

        Indicator::create([
            'canonical' => 'candle-comparison',
            'type' => 'refresh-data',
            'is_active' => true,
            'is_computed' => true,
            'class' => 'Martingalian\Core\Indicators\RefreshData\CandleComparisonIndicator',
            'parameters' => [
                'results' => 2,
            ],
        ]);

        Indicator::create([
            'canonical' => 'macd',
            'is_active' => false,
            'class' => "Martingalian\Core\Indicators\RefreshData\MACDIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'optInFastPeriod' => '12',
                'optInSlowPeriod' => 26,
                'optInSignalPeriod' => 9,
            ],
        ]);

        Indicator::create([
            'canonical' => 'obv',
            'class' => "Martingalian\Core\Indicators\RefreshData\OBVIndicator",
            'parameters' => [
                'results' => 2,
            ],
        ]);

        Indicator::create([
            'canonical' => 'adx',
            'class' => "Martingalian\Core\Indicators\RefreshData\ADXIndicator",
            'parameters' => [
                'results' => 1,
            ],
        ]);

        Indicator::create([
            'canonical' => 'emas-convergence',
            'is_active' => false,
            'class' => "Martingalian\Core\Indicators\RefreshData\EMAsConvergence",
            'is_computed' => false,
        ]);

        Indicator::create([
            'canonical' => 'ema-40',
            'class' => "Martingalian\Core\Indicators\RefreshData\EMAIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'period' => '40',
            ],
        ]);

        Indicator::create([
            'canonical' => 'ema-80',
            'class' => "Martingalian\Core\Indicators\RefreshData\EMAIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'period' => '80',
            ],
        ]);

        Indicator::create([
            'canonical' => 'ema-120',
            'class' => "Martingalian\Core\Indicators\RefreshData\EMAIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'period' => '120',
            ],
        ]);

        Indicator::create([
            'type' => 'dashboard',
            'is_active' => true,
            'is_computed' => true,
            'canonical' => 'candle',
            'parameters' => ['results' => 1],
            'class' => "Martingalian\Core\Indicators\History\CandleIndicator",
        ]);

        Indicator::create([
            'canonical' => 'price-volatility',
            'is_active' => true,
            'type' => 'reports',
            'class' => "Martingalian\Core\Indicators\Reports\PriceVolatilityIndicator",
            'is_computed' => true,
            'parameters' => ['results' => 2000],
        ]);

        // ============================================================
        // SYMBOLS (all CMC IDs in one batch)
        // ============================================================
        $allSymbolCmcIds = $this->getAllSymbolCmcIds();
        $symbolRows = [];

        foreach ($allSymbolCmcIds as $cmcId) {
            $symbolRows[] = [
                'cmc_id' => $cmcId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Symbol::insert($symbolRows);

        // ============================================================
        // BASE ASSET MAPPERS (Binance + Bybit)
        // ============================================================
        BaseAssetMapper::create([
            'api_system_id' => $binance->id,
            'symbol_token' => 'BONK',
            'exchange_token' => '1000BONK',
        ]);

        BaseAssetMapper::create([
            'api_system_id' => $binance->id,
            'symbol_token' => 'BROCCOLI',
            'exchange_token' => 'BROCCOLI714',
        ]);

        BaseAssetMapper::create([
            'api_system_id' => $bybit->id,
            'symbol_token' => 'BONK',
            'exchange_token' => '1000BONK',
        ]);

        // ============================================================
        // ACCOUNTS (Binance + Bybit with all credentials)
        // ============================================================
        Account::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $trader->id,
            'api_system_id' => $binance->id,
            'portfolio_quote_id' => $usdt->id,
            'trading_quote_id' => $usdt->id,
            'trade_configuration_id' => 1,
            'binance_api_key' => env('BINANCE_API_KEY'),
            'binance_api_secret' => env('BINANCE_API_SECRET'),
        ]);

        Account::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $trader->id,
            'api_system_id' => $bybit->id,
            'portfolio_quote_id' => $usdt->id,
            'trading_quote_id' => $usdt->id,
            'trade_configuration_id' => 1,
            'bybit_api_key' => env('BYBIT_API_KEY'),
            'bybit_api_secret' => env('BYBIT_API_SECRET'),
        ]);

        // ============================================================
        // MARTINGALIAN (all fields at once)
        // ============================================================
        Martingalian::create([
            'allow_opening_positions' => true,
            'admin_user_email' => env('ADMIN_USER_EMAIL'),
            'binance_api_key' => env('BINANCE_API_KEY'),
            'binance_api_secret' => env('BINANCE_API_SECRET'),
            'bybit_api_key' => env('BYBIT_API_KEY'),
            'bybit_api_secret' => env('BYBIT_API_SECRET'),
            'coinmarketcap_api_key' => env('COINMARKETCAP_API_KEY'),
            'taapi_secret' => env('TAAPI_SECRET'),
            'admin_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY'),
            'admin_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
            'notification_channels' => ['pushover', 'mail'],
        ]);

        // ============================================================
        // STEPS DISPATCHERS
        // ============================================================
        $groups = [
            'alpha', 'beta', 'gamma', 'delta', 'epsilon',
            'zeta', 'eta', 'theta', 'iota', 'kappa',
        ];

        $existing = DB::table('steps_dispatcher')
            ->whereIn('group', $groups)
            ->pluck('group')
            ->all();

        $missing = array_values(array_diff($groups, $existing));

        if (! empty($missing)) {
            $dispatcherRows = array_map(static function (string $g) use ($now) {
                return [
                    'group' => $g,
                    'can_dispatch' => true,
                    'current_tick_id' => null,
                    'last_tick_completed' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $missing);

            DB::table('steps_dispatcher')->insert($dispatcherRows);
        }

        // ============================================================
        // SERVERS
        // ============================================================
        DB::table('servers')->insert([
            'hostname' => gethostname(),
            'ip_address' => gethostbyname(gethostname()),
            'type' => 'ingestion',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ============================================================
        // NOTIFICATIONS
        // ============================================================
        $notifications = $this->getNotifications();

        foreach ($notifications as $notification) {
            DB::table('notifications')->insert([
                'canonical' => $notification['canonical'],
                'title' => $notification['title'],
                'description' => $notification['description'],
                'detailed_description' => $notification['detailed_description'] ?? null,
                'default_severity' => $notification['default_severity'],
                'user_types' => json_encode($notification['user_types']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ============================================================
        // UPDATES (only if records exist)
        // ============================================================

        // Update position profit prices (if positions exist)
        $positionData = [
            1072 => 3.04389988,
            1073 => 0.00318842,
            1064 => 321.68888500,
            1063 => 0.43392483,
            1060 => 0.30980976,
            1061 => 0.20292457,
            989 => 566.23485550,
            983 => 0.95447097,
            974 => 0.26890577,
            973 => 0.82540880,
            782 => 124.80333000,
            733 => 887.30647000,
        ];

        foreach ($positionData as $id => $profitPrice) {
            $position = Position::find($id);

            if ($position) {
                $position->updateSaving([
                    'first_profit_price' => $profitPrice,
                ]);
            }
        }

        // Update exchange symbols settings
        ExchangeSymbol::query()->update([
            'is_tradeable' => false,
            'is_active' => false,
            'limit_quantity_multipliers' => [2, 2, 2, 2],
        ]);
    }

    /**
     * Get all symbol CMC IDs consolidated from all schema seeders.
     */
    public function getAllSymbolCmcIds(): array
    {
        return array_unique([
            52, 1839, 5426,
            /*
            74, 2010, 1975, 5805, 11419, 512, 20947,
            1831, 6636, 2, 32196, 11092, 7083, 21159, 6535, 8916, 1321,
            22974, 7278, 5690, 3077, 28321, 3794, 4030, 2280, 23095,
            36920, 36410, 36861, 12894, 31525, 29210, 30171, 20873, 34466, 26198,
            7232, 33788, 36922, 34034, 7978, 36775, 34993, 1586, 14783, 3978,
            19966, 32325, 22461, 35168, 35430, 22861, 36671, 14806, 24924, 28382,
            36713, 10974, 35749, 34103, 36369, 28933, 10688, 30096, 28504, 15678,
            1437, 3155, 7226, 32684, 2011, 6210,
            7129, 5864,
            1,
            1720, 2566, 1684, 1697, 1376, 2469, 11289, 37566, 7501, 18876,
            7737, 18069, 4558, 7080,
            6958, 29270, 29676, 8766, 6783, 10903, 4066, 6538, 131, 4092,
            28324, 6892, 2130, 3773, 3513, 4195, 11857, 10603, 8425, 4846,
            3640, 1966, 8536, 8646, 6536, 9481, 8526, 30843, 7653, 4157,
            8119, 2586, 28081, 4847, 36405, 2416, 7725, 7288, 1698, 1896,
            11841, 21533, 1732, 28827, 2539, 26998, 5824, 1759, 18934, 6758,
            35892, 35421, 328, 29711,
            9329, 1934, 3217, 11294, 30372,
            */
        ]);
    }

    /**
     * Get all notification definitions.
     */
    public function getNotifications(): array
    {
        return [
            [
                'canonical' => 'stale_price_detected',
                'title' => 'Stale Price Detected',
                'description' => 'Sent when exchange symbol prices have not been updated within expected timeframe',
                'detailed_description' => 'Triggered by the MonitorDataCoherencyJob when exchange symbol prices have not been refreshed within the configured threshold (typically 60 seconds). This indicates a potential issue with WebSocket price streams, exchange connectivity, or database update failures. Does not involve HTTP error codes - purely time-based monitoring of the last_price_update timestamp on exchange_symbols table.',
                'default_severity' => 'High',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'binance_prices_restart',
                'title' => 'Binance: Price Stream Restart',
                'description' => 'Sent when Binance price monitoring restarts due to symbol changes',
                'detailed_description' => 'Triggered when the Binance WebSocket price monitoring process detects symbol list changes (new symbols added or removed) and needs to restart the stream to include/exclude symbols. This is an informational notification for operational transparency. No HTTP errors involved - this is a controlled process restart triggered by configuration changes.',
                'default_severity' => 'Info',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'binance_websocket_error',
                'title' => 'Binance: WebSocket Error',
                'description' => 'Sent when Binance WebSocket encounters an error',
                'detailed_description' => 'Triggered when the Binance WebSocket connection encounters critical errors such as connection failures, unexpected disconnections, protocol errors, or stream interruptions. This notification indicates the real-time price feed is disrupted and positions may not receive current market data. Typically involves network-level errors (connection refused, timeout, DNS failures) rather than HTTP response codes since WebSockets use a persistent connection.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'binance_invalid_json',
                'title' => 'Binance: Invalid JSON Response',
                'description' => 'Sent when Binance API returns invalid JSON',
                'detailed_description' => 'Triggered when Binance REST API or WebSocket returns malformed JSON that cannot be parsed. This can occur during exchange system maintenance, high load conditions, or when receiving incomplete/truncated responses. HTTP response code is typically 200 but the body contains invalid JSON syntax (missing braces, trailing commas, encoding issues). Indicates a temporary Binance infrastructure issue rather than a client error.',
                'default_severity' => 'High',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'binance_db_update_error',
                'title' => 'Binance: Database Update Error',
                'description' => 'Sent when database update fails for Binance price data',
                'detailed_description' => 'Triggered when the system successfully receives Binance price data via WebSocket or REST API, but encounters database errors when attempting to persist the data. Common causes include database connection failures, transaction deadlocks, constraint violations, or disk space issues. No HTTP error codes involved - this is a local database operation failure. Critical because price data is not being stored despite successful API retrieval.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'ip_not_whitelisted',
                'title' => 'IP Not Whitelisted',
                'description' => 'Sent when server IP is not whitelisted on exchange API for user account (Bybit 10010 specific)',
                'detailed_description' => 'Triggered when Bybit API returns error code 10010 ("Unmatched IP - IP not in API key whitelist"). This occurs when API key has IP whitelist restrictions enabled and the server\'s IP address is not in the allowed list. User needs to add the server IP to their Bybit API key whitelist settings. HTTP status code is typically 403. This error automatically creates a ServerIpNotWhitelistedRepeater that retries periodically (every 1 minute for up to 60 attempts) to detect when IP is whitelisted.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'api_credentials_or_ip',
                'title' => 'API Credentials or IP Issue',
                'description' => 'Sent for Binance error -2015 (ambiguous: could be invalid API key, IP not whitelisted, or insufficient permissions)',
                'detailed_description' => 'Triggered when Binance API returns error code -2015 with message "Invalid API-key, IP, or permissions for action". This is Binance\'s most ambiguous error covering THREE possible root causes: (1) Invalid API credentials/keys, (2) Server IP not whitelisted in API key settings, or (3) API key missing required permissions for the operation. HTTP status is typically 401. Account is automatically disabled (can_trade=false) when this error occurs. User must verify API credentials are correct, add server IP to whitelist, and ensure all required permissions (reading, trading, spot & margin trading) are enabled.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'invalid_api_key',
                'title' => 'Invalid API Key',
                'description' => 'Sent when API key is invalid (Bybit 10003 specific)',
                'detailed_description' => 'Triggered when Bybit API returns error code 10003 ("Invalid API key"). This occurs when the provided API key does not exist in Bybit\'s system, has been deleted, or contains typos. HTTP status is typically 403. Account is automatically disabled (can_trade=false). User must verify the API key is correctly copied from Bybit account settings without extra spaces or characters, and that the key has not been deleted or regenerated on the Bybit platform.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'invalid_signature',
                'title' => 'Invalid API Signature',
                'description' => 'Sent when API signature validation fails (Bybit 10004 specific)',
                'detailed_description' => 'Triggered when Bybit API returns error code 10004 ("Error sign" - signature generation/validation failure). This occurs when the API secret is incorrect or the HMAC SHA256 signature generation algorithm produces an invalid signature. HTTP status is typically 403. Account is automatically disabled (can_trade=false). User must verify the API secret is correctly configured, matches the API key, and has not been regenerated on Bybit. This error can also occur if system clock is significantly out of sync (timestamp drift).',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'insufficient_permissions',
                'title' => 'Insufficient API Permissions',
                'description' => 'Sent when API key lacks required permissions (Bybit 10005 specific)',
                'detailed_description' => 'Triggered when Bybit API returns error code 10005 ("Permission denied - API key permissions insufficient"). This occurs when attempting operations that require specific permissions not granted to the API key. HTTP status is typically 403. Account is automatically disabled (can_trade=false) as trading operations cannot proceed. User must edit their Bybit API key settings to enable required permissions: Read-Write for account info, Order placement for trading, Position management for futures. Some operations may also require Sub-account transfer or Wallet permissions.',
                'default_severity' => 'High',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'invalid_api_credentials',
                'title' => 'Invalid API Credentials',
                'description' => 'Sent when API credentials are invalid or API keys are locked',
                'detailed_description' => 'Triggered when Binance API returns error code -2017 ("API-keys are locked"). This occurs when Binance has temporarily or permanently locked the API keys due to security concerns, suspicious activity, or compliance requirements. HTTP status is typically 401. Account is automatically disabled (can_trade=false). User must log into Binance account to check API key status, review any security alerts or compliance notifications, and potentially contact Binance support to unlock keys. Often occurs after failed login attempts or when Binance detects unusual trading patterns.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'account_in_liquidation',
                'title' => 'Account in Liquidation',
                'description' => 'Sent when user account is in liquidation mode',
                'detailed_description' => 'Triggered when Binance API returns error code -2023 ("User in liquidation mode"). This occurs when account margin ratio has dropped below required maintenance margin levels and Binance is forcefully closing positions to prevent negative balance. HTTP status is typically 403. Account is automatically disabled (can_trade=false) to prevent opening new positions during liquidation. User must immediately deposit additional collateral if possible or accept position closures. This is a CRITICAL financial event indicating significant account losses and inability to meet margin requirements.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'account_reduce_only_mode',
                'title' => 'Account in Reduce-Only Mode',
                'description' => 'Sent when account is in reduce-only mode - cannot open new positions',
                'detailed_description' => 'Triggered when exchange APIs return reduce-only mode errors: Binance codes -4087 ("Reduce-only order permission") or -4088 ("No place order permission"), or Bybit code 110023 ("Can only reduce positions"). HTTP status is typically 403. This mode prevents opening new positions or increasing existing ones - only position-reducing orders allowed. Account is automatically disabled (can_trade=false). Occurs when: account margin ratio is low but not yet in liquidation, exchange risk management triggered, or account under regulatory restrictions. User must reduce positions or add collateral to exit reduce-only mode.',
                'default_severity' => 'High',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'account_trading_banned',
                'title' => 'Account Trading Banned',
                'description' => 'Sent when account trading is banned due to risk control or compliance',
                'detailed_description' => 'Triggered when exchange APIs return trading ban errors: Binance code -4400 ("Trading quantitative rule - risk control triggered") or Bybit codes 10008 ("Common ban applied"), 10024 ("Compliance rules triggered"), 10027 ("Transactions are banned"), or 110066 ("Trading currently not allowed"). HTTP status is typically 403. Account is automatically disabled (can_trade=false). Occurs when exchange risk management systems detect unusual trading patterns, potential market manipulation, compliance violations, or regulatory restrictions. User must contact exchange support to understand ban reason and resolution requirements. This is often a manual review process.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'account_unauthorized',
                'title' => 'Account Unauthorized',
                'description' => 'Sent when account authentication fails or is unauthorized',
                'detailed_description' => 'Triggered when exchange APIs return unauthorized errors: Binance code -1002 ("Unauthorized") or Bybit code 10007 ("User authentication failed"). HTTP status is typically 401. Account is automatically disabled (can_trade=false). This indicates the exchange rejected the API request authentication entirely - different from invalid credentials as it may occur even with valid keys if account status changed (suspended, frozen, verification lapsed). User must verify account is active on exchange platform, not suspended or restricted, and authentication headers are properly formatted.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'api_key_expired',
                'title' => 'API Key Expired',
                'description' => 'Sent when API key has expired and needs renewal',
                'detailed_description' => 'Triggered when Bybit API returns error code 33004 ("API key expired"). This occurs when the API key has passed its configured expiration date set during key creation on Bybit platform. HTTP status is typically 403. Account is automatically disabled (can_trade=false). User must generate a new API key from Bybit account settings and update the system with new credentials. Bybit allows setting expiration dates (30 days, 90 days, never) when creating API keys for security purposes. Best practice is to use short-lived keys and rotate regularly.',
                'default_severity' => 'Critical',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'insufficient_balance_margin',
                'title' => 'Insufficient Balance/Margin',
                'description' => 'Sent when account has insufficient balance or margin for operations',
                'detailed_description' => 'Triggered when exchange APIs return balance/margin insufficiency errors: Binance codes -2018 ("Balance not sufficient") or -2019 ("Margin not sufficient"), or Bybit codes 110004/110007/110012 ("Insufficient wallet/available balance") or 110044/110045 ("Insufficient available margin/wallet balance"). HTTP status is typically 400. Account is NOT disabled - this is a transient issue. Occurs when attempting to place orders or open positions without sufficient collateral. User must deposit additional funds or close existing positions to free up margin. This is a normal operational constraint, not a critical error.',
                'default_severity' => 'High',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'kyc_verification_required',
                'title' => 'KYC Verification Required',
                'description' => 'Sent when KYC verification is required to continue trading',
                'detailed_description' => 'Triggered when exchange APIs return KYC verification errors: Binance code -4202 ("Adjust leverage KYC failed - enhanced KYC required for leverage >20x") or Bybit code 20096 ("KYC authentication required"). HTTP status is typically 403. Account is NOT disabled - operations are limited until KYC completed. Occurs when: attempting high leverage trading without enhanced verification, accessing restricted features requiring identity verification, or regulatory compliance requirements. User must complete KYC process on exchange platform by submitting identity documents, proof of address, and potentially selfie verification.',
                'default_severity' => 'Medium',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'api_rate_limit_exceeded',
                'title' => 'API Rate Limit Exceeded',
                'description' => 'Sent when API rate limit is exceeded',
                'detailed_description' => 'Triggered when exchange APIs return rate limit errors: HTTP codes 429 (Too Many Requests) or 418 (Binance IP ban escalation), or vendor codes Binance -1003/-1015 (request limits) or Bybit 10006/10018/170005/170222 (various rate limits). Binance tracks weight-based limits (per IP, per UID) and order count limits with interval headers (1s, 1m, 1h). Bybit uses 403 HTTP status for temporary 10-minute IP bans (600 req/5s limit) or retCode-based limits. System automatically backs off using Retry-After header or calculated interval resets. Account NOT disabled. Indicates system making too many requests - usually resolves automatically. May occur during high market volatility when many operations needed.',
                'default_severity' => 'High',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'api_access_denied',
                'title' => 'API Access Denied',
                'description' => 'Sent when API access is denied (ambiguous 401/403)',
                'detailed_description' => 'Triggered when exchange APIs return generic forbidden/unauthorized errors: HTTP 401 (Unauthorized) or HTTP 403 (Forbidden) without specific vendor error codes that map to other canonicals. This is a catch-all for access denial scenarios not covered by more specific notifications (ip_not_whitelisted, invalid_api_key, etc.). For user accounts, account is automatically disabled (can_trade=false). For admin/system accounts, sent to admin only. Indicates authentication or authorization failure but root cause unclear - may be server-side issue, temporary restriction, or configuration problem requiring manual investigation.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'api_system_error',
                'title' => 'API System Error',
                'description' => 'Sent when exchange API encounters system errors',
                'detailed_description' => 'Triggered when exchange APIs return internal system errors: Binance codes -1000 ("Unknown error"), -1007 ("Timeout"), -1008 ("Server overload - request throttled"), or Bybit codes 10016 ("Internal server error"), 10000 ("Server timeout"), 10002 ("Request time exceeds acceptable window"). HTTP status is typically 500, 503, or 408. These are exchange-side infrastructure failures, not client errors. Account NOT disabled. Indicates temporary exchange system issues - database problems, service crashes, or infrastructure overload. System automatically retries with backoff. Admin notification allows monitoring exchange stability and detecting prolonged outages.',
                'default_severity' => 'High',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'api_network_error',
                'title' => 'API Network Error',
                'description' => 'Sent when network errors occur communicating with exchange',
                'detailed_description' => 'Triggered when exchange APIs return network-related errors: Bybit code 170032 ("Network error") or similar network layer failures. No HTTP status code available as request never completes. These are connectivity issues between server and exchange - DNS failures, routing problems, firewall blocks, or Internet connectivity loss. Account NOT disabled. Different from "api_connection_failed" which covers broader connection failures. System automatically retries with backoff. Admin notification allows detecting server-side network issues requiring infrastructure investigation (firewall rules, routing, ISP problems).',
                'default_severity' => 'High',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'exchange_maintenance',
                'title' => 'Exchange Maintenance',
                'description' => 'Sent when exchange is under maintenance or overloaded',
                'detailed_description' => 'Triggered when exchange APIs return service unavailable or server overload errors: HTTP 503 (Service Unavailable), HTTP 504 (Gateway Timeout), or vendor codes Bybit 10019 ("Service restarting"), 170007 ("Backend timeout"), 177002 ("Server busy"). CRITICAL during price crashes when exchanges get overloaded and cannot process requests - positions may be at risk without ability to close them. Account NOT disabled. Indicates exchange infrastructure cannot handle request volume, is undergoing planned maintenance, or experiencing technical issues. System automatically retries with backoff. Users should be aware trading operations delayed/unavailable.',
                'default_severity' => 'High',
                'user_types' => ['user'],
            ],
            [
                'canonical' => 'api_connection_failed',
                'title' => 'API Connection Failed',
                'description' => 'Sent when unable to connect to exchange API',
                'detailed_description' => 'Triggered when API request to exchange fails without receiving HTTP response - connection timeouts, DNS resolution failures, SSL/TLS handshake errors, socket errors, or network unreachable conditions. No http_response_code available but error_message populated with exception details (e.g., "Connection refused", "Connection timed out", "Could not resolve host"). Account NOT disabled. Indicates network or connectivity issues preventing communication with exchange. May be caused by: exchange DNS/infrastructure issues, local network problems, firewall blocking, SSL certificate problems. System automatically retries. Admin notification critical as indicates complete inability to trade or retrieve data.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'server_ip_whitelisted',
                'title' => 'Server IP Whitelisted',
                'description' => 'Sent when server IP is successfully whitelisted on exchange',
                'detailed_description' => 'Triggered by ServerIpNotWhitelistedRepeater when it successfully detects that a previously blocked IP is now whitelisted. This occurs when: (1) User previously received "ip_not_whitelisted" notification (Bybit error 10010), (2) ServerIpNotWhitelistedRepeater automatically created to retry API calls every 1 minute, (3) User added server IP to API key whitelist on exchange platform, (4) Repeater detects successful API call (HTTP 200) after previously receiving 403/10010. This is a positive confirmation notification - trading can now resume. No HTTP error involved - this celebrates successful error resolution.',
                'default_severity' => 'Info',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'symbol_synced',
                'title' => 'Symbol Synced',
                'description' => 'Sent when a symbol is successfully synced with CoinMarketCap',
                'detailed_description' => 'Triggered when the system successfully synchronizes cryptocurrency symbol metadata from CoinMarketCap API including market cap, circulating supply, logo, description, and other reference data. This is an informational notification confirming successful data enrichment for symbols in the system. No HTTP errors involved - this confirms successful API integration. Useful for operational monitoring to track when symbol database is updated with fresh CoinMarketCap data. May be triggered by manual sync operations or scheduled jobs.',
                'default_severity' => 'Info',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'step_error',
                'title' => 'Step Error',
                'description' => 'Sent when a step encounters an error during execution',
                'detailed_description' => 'Triggered when a Step (job execution unit in StepDispatcher system) encounters an unrecoverable exception and transitions to Failed state. Steps are workflow execution units managed by Spatie ModelStates with states: Pending → Dispatched → Running → Completed/Failed. This notification fires when: job throws exception, max retries reached after transient failures, or validation errors prevent execution. Error details include: exception message, stack trace, step canonical, block_uuid for workflow context, and hostname of worker. Not directly API-related but may be triggered by API errors within jobs. Critical as indicates workflow failures requiring manual intervention.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'forbidden_hostname_added',
                'title' => 'Forbidden Hostname Detected',
                'description' => 'Sent when a hostname is forbidden from accessing an exchange API',
                'detailed_description' => 'Triggered when the system detects a server hostname has been permanently banned by exchange and adds it to the forbidden_hostnames tracking. This occurs when: exchange returns permanent ban error (Bybit error 10009 "IP banned by exchange"), ban persists across multiple retry attempts, and system determines ban is not temporary rate limit but permanent restriction. Forbidden hostnames are tracked to prevent wasting resources attempting API calls from banned IPs. HTTP status is typically 403 with vendor error indicating permanent ban. Critical as indicates exchange has blacklisted the server IP - requires using different server/IP or appealing ban with exchange support.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
            [
                'canonical' => 'notification_gateway_error',
                'title' => 'Notification Gateway Error',
                'description' => 'Sent when notification delivery fails due to gateway errors (Pushover/Mail)',
                'detailed_description' => 'Triggered when notification gateways (Pushover for push notifications, Zeptomail for email) fail to deliver messages. Occurs when: Pushover API returns errors (invalid user key, invalid token, rate limits, API down), Zeptomail SMTP/API returns errors (invalid credentials, rate limits, recipient bounces, API down), network failures prevent reaching gateway APIs. HTTP errors from gateways: 400 (invalid parameters), 401 (invalid credentials), 429 (rate limited), 500/503 (gateway infrastructure issues). Critical because notification system failure means users/admins not alerted to important events (trading errors, account issues, liquidations). System may retry or fall back to alternative channels.',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
            ],
        ];
    }
}
