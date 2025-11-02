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
                'default_severity' => $notification['default_severity'],
                'user_types' => json_encode($notification['user_types']),
                'is_active' => $notification['is_active'],
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
                'default_severity' => 'High',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_prices_restart',
                'title' => 'Binance: Price Stream Restart',
                'description' => 'Sent when Binance price monitoring restarts due to symbol changes',
                'default_severity' => 'Info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_websocket_error',
                'title' => 'Binance: WebSocket Error',
                'description' => 'Sent when Binance WebSocket encounters an error',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_invalid_json',
                'title' => 'Binance: Invalid JSON Response',
                'description' => 'Sent when Binance API returns invalid JSON',
                'default_severity' => 'High',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_db_update_error',
                'title' => 'Binance: Database Update Error',
                'description' => 'Sent when database update fails for Binance price data',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'ip_not_whitelisted',
                'title' => 'IP Not Whitelisted',
                'description' => 'Sent when server IP is not whitelisted on exchange API for user account (Bybit 10010 specific)',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_credentials_or_ip',
                'title' => 'API Credentials or IP Issue',
                'description' => 'Sent for Binance error -2015 (ambiguous: could be invalid API key, IP not whitelisted, or insufficient permissions)',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'invalid_api_key',
                'title' => 'Invalid API Key',
                'description' => 'Sent when API key is invalid (Bybit 10003 specific)',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'invalid_signature',
                'title' => 'Invalid API Signature',
                'description' => 'Sent when API signature validation fails (Bybit 10004 specific)',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'insufficient_permissions',
                'title' => 'Insufficient API Permissions',
                'description' => 'Sent when API key lacks required permissions (Bybit 10005 specific)',
                'default_severity' => 'High',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'invalid_api_credentials',
                'title' => 'Invalid API Credentials',
                'description' => 'Sent when API credentials are invalid or API keys are locked',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_in_liquidation',
                'title' => 'Account in Liquidation',
                'description' => 'Sent when user account is in liquidation mode',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_reduce_only_mode',
                'title' => 'Account in Reduce-Only Mode',
                'description' => 'Sent when account is in reduce-only mode - cannot open new positions',
                'default_severity' => 'High',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_trading_banned',
                'title' => 'Account Trading Banned',
                'description' => 'Sent when account trading is banned due to risk control or compliance',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_unauthorized',
                'title' => 'Account Unauthorized',
                'description' => 'Sent when account authentication fails or is unauthorized',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_key_expired',
                'title' => 'API Key Expired',
                'description' => 'Sent when API key has expired and needs renewal',
                'default_severity' => 'Critical',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'insufficient_balance_margin',
                'title' => 'Insufficient Balance/Margin',
                'description' => 'Sent when account has insufficient balance or margin for operations',
                'default_severity' => 'High',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'kyc_verification_required',
                'title' => 'KYC Verification Required',
                'description' => 'Sent when KYC verification is required to continue trading',
                'default_severity' => 'Medium',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_rate_limit_exceeded',
                'title' => 'API Rate Limit Exceeded',
                'description' => 'Sent when API rate limit is exceeded',
                'default_severity' => 'High',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_access_denied',
                'title' => 'API Access Denied',
                'description' => 'Sent when API access is denied (ambiguous 401/403)',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_system_error',
                'title' => 'API System Error',
                'description' => 'Sent when exchange API encounters system errors',
                'default_severity' => 'High',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_network_error',
                'title' => 'API Network Error',
                'description' => 'Sent when network errors occur communicating with exchange',
                'default_severity' => 'High',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'exchange_maintenance',
                'title' => 'Exchange Maintenance',
                'description' => 'Sent when exchange is under maintenance or overloaded',
                'default_severity' => 'High',
                'user_types' => ['user', 'admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_connection_failed',
                'title' => 'API Connection Failed',
                'description' => 'Sent when unable to connect to exchange API',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'server_ip_whitelisted',
                'title' => 'Server IP Whitelisted',
                'description' => 'Sent when server IP is successfully whitelisted on exchange',
                'default_severity' => 'Info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'symbol_synced',
                'title' => 'Symbol Synced',
                'description' => 'Sent when a symbol is successfully synced with CoinMarketCap',
                'default_severity' => 'Info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'step_error',
                'title' => 'Step Error',
                'description' => 'Sent when a step encounters an error during execution',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'forbidden_hostname_added',
                'title' => 'Forbidden Hostname Detected',
                'description' => 'Sent when a hostname is forbidden from accessing an exchange API',
                'default_severity' => 'Critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
        ];
    }
}
