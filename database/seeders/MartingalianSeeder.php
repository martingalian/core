<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * This consolidated seeder combines all schema seeder logic.
     */
    public function run(): void
    {
        // SECTION 1: Create Indicators (SchemaSeeder1, SchemaSeeder9, SchemaSeeder11)
        $this->seedIndicators();

        // SECTION 2: Create API Systems (SchemaSeeder1)
        $apiSystems = $this->seedApiSystems();

        // SECTION 3: Create Quotes (SchemaSeeder1)
        $quotes = $this->seedQuotes();

        // SECTION 4: Create User (SchemaSeeder1)
        $trader = $this->seedUser();

        // SECTION 5: Create Default Trade Configuration (SchemaSeeder1)
        $this->seedTradeConfiguration();

        // SECTION 6: Create Binance Account (SchemaSeeder1)
        $this->seedBinanceAccount($trader, $apiSystems['binance'], $quotes['usdt']);

        // SECTION 7: Create Initial Symbols (SchemaSeeder1, SchemaSeeder2)
        $this->seedSymbols();

        // SECTION 8: Create Base Asset Mappers (SchemaSeeder1, SchemaSeeder2)
        $this->seedBinanceBaseAssetMappers($apiSystems['binance']);

        // SECTION 9: Create StepsDispatcher (SchemaSeeder3, StepsDispatcherSeeder)
        $this->seedStepsDispatchers();

        // SECTION 10: Update Trade Configuration (SchemaSeeder4) - SKIPPED: column doesn't exist in final schema
        // $this->updateTradeConfiguration();

        // SECTION 11: Create Martingalian (SchemaSeeder5)
        $this->seedMartingalian();

        // SECTION 12: Add Additional Symbol Batches (SchemaSeeder6-8, SchemaSeeder13-15, SchemaSeeder18)
        $this->seedAdditionalSymbols();

        // SECTION 13: Update Existing Positions (SchemaSeeder10)
        $this->updatePositionProfitPrices();

        // SECTION 14: Update Exchange Symbols (SchemaSeeder2, SchemaSeeder12)
        $this->updateExchangeSymbols();

        // SECTION 15: Migrate Account Credentials (SchemaSeeder16)
        $this->migrateAccountCredentials();

        // SECTION 16: Migrate Martingalian Credentials (SchemaSeeder17)
        $this->migrateMartingalianCredentials();

        // SECTION 17: Setup Bybit Integration (SchemaSeeder19, SchemaSeeder20, SchemaSeeder21)
        $this->setupBybitIntegration($trader, $apiSystems['bybit'], $quotes['usdt']);

        // SECTION 18: Cleanup Bybit Account Credentials (SchemaSeeder22)
        $this->cleanupAccountCredentials();

        // SECTION 19: Add Notification Channels (SchemaSeeder23)
        $this->addNotificationChannels();

        // SECTION 20: Move Admin Pushover Key (SchemaSeeder24)
        $this->moveAdminPushoverKey();

        // SECTION 21: Seed Servers
        $this->seedServers();

        // SECTION 22: Seed Notifications
        $this->seedNotifications();
    }

    /**
     * Get all symbol CMC IDs consolidated from all schema seeders.
     * This combines initial symbols, high volatility tokens, and additional batches.
     *
     * NOTE: Symbols are now dynamically discovered from exchanges.
     * This seeder no longer pre-populates symbols.
     */
    public function getAllSymbolCmcIds(): array
    {
        return [];
    }

    /**
     * Seed all indicators.
     */
    public function seedIndicators(): void
    {
        // From SchemaSeeder1
        Indicator::create([
            'canonical' => 'emas-same-direction',
            'is_active' => true,
            'class' => "Martingalian\Core\Indicators\RefreshData\EMAsSameDirection",
            'is_computed' => true,
        ]);

        Indicator::create([
            'canonical' => 'candle-comparison',
            'type' => 'refresh-data',
            'is_active' => true,
            'is_computed' => false,
            'class' => "Martingalian\Core\Indicators\Ongoing\CandleComparisonIndicator",
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
            'is_active' => false, // For now, this indicator is causing issues.
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

        // From SchemaSeeder9 - Update and Create
        Indicator::query()->where('canonical', 'candle-comparison')
            ->update(['class' => 'Martingalian\Core\Indicators\RefreshData\CandleComparisonIndicator']);

        Indicator::create([
            'type' => 'history',
            'is_active' => true,
            'is_computed' => true,
            'canonical' => 'candle',
            'parameters' => ['results' => 1],
            'class' => "Martingalian\Core\Indicators\History\CandleIndicator",
        ]);

        // From SchemaSeeder11
        Indicator::create([
            'canonical' => 'price-volatility',
            'is_active' => true,
            'type' => 'reports',
            'class' => "Martingalian\Core\Indicators\Reports\PriceVolatilityIndicator",
            'is_computed' => true,
            'parameters' => ['results' => 2000],
        ]);

        Indicator::where('canonical', 'candle')->where('type', 'history')->first()->update(['type' => 'dashboard']);
    }

    /**
     * Seed API systems and return them for reference.
     */
    public function seedApiSystems(): array
    {
        $binance = ApiSystem::firstOrCreate(
            ['canonical' => 'binance'],
            [
                'name' => 'Binance',
                'logo_url' => 'https://public.bnbstatic.com/static/images/common/favicon.ico',
                'is_exchange' => true,
                'taapi_canonical' => 'binancefutures',
            ]
        );

        $bybit = ApiSystem::firstOrCreate(
            ['canonical' => 'bybit'],
            [
                'name' => 'Bybit',
                'logo_url' => 'https://www.bybit.com/favicon.ico',
                'is_exchange' => true,
                'taapi_canonical' => 'bybit',
            ]
        );

        $coinmarketcap = ApiSystem::firstOrCreate(
            ['canonical' => 'coinmarketcap'],
            [
                'name' => 'CoinmarketCap',
                'is_exchange' => false,
            ]
        );

        $alternativeMe = ApiSystem::firstOrCreate(
            ['canonical' => 'alternativeme'],
            [
                'name' => 'AlternativeMe',
                'is_exchange' => false,
            ]
        );

        $taapi = ApiSystem::firstOrCreate(
            ['canonical' => 'taapi'],
            [
                'name' => 'Taapi',
                'is_exchange' => false,
            ]
        );

        return [
            'binance' => $binance,
            'bybit' => $bybit,
            'coinmarketcap' => $coinmarketcap,
            'alternativeme' => $alternativeMe,
            'taapi' => $taapi,
        ];
    }

    /**
     * Seed quotes and return them for reference.
     */
    public function seedQuotes(): array
    {
        $usdt = Quote::create([
            'canonical' => 'USDT',
            'name' => 'USDT (Tether)',
        ]);

        $usdc = Quote::create([
            'canonical' => 'USDC',
            'name' => 'USDC (USD Coin)',
        ]);

        $bfusdt = Quote::create([
            'canonical' => 'BFUSDT',
            'name' => 'BFUSDT (USD Coin)',
        ]);

        return [
            'usdt' => $usdt,
            'usdc' => $usdc,
            'bfusdt' => $bfusdt,
        ];
    }

    /**
     * Seed the default user/trader.
     */
    public function seedUser(): User
    {
        $userData = [
            'name' => env('TRADER_NAME'),
            'email' => env('TRADER_EMAIL'),
            'password' => bcrypt('password'),
            'is_active' => true,
            'is_admin' => true,
            'pushover_key' => env('PUSHOVER_USER_KEY'),
            'notification_channels' => ['mail', 'pushover'],
        ];

        return User::create($userData);
    }

    /**
     * Seed the default trade configuration.
     */
    public function seedTradeConfiguration(): void
    {
        TradeConfiguration::create([
            'is_default' => true,
            'canonical' => 'standard',
            'description' => 'Standard trade configuration, default for all tokens',
            'indicator_timeframes' => ['1h', '4h', '6h', '12h', '1d'],
        ]);
    }

    /**
     * Seed the Binance account for the trader.
     */
    public function seedBinanceAccount(User $trader, ApiSystem $binance, Quote $usdt): void
    {
        Account::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Main Binance Account',
            'user_id' => $trader->id,
            'api_system_id' => $binance->id,
            'portfolio_quote_id' => $usdt->id,
            'trading_quote_id' => $usdt->id,
            'trade_configuration_id' => 1,
            'binance_api_key' => env('BINANCE_API_KEY'),
            'binance_api_secret' => env('BINANCE_API_SECRET'),
        ]);
    }

    /**
     * Seed initial symbols from SchemaSeeder1 and SchemaSeeder2.
     */
    public function seedSymbols(): void
    {
        $allSymbolCmcIds = $this->getAllSymbolCmcIds();

        $rows = [];
        $now = now();

        foreach ($allSymbolCmcIds as $cmcId) {
            $rows[] = [
                'cmc_id' => $cmcId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Symbol::insert($rows);
    }

    /**
     * Seed Binance base asset mappers.
     */
    public function seedBinanceBaseAssetMappers(ApiSystem $binance): void
    {
        // From SchemaSeeder1 - BONK mapping
        BaseAssetMapper::create([
            'api_system_id' => $binance->id,
            'symbol_token' => 'BONK',
            'exchange_token' => '1000BONK',
        ]);

        // From SchemaSeeder2 - BROCCOLI mapping
        BaseAssetMapper::create([
            'api_system_id' => $binance->id,
            'symbol_token' => 'BROCCOLI',
            'exchange_token' => 'BROCCOLI714',
        ]);
    }

    /**
     * Seed steps dispatchers.
     */
    public function seedStepsDispatchers(): void
    {
        // From StepsDispatcherSeeder - 10 group-based records
        $groups = [
            'alpha', 'beta', 'gamma', 'delta', 'epsilon',
            'zeta', 'eta', 'theta', 'iota', 'kappa',
        ];

        $now = now();

        // Avoid duplicates
        $existing = DB::table('steps_dispatcher')
            ->whereIn('group', $groups)
            ->pluck('group')
            ->all();

        $missing = array_values(array_diff($groups, $existing));

        if (! empty($missing)) {
            $rows = array_map(static function (string $g) use ($now) {
                return [
                    'group' => $g,
                    'can_dispatch' => true,
                    'current_tick_id' => null,
                    'last_tick_completed' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $missing);

            DB::table('steps_dispatcher')->insert($rows);
        }
    }

    /**
     * Update trade configuration with hedge quantity laddering.
     */
    public function updateTradeConfiguration(): void
    {
        TradeConfiguration::query()->update([
            'hedge_quantity_laddering_percentages' => [110, 75, 40, 20],
        ]);
    }

    /**
     * Seed the Martingalian singleton record.
     */
    public function seedMartingalian(): void
    {
        Martingalian::create([
            'allow_opening_positions' => true,
            'admin_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
            'admin_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY'),
            'admin_user_email' => env('ADMIN_USER_EMAIL'),
        ]);
    }

    /**
     * Seed additional symbol batches from various schema seeders.
     * This method is now empty as all symbols are seeded in seedSymbols().
     */
    public function seedAdditionalSymbols(): void
    {
        // All symbols are now seeded in seedSymbols() using getAllSymbolCmcIds()
    }

    /**
     * Update specific positions with hardcoded profit prices.
     */
    public function updatePositionProfitPrices(): void
    {
        $positionData = [
            1072 => 3.04389988, // TONUSDT
            1073 => 0.00318842, // DEGENUSDT
            1064 => 321.68888500, // AAVEUSDT
            1063 => 0.43392483, // ARKUSDT
            1060 => 0.30980976, // SUSDT
            1061 => 0.20292457, // PNUTUSDT
            989 => 566.23485550, // BCHUSDT
            983 => 0.95447097, // ONDUSDT
            974 => 0.26890577, // SANDUSDT
            973 => 0.82540880, // ALGUSDT
            782 => 124.80333000, // LTCUSDT
            733 => 887.30647000, // BNBUSDT
        ];

        foreach ($positionData as $id => $profitPrice) {
            $position = Position::find($id);

            if ($position) {
                $position->updateSaving([
                    'first_profit_price' => $profitPrice,
                ]);
            }
        }
    }

    /**
     * Update exchange symbols settings.
     */
    public function updateExchangeSymbols(): void
    {
        // From SchemaSeeder2 - Disable all current tokens
        // NOTE: Commented out because CoreSymbolDataSeeder now handles is_active correctly
        // ExchangeSymbol::query()->update(['is_tradeable' => false, 'is_active' => false]);

        // From SchemaSeeder12 - Set limit quantity multipliers
        ExchangeSymbol::query()->update([
            'limit_quantity_multipliers' => [2, 2, 2, 2],
        ]);
    }

    /**
     * Migrate exchange credentials from JSON to dedicated columns.
     */
    public function migrateAccountCredentials(): void
    {
        $account = Account::find(1);

        if ($account && isset($account->credentials['api_key'])) {
            $account->binance_api_key = $account->credentials['api_key'];
            $account->binance_api_secret = $account->credentials['api_secret'];
            $account->save();
        }
    }

    /**
     * Migrate Martingalian credentials from environment to database.
     */
    public function migrateMartingalianCredentials(): void
    {
        $martingalian = Martingalian::find(1);

        if ($martingalian) {
            $martingalian->binance_api_key = env('BINANCE_API_KEY');
            $martingalian->binance_api_secret = env('BINANCE_API_SECRET');
            $martingalian->coinmarketcap_api_key = env('COINMARKETCAP_API_KEY');
            $martingalian->taapi_secret = env('TAAPI_SECRET');
            $martingalian->save();
        }
    }

    /**
     * Setup Bybit integration: API system, mappers, and account.
     */
    public function setupBybitIntegration(User $trader, ApiSystem $bybitApiSystem, Quote $usdt): void
    {
        // SchemaSeeder19: Create Bybit base asset mappers
        $mappers = [
            ['symbol_token' => 'BONK', 'exchange_token' => '1000BONK'],
            ['symbol_token' => 'BROCCOLI', 'exchange_token' => 'BROCCOLI714'],
        ];

        foreach ($mappers as $mapper) {
            BaseAssetMapper::updateOrCreate(
                [
                    'api_system_id' => $bybitApiSystem->id,
                    'symbol_token' => $mapper['symbol_token'],
                ],
                [
                    'exchange_token' => $mapper['exchange_token'],
                ]
            );
        }

        // SchemaSeeder21: Create Bybit account
        $existingBybitAccount = Account::where('user_id', $trader->id)
            ->where('api_system_id', $bybitApiSystem->id)
            ->first();

        if (! $existingBybitAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Bybit Account',
                'user_id' => $trader->id,
                'api_system_id' => $bybitApiSystem->id,
                'portfolio_quote_id' => $usdt->id,
                'trading_quote_id' => $usdt->id,
                'trade_configuration_id' => 1,
                'bybit_api_key' => env('BYBIT_API_KEY'),
                'bybit_api_secret' => env('BYBIT_API_SECRET'),
            ]);
        }
    }

    /**
     * Cleanup Bybit credentials from Binance account.
     */
    public function cleanupAccountCredentials(): void
    {
        $binanceAccount = Account::find(1);

        if ($binanceAccount) {
            $binanceAccount->update([
                'bybit_api_key' => null,
                'bybit_api_secret' => null,
            ]);
        }

        // Ensure account 2 (Bybit) has credentials
        $bybitAccount = Account::find(2);

        if ($bybitAccount) {
            $hasKey = ! empty($bybitAccount->bybit_api_key);
            $hasSecret = ! empty($bybitAccount->bybit_api_secret);

            if (! $hasKey || ! $hasSecret) {
                $bybitAccount->update([
                    'bybit_api_key' => env('BYBIT_API_KEY'),
                    'bybit_api_secret' => env('BYBIT_API_SECRET'),
                ]);
            }
        }
    }

    /**
     * Add notification channels to Martingalian.
     */
    public function addNotificationChannels(): void
    {
        $martingalian = Martingalian::find(1);

        if ($martingalian) {
            $martingalian->bybit_api_key = env('BYBIT_API_KEY');
            $martingalian->bybit_api_secret = env('BYBIT_API_SECRET');
            $martingalian->notification_channels = [
                'pushover',
                'mail',
            ];
            $martingalian->save();
        }
    }

    /**
     * Move admin Pushover key from config to database.
     */
    public function moveAdminPushoverKey(): void
    {
        $martingalian = Martingalian::find(1);

        if ($martingalian) {
            $adminPushoverKey = config('martingalian.admin_user_pushover_key');

            if ($adminPushoverKey) {
                $martingalian->admin_pushover_user_key = $adminPushoverKey;
                $martingalian->save();
            }
        }
    }

    /**
     * Seed the servers table with the current server.
     */
    public function seedServers(): void
    {
        $servers = [
            [
                'hostname' => 'worker-5',
                'ip_address' => '157.180.69.25',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker-5',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
            ],
            [
                'hostname' => 'worker-4',
                'ip_address' => '46.62.156.246',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker-4',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
            ],
            [
                'hostname' => 'worker-3',
                'ip_address' => '46.62.255.137',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker-3',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
            ],
            [
                'hostname' => 'worker-2',
                'ip_address' => '37.27.83.74',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker-2',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
            ],
            [
                'hostname' => 'worker-1',
                'ip_address' => '46.62.215.85',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'worker-1',
                'description' => 'Worker server for job processing',
                'type' => 'worker',
            ],
            [
                'hostname' => 'ingestion',
                'ip_address' => '46.62.203.165',
                'is_apiable' => true,
                'needs_whitelisting' => true,
                'own_queue_name' => 'ingestion',
                'description' => 'Ingestion server - cron & dispatch',
                'type' => 'ingestion',
            ],
            [
                'hostname' => 'redis',
                'ip_address' => '46.62.215.70',
                'is_apiable' => false,
                'needs_whitelisting' => false,
                'own_queue_name' => null,
                'description' => 'Redis cache server',
                'type' => 'redis',
            ],
            [
                'hostname' => 'database',
                'ip_address' => '46.62.218.172',
                'is_apiable' => false,
                'needs_whitelisting' => false,
                'own_queue_name' => null,
                'description' => 'Database server',
                'type' => 'database',
            ],
            [
                'hostname' => 'frontend',
                'ip_address' => '65.21.5.150',
                'is_apiable' => false,
                'needs_whitelisting' => false,
                'own_queue_name' => null,
                'description' => 'Frontend application server',
                'type' => 'frontend',
            ],
        ];

        foreach ($servers as $server) {
            DB::table('servers')->insert(array_merge($server, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * Seed common notification definitions.
     */
    public function seedNotifications(): void
    {
        $notifications = [
            [
                'canonical' => 'stale_price_detected',
                'title' => 'Stale Price Detected',
                'description' => 'Sent when exchange symbol prices have not been updated within expected timeframe',
                'default_severity' => 'high',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_prices_restart',
                'title' => 'Binance: Price Stream Restart',
                'description' => 'Sent when Binance price monitoring restarts due to symbol changes',
                'default_severity' => 'info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_websocket_error',
                'title' => 'Binance: WebSocket Error',
                'description' => 'Sent when Binance WebSocket encounters an error',
                'default_severity' => 'critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_invalid_json',
                'title' => 'Binance: Invalid JSON Response',
                'description' => 'Sent when Binance API returns invalid JSON',
                'default_severity' => 'high',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'binance_db_update_error',
                'title' => 'Binance: Database Update Error',
                'description' => 'Sent when database update fails for Binance price data',
                'default_severity' => 'critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            // User-facing notifications (sent to user only - user needs to take action)
            [
                'canonical' => 'ip_not_whitelisted',
                'title' => 'IP Not Whitelisted',
                'description' => 'Sent when server IP is not whitelisted on exchange API for user account',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'invalid_api_credentials',
                'title' => 'Invalid API Credentials',
                'description' => 'Sent when API credentials are invalid or API keys are locked',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_in_liquidation',
                'title' => 'Account in Liquidation',
                'description' => 'Sent when user account is in liquidation mode',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_reduce_only_mode',
                'title' => 'Account in Reduce-Only Mode',
                'description' => 'Sent when account is in reduce-only mode - cannot open new positions',
                'default_severity' => 'high',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_trading_banned',
                'title' => 'Account Trading Banned',
                'description' => 'Sent when account trading is banned due to risk control or compliance',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'account_unauthorized',
                'title' => 'Account Unauthorized',
                'description' => 'Sent when account authentication fails or is unauthorized',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_key_expired',
                'title' => 'API Key Expired',
                'description' => 'Sent when API key has expired and needs renewal',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_credentials_or_ip',
                'title' => 'API Credentials or IP Issue',
                'description' => 'Sent when API call fails with ambiguous error (could be credentials, IP, or permissions)',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'invalid_api_key',
                'title' => 'Invalid API Key',
                'description' => 'Sent when API key is invalid (Bybit specific)',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'invalid_signature',
                'title' => 'Invalid API Signature',
                'description' => 'Sent when API signature is invalid (Bybit specific)',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'insufficient_permissions',
                'title' => 'Insufficient API Permissions',
                'description' => 'Sent when API key lacks required permissions (Bybit specific)',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'insufficient_balance_margin',
                'title' => 'Insufficient Balance/Margin',
                'description' => 'Sent when account has insufficient balance or margin for operations',
                'default_severity' => 'high',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'kyc_verification_required',
                'title' => 'KYC Verification Required',
                'description' => 'Sent when KYC verification is required to continue trading',
                'default_severity' => 'medium',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'bounce_alert_to_pushover',
                'title' => 'Email Delivery Failed',
                'description' => 'Sent via Pushover when user email bounces (soft or hard bounce)',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],

            // Admin-only notifications (system-level issues)
            [
                'canonical' => 'api_rate_limit_exceeded',
                'title' => 'API Rate Limit Exceeded',
                'description' => 'Sent when API rate limit is exceeded',
                'default_severity' => 'high',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_access_denied',
                'title' => 'API Access Denied',
                'description' => 'Sent when API access is denied (ambiguous 401/403)',
                'default_severity' => 'critical',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_system_error',
                'title' => 'API System Error',
                'description' => 'Sent when exchange API encounters system errors',
                'default_severity' => 'high',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_network_error',
                'title' => 'API Network Error',
                'description' => 'Sent when network errors occur communicating with exchange',
                'default_severity' => 'high',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'exchange_maintenance',
                'title' => 'Exchange Maintenance',
                'description' => 'Sent when exchange is under maintenance or overloaded',
                'default_severity' => 'high',
                'user_types' => ['user'],
                'is_active' => true,
            ],
            [
                'canonical' => 'api_connection_failed',
                'title' => 'API Connection Failed',
                'description' => 'Sent when unable to connect to exchange API',
                'default_severity' => 'critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'server_ip_whitelisted',
                'title' => 'Server IP Whitelisted',
                'description' => 'Sent when server IP is successfully whitelisted on exchange',
                'default_severity' => 'info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'symbol_synced',
                'title' => 'Symbol Synced',
                'description' => 'Sent when a symbol is successfully synced with CoinMarketCap',
                'default_severity' => 'info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'step_error',
                'title' => 'Step Error',
                'description' => 'Sent when a step encounters an error during execution',
                'default_severity' => 'critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'forbidden_hostname_added',
                'title' => 'Forbidden Hostname Detected',
                'description' => 'Sent when a hostname is forbidden from accessing an exchange API',
                'default_severity' => 'critical',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'uncategorized_notification',
                'title' => 'Uncategorized Notification',
                'description' => 'Fallback notification type for messages without a specific canonical identifier',
                'default_severity' => 'info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'symbol_delisting_positions_detected',
                'title' => 'Symbol Delisting - Open Positions Detected',
                'description' => 'Sent when a symbol delivery date changes indicating delisting, and open positions exist requiring manual review',
                'default_severity' => 'high',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'price_spike_check_symbol_error',
                'title' => 'Price Spike Check - Symbol Error',
                'description' => 'Sent when price spike check fails due to missing symbol data or calculation errors',
                'default_severity' => 'medium',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'exchange_symbol_no_taapi_data',
                'title' => 'Exchange Symbol Auto-Deactivated - No TAAPI Data',
                'description' => 'Sent when an exchange symbol is automatically deactivated due to consistent lack of TAAPI indicator data',
                'default_severity' => 'info',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
            [
                'canonical' => 'symbol_cmc_id_not_found',
                'title' => 'Symbol Not Found on CoinMarketCap',
                'description' => 'Sent when a symbol cannot be found on CoinMarketCap during symbol discovery',
                'default_severity' => 'medium',
                'user_types' => ['admin'],
                'is_active' => true,
            ],
        ];

        foreach ($notifications as $notification) {
            DB::table('notifications')->insert([
                'canonical' => $notification['canonical'],
                'title' => $notification['title'],
                'description' => $notification['description'],
                'detailed_description' => $notification['detailed_description'] ?? $notification['description'],
                'default_severity' => $notification['default_severity'],
                'user_types' => json_encode($notification['user_types']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

}
