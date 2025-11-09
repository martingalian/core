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

        // SECTION 23: Seed Throttle Rules
        $this->seedThrottleRules();
    }

    /**
     * Get all symbol CMC IDs consolidated from all schema seeders.
     * This combines initial symbols, high volatility tokens, and additional batches.
     */
    public function getAllSymbolCmcIds(): array
    {
        return array_unique([
            // SchemaSeeder1 - Initial 25 symbols
            52,    // XRP
            1839,  // BNB
            5426,  // SOL
            74,    // DOGE
            2010,  // ADA
            1975,  // LINK
            5805,  // AVAX
            11419, // TON
            512,   // XLM
            20947, // SUI
            1831,  // BCH
            6636,  // DOT
            2,     // LTC
            32196, // HYPE
            11092, // BGB
            7083,  // UNI
            21159, // ONDO
            6535,  // NEAR
            8916,  // ICP
            1321,  // ETC
            22974, // TAO
            7278,  // AAVE
            5690,  // RENDER
            3077,  // VET
            28321, // POL
            3794,  // ATOM
            4030,  // ALGO
            2280,  // FIL
            23095, // BONK

            // SchemaSeeder2 - High volatility tokens (40 symbols)
            36920, // DMC
            36410, // MYX
            36861, // NEWT
            12894, // SQD
            31525, // TAIKO
            29210, // JUP
            30171, // ENA
            20873, // LEVER
            34466, // PENGU
            26198, // BDXN
            7232,  // ALPHA
            33788, // PNUT
            36922, // HUSDT
            34034, // OLUSDT
            7978,  // FIDA
            36775, // IDOL
            34993, // SWARMS
            1586,  // ARK
            14783, // MAGIC
            3978,  // CHR
            19966, // QUICK
            32325, // PUFFER
            22461, // HFT
            35168, // 1000X
            35430, // BID
            22861, // TIA
            36671, // SAHARA
            14806, // PEOPLE
            24924, // SWELL
            28382, // MYRO
            36713, // RESOLV
            10974, // CHESS
            35749, // BROCCOLI
            34103, // AIXBT
            36369, // HAEDAL
            28933, // XAI
            10688, // YGG
            30096, // DEGEN
            28504, // JOE
            15678, // VOXEL

            // SchemaSeeder6
            1437,
            3155,
            7226,
            32684,
            2011,
            6210,

            // SchemaSeeder7
            7129, // USTC
            5864, // YFI

            // SchemaSeeder8
            1, // BTC

            // SchemaSeeder13
            1720,  // IOTA
            2566,  // ONT
            1684,  // QTUM
            1697,  // BAT
            1376,  // NEO
            2469,  // ZIL
            11289, // SPELL
            37566, // DAR
            7501,  // WOO
            18876, // APE
            7737,  // API3
            18069, // GMT
            4558,  // FLOW
            7080,  // GALA

            // SchemaSeeder14
            6958,  // ACH
            29270, // AERO
            29676, // AEVO
            8766,  // ALICE
            6783,  // AXS
            10903, // C98
            4066,  // CHZ
            6538,  // CRV
            131,   // DASH
            4092,  // DUSK
            28324, // DYDX
            6892,  // EGLD
            2130,  // ENJ
            3773,  // FET
            3513,  // FTM
            4195,  // FTT
            11857, // GMX
            10603, // IMX
            8425,  // JASMY
            4846,  // KAVA
            3640,  // LPT
            1966,  // MANA
            8536,  // MASK
            8646,  // MINA
            6536,  // OM
            9481,  // PENDLE
            8526,  // RAY
            30843, // REZ
            7653,  // ROSE
            4157,  // RUNE
            8119,  // SFP
            2586,  // SNX
            28081, // SPX
            4847,  // STX
            36405, // SXT
            2416,  // THETA
            7725,  // TRU
            7288,  // XVS
            1698,  // ZEN
            1896,  // ZRX

            // SchemaSeeder15
            11841, // ARB
            21533, // LISTA
            1732,  // NMR
            28827, // OMNI
            2539,  // REN
            26998, // SCR
            5824,  // SLP
            1759,  // SNT
            18934, // STG
            6758,  // SUSHI
            35892, // TUT
            35421, // VINE
            328,   // XMR
            29711, // ZRC

            // SchemaSeeder18
            9329,  // CELO
            1934,  // LRC
            3217,  // ONG
            11294, // RARE
            30372, // SAGA
        ]);
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
        ExchangeSymbol::query()->update(['is_tradeable' => false, 'is_active' => false]);

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
        DB::table('servers')->insert([
            'hostname' => gethostname(),
            'ip_address' => gethostbyname(gethostname()),
            'type' => 'ingestion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * Seed common throttle rules.
     */
    public function seedThrottleRules(): void
    {
        $throttleRules = [
            // General throttle intervals
            [
                'canonical' => 'throttle_900',
                'description' => 'Throttle for 15 minutes (900 seconds)',
                'throttle_seconds' => 900,
                'is_active' => true,
            ],
            [
                'canonical' => 'throttle_1800',
                'description' => 'Throttle for 30 minutes (1800 seconds)',
                'throttle_seconds' => 1800,
                'is_active' => true,
            ],
            [
                'canonical' => 'throttle_3600',
                'description' => 'Throttle for 1 hour (3600 seconds)',
                'throttle_seconds' => 3600,
                'is_active' => true,
            ],
            [
                'canonical' => 'symbol_synced',
                'description' => 'Throttle symbol synced notifications',
                'throttle_seconds' => 3600,
                'is_active' => true,
            ],
            [
                'canonical' => 'step_error',
                'description' => 'Throttle step error notifications',
                'throttle_seconds' => 900,
                'is_active' => true,
            ],
            [
                'canonical' => 'forbidden_hostname_added',
                'description' => 'Throttle forbidden hostname notifications',
                'throttle_seconds' => 3600,
                'is_active' => true,
            ],

            // Supervisor restart throttles (1 minute default)
            ['canonical' => 'binance_prices_restart', 'throttle_seconds' => 60, 'description' => 'Binance: Supervisor restart notification', 'is_active' => true],
            ['canonical' => 'bybit_prices_restart', 'throttle_seconds' => 60, 'description' => 'Bybit: Supervisor restart notification', 'is_active' => true],

            // WebSocket notification throttles (15 minutes default)
            ['canonical' => 'websocket_error', 'throttle_seconds' => 900, 'description' => 'WebSocket: Generic error occurred', 'is_active' => true],
            ['canonical' => 'websocket_reconnected', 'throttle_seconds' => 900, 'description' => 'WebSocket: Successfully reconnected', 'is_active' => true],
            ['canonical' => 'websocket_connection_failed', 'throttle_seconds' => 900, 'description' => 'WebSocket: Connection failed', 'is_active' => true],
            ['canonical' => 'websocket_closed_with_details', 'throttle_seconds' => 900, 'description' => 'WebSocket: Connection closed with details', 'is_active' => true],
            ['canonical' => 'websocket_reconnect_attempt', 'throttle_seconds' => 900, 'description' => 'WebSocket: Reconnection attempt', 'is_active' => true],
            ['canonical' => 'websocket_error_3', 'throttle_seconds' => 900, 'description' => 'WebSocket: Error code 3', 'is_active' => true],
            ['canonical' => 'binance_no_symbols', 'throttle_seconds' => 900, 'description' => 'Binance: No symbols available', 'is_active' => true],
            ['canonical' => 'bybit_no_symbols', 'throttle_seconds' => 900, 'description' => 'Bybit: No symbols available', 'is_active' => true],
            ['canonical' => 'binance_websocket_error', 'throttle_seconds' => 900, 'description' => 'Binance: WebSocket error', 'is_active' => true],
            ['canonical' => 'bybit_websocket_error', 'throttle_seconds' => 900, 'description' => 'Bybit: WebSocket error', 'is_active' => true],
            ['canonical' => 'binance_invalid_json', 'throttle_seconds' => 900, 'description' => 'Binance: Invalid JSON received', 'is_active' => true],
            ['canonical' => 'bybit_invalid_json', 'throttle_seconds' => 900, 'description' => 'Bybit: Invalid JSON received', 'is_active' => true],
            ['canonical' => 'binance_db_update_error', 'throttle_seconds' => 900, 'description' => 'Binance: Database update error', 'is_active' => true],
            ['canonical' => 'bybit_db_update_error', 'throttle_seconds' => 900, 'description' => 'Bybit: Database update error', 'is_active' => true],
            ['canonical' => 'binance_db_insert_error', 'throttle_seconds' => 900, 'description' => 'Binance: Database insert error', 'is_active' => true],
            ['canonical' => 'bybit_db_insert_error', 'throttle_seconds' => 900, 'description' => 'Bybit: Database insert error', 'is_active' => true],

            // API Exception Handler throttles (API-system-specific to prevent cross-API throttling)
            // Binance
            ['canonical' => 'binance_ip_not_whitelisted', 'throttle_seconds' => 900, 'description' => 'Binance: Worker IP is not whitelisted on API', 'is_active' => true],
            ['canonical' => 'binance_api_rate_limit_exceeded', 'throttle_seconds' => 1800, 'description' => 'Binance: API rate limit exceeded', 'is_active' => true],
            ['canonical' => 'binance_api_connection_failed', 'throttle_seconds' => 900, 'description' => 'Binance: Unable to connect to API', 'is_active' => true],
            ['canonical' => 'binance_invalid_api_credentials', 'throttle_seconds' => 1800, 'description' => 'Binance: Invalid API credentials for account', 'is_active' => true],
            ['canonical' => 'binance_exchange_maintenance', 'throttle_seconds' => 3600, 'description' => 'Binance: API is under maintenance or unavailable', 'is_active' => true],

            // Bybit
            ['canonical' => 'bybit_ip_not_whitelisted', 'throttle_seconds' => 900, 'description' => 'Bybit: Worker IP is not whitelisted on API', 'is_active' => true],
            ['canonical' => 'bybit_api_rate_limit_exceeded', 'throttle_seconds' => 1800, 'description' => 'Bybit: API rate limit exceeded', 'is_active' => true],
            ['canonical' => 'bybit_api_connection_failed', 'throttle_seconds' => 900, 'description' => 'Bybit: Unable to connect to API', 'is_active' => true],
            ['canonical' => 'bybit_invalid_api_credentials', 'throttle_seconds' => 1800, 'description' => 'Bybit: Invalid API credentials for account', 'is_active' => true],
            ['canonical' => 'bybit_exchange_maintenance', 'throttle_seconds' => 3600, 'description' => 'Bybit: API is under maintenance or unavailable', 'is_active' => true],

            // Taapi
            ['canonical' => 'taapi_ip_not_whitelisted', 'throttle_seconds' => 900, 'description' => 'Taapi: Worker IP is not whitelisted on API', 'is_active' => true],
            ['canonical' => 'taapi_api_rate_limit_exceeded', 'throttle_seconds' => 1800, 'description' => 'Taapi: API rate limit exceeded', 'is_active' => true],
            ['canonical' => 'taapi_api_connection_failed', 'throttle_seconds' => 900, 'description' => 'Taapi: Unable to connect to API', 'is_active' => true],
            ['canonical' => 'taapi_invalid_api_credentials', 'throttle_seconds' => 1800, 'description' => 'Taapi: Invalid API credentials for account', 'is_active' => true],
            ['canonical' => 'taapi_exchange_maintenance', 'throttle_seconds' => 3600, 'description' => 'Taapi: API is under maintenance or unavailable', 'is_active' => true],

            // AlternativeMe
            ['canonical' => 'alternativeme_ip_not_whitelisted', 'throttle_seconds' => 900, 'description' => 'Alternativeme: Worker IP is not whitelisted on API', 'is_active' => true],
            ['canonical' => 'alternativeme_api_rate_limit_exceeded', 'throttle_seconds' => 1800, 'description' => 'Alternativeme: API rate limit exceeded', 'is_active' => true],
            ['canonical' => 'alternativeme_api_connection_failed', 'throttle_seconds' => 900, 'description' => 'Alternativeme: Unable to connect to API', 'is_active' => true],
            ['canonical' => 'alternativeme_invalid_api_credentials', 'throttle_seconds' => 1800, 'description' => 'Alternativeme: Invalid API credentials for account', 'is_active' => true],
            ['canonical' => 'alternativeme_exchange_maintenance', 'throttle_seconds' => 3600, 'description' => 'Alternativeme: API is under maintenance or unavailable', 'is_active' => true],

            // CoinMarketCap
            ['canonical' => 'coinmarketcap_ip_not_whitelisted', 'throttle_seconds' => 900, 'description' => 'Coinmarketcap: Worker IP is not whitelisted on API', 'is_active' => true],
            ['canonical' => 'coinmarketcap_api_rate_limit_exceeded', 'throttle_seconds' => 1800, 'description' => 'Coinmarketcap: API rate limit exceeded', 'is_active' => true],
            ['canonical' => 'coinmarketcap_api_connection_failed', 'throttle_seconds' => 900, 'description' => 'Coinmarketcap: Unable to connect to API', 'is_active' => true],
            ['canonical' => 'coinmarketcap_invalid_api_credentials', 'throttle_seconds' => 1800, 'description' => 'Coinmarketcap: Invalid API credentials for account', 'is_active' => true],
            ['canonical' => 'coinmarketcap_exchange_maintenance', 'throttle_seconds' => 3600, 'description' => 'Coinmarketcap: API is under maintenance or unavailable', 'is_active' => true],

            // Critical account status notifications (exchange-specific)
            // Binance
            ['canonical' => 'binance_api_key_expired', 'throttle_seconds' => 1800, 'description' => 'Binance: API key has expired', 'is_active' => true],
            ['canonical' => 'binance_account_in_liquidation', 'throttle_seconds' => 900, 'description' => 'Binance: Account undergoing liquidation', 'is_active' => true],
            ['canonical' => 'binance_account_reduce_only_mode', 'throttle_seconds' => 900, 'description' => 'Binance: Account restricted to reduce-only', 'is_active' => true],
            ['canonical' => 'binance_account_trading_banned', 'throttle_seconds' => 1800, 'description' => 'Binance: Trading banned on account', 'is_active' => true],
            ['canonical' => 'binance_insufficient_balance_margin', 'throttle_seconds' => 900, 'description' => 'Binance: Insufficient balance or margin', 'is_active' => true],
            ['canonical' => 'binance_kyc_verification_required', 'throttle_seconds' => 1800, 'description' => 'Binance: KYC verification required', 'is_active' => true],
            ['canonical' => 'binance_account_unauthorized', 'throttle_seconds' => 900, 'description' => 'Binance: Unauthorized operation attempted', 'is_active' => true],
            ['canonical' => 'binance_api_system_error', 'throttle_seconds' => 900, 'description' => 'Binance: System error or timeout occurred', 'is_active' => true],
            ['canonical' => 'binance_api_network_error', 'throttle_seconds' => 900, 'description' => 'Binance: Network connectivity error', 'is_active' => true],

            // Bybit
            ['canonical' => 'bybit_api_key_expired', 'throttle_seconds' => 1800, 'description' => 'Bybit: API key has expired', 'is_active' => true],
            ['canonical' => 'bybit_account_in_liquidation', 'throttle_seconds' => 900, 'description' => 'Bybit: Account undergoing liquidation', 'is_active' => true],
            ['canonical' => 'bybit_account_reduce_only_mode', 'throttle_seconds' => 900, 'description' => 'Bybit: Account restricted to reduce-only', 'is_active' => true],
            ['canonical' => 'bybit_account_trading_banned', 'throttle_seconds' => 1800, 'description' => 'Bybit: Trading banned on account', 'is_active' => true],
            ['canonical' => 'bybit_insufficient_balance_margin', 'throttle_seconds' => 900, 'description' => 'Bybit: Insufficient balance or margin', 'is_active' => true],
            ['canonical' => 'bybit_kyc_verification_required', 'throttle_seconds' => 1800, 'description' => 'Bybit: KYC verification required', 'is_active' => true],
            ['canonical' => 'bybit_account_unauthorized', 'throttle_seconds' => 900, 'description' => 'Bybit: Unauthorized operation attempted', 'is_active' => true],
            ['canonical' => 'bybit_api_system_error', 'throttle_seconds' => 900, 'description' => 'Bybit: System error or timeout occurred', 'is_active' => true],
            ['canonical' => 'bybit_api_network_error', 'throttle_seconds' => 900, 'description' => 'Bybit: Network connectivity error', 'is_active' => true],

            // User notification system throttles
            ['canonical' => 'bounce_alert_to_pushover', 'throttle_seconds' => 3600, 'description' => 'Email bounce alert notification (sent via Pushover)', 'is_active' => true],

            // Symbol delisting throttles
            ['canonical' => 'symbol_delisting_positions_detected', 'throttle_seconds' => 1800, 'description' => 'Symbol delisting with open positions notification', 'is_active' => true],

            // Price spike check throttles
            ['canonical' => 'price_spike_check_symbol_error', 'throttle_seconds' => 900, 'description' => 'Price spike check symbol error notification', 'is_active' => true],
        ];

        foreach ($throttleRules as $rule) {
            DB::table('throttle_rules')->insert(array_merge($rule, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
