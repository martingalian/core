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
        // Disable observers during seeding to prevent notification spam
        ExchangeSymbol::withoutEvents(function () {
            Account::withoutEvents(function () {
                Position::withoutEvents(function () {
                    User::withoutEvents(function () {
                        $this->runSeeding();
                    });
                });
            });
        });
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
        Indicator::updateOrCreate(
            ['canonical' => 'emas-same-direction'],
            [
                'is_active' => true,
                'class' => "Martingalian\Core\Indicators\RefreshData\EMAsSameDirection",
                'is_computed' => true,
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'candle-comparison'],
            [
                'type' => 'refresh-data',
                'is_active' => true,
                'is_computed' => false,
                'class' => "Martingalian\Core\Indicators\Ongoing\CandleComparisonIndicator",
                'parameters' => [
                    'results' => 2,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'macd'],
            [
                'is_active' => false,
                'class' => "Martingalian\Core\Indicators\RefreshData\MACDIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'optInFastPeriod' => '12',
                    'optInSlowPeriod' => 26,
                    'optInSignalPeriod' => 9,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'obv'],
            [
                'is_active' => false, // For now, this indicator is causing issues.
                'class' => "Martingalian\Core\Indicators\RefreshData\OBVIndicator",
                'parameters' => [
                    'results' => 2,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'adx'],
            [
                'class' => "Martingalian\Core\Indicators\RefreshData\ADXIndicator",
                'parameters' => [
                    'results' => 1,
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'emas-convergence'],
            [
                'is_active' => false,
                'class' => "Martingalian\Core\Indicators\RefreshData\EMAsConvergence",
                'is_computed' => false,
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'ema-40'],
            [
                'class' => "Martingalian\Core\Indicators\RefreshData\EMAIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'period' => '40',
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'ema-80'],
            [
                'class' => "Martingalian\Core\Indicators\RefreshData\EMAIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'period' => '80',
                ],
            ]
        );

        Indicator::updateOrCreate(
            ['canonical' => 'ema-120'],
            [
                'class' => "Martingalian\Core\Indicators\RefreshData\EMAIndicator",
                'parameters' => [
                    'backtrack' => 1,
                    'results' => 2,
                    'period' => '120',
                ],
            ]
        );

        // From SchemaSeeder9 - Update and Create
        Indicator::query()->where('canonical', 'candle-comparison')
            ->update(['class' => 'Martingalian\Core\Indicators\RefreshData\CandleComparisonIndicator']);

        Indicator::updateOrCreate(
            ['canonical' => 'candle'],
            [
                'type' => 'history',
                'is_active' => true,
                'is_computed' => true,
                'parameters' => ['results' => 1],
                'class' => "Martingalian\Core\Indicators\History\CandleIndicator",
            ]
        );

        // From SchemaSeeder11
        Indicator::updateOrCreate(
            ['canonical' => 'price-volatility'],
            [
                'is_active' => true,
                'type' => 'reports',
                'class' => "Martingalian\Core\Indicators\Reports\PriceVolatilityIndicator",
                'is_computed' => true,
                'parameters' => ['results' => 2000],
            ]
        );

        Indicator::where('canonical', 'candle')->where('type', 'history')->first()?->update(['type' => 'dashboard']);
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
        $usdt = Quote::updateOrCreate(
            ['canonical' => 'USDT'],
            ['name' => 'USDT (Tether)']
        );

        $usdc = Quote::updateOrCreate(
            ['canonical' => 'USDC'],
            ['name' => 'USDC (USD Coin)']
        );

        $bfusdt = Quote::updateOrCreate(
            ['canonical' => 'BFUSDT'],
            ['name' => 'BFUSDT (USD Coin)']
        );

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

        return User::updateOrCreate(
            ['email' => $userData['email']],
            $userData
        );
    }

    /**
     * Seed the default trade configuration.
     */
    public function seedTradeConfiguration(): void
    {
        TradeConfiguration::updateOrCreate(
            ['canonical' => 'standard'],
            [
                'is_default' => true,
                'description' => 'Standard trade configuration, default for all tokens',
                'indicator_timeframes' => ['1h', '4h', '6h', '12h', '1d'],
            ]
        );
    }

    /**
     * Seed the Binance account for the trader.
     */
    public function seedBinanceAccount(User $trader, ApiSystem $binance, Quote $usdt): void
    {
        Account::updateOrCreate(
            [
                'user_id' => $trader->id,
                'api_system_id' => $binance->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Binance Account',
                'portfolio_quote_id' => $usdt->id,
                'trading_quote_id' => $usdt->id,
                'trade_configuration_id' => 1,
                'binance_api_key' => env('BINANCE_API_KEY'),
                'binance_api_secret' => env('BINANCE_API_SECRET'),
            ]
        );
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
        BaseAssetMapper::updateOrCreate(
            [
                'api_system_id' => $binance->id,
                'symbol_token' => 'BONK',
            ],
            ['exchange_token' => '1000BONK']
        );

        // From SchemaSeeder2 - BROCCOLI mapping
        BaseAssetMapper::updateOrCreate(
            [
                'api_system_id' => $binance->id,
                'symbol_token' => 'BROCCOLI',
            ],
            ['exchange_token' => 'BROCCOLI714']
        );
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
        Martingalian::updateOrCreate(
            ['id' => 1],
            [
                'allow_opening_positions' => true,
                'admin_pushover_application_key' => env('ADMIN_USER_PUSHOVER_APPLICATION_KEY'),
                'admin_pushover_user_key' => env('ADMIN_USER_PUSHOVER_USER_KEY'),
                'email' => env('ADMIN_USER_EMAIL'),
            ]
        );
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
            DB::table('servers')->updateOrInsert(
                ['hostname' => $server['hostname']],
                array_merge($server, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
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
                'usage_reference' => 'MonitorDataCoherencyCommand::checkAndNotifyStaleIssues() line 188',
                'default_severity' => 'high',
                'verified' => 1,
            ],
            [
                'canonical' => 'update_prices_restart',
                'title' => 'Price Stream Restart',
                'description' => 'Sent when price monitoring restarts due to symbol changes (exchange-agnostic, uses relatable ApiSystem)',
                'usage_reference' => 'Binance/UpdatePricesCommand line 117, Bybit/UpdatePricesCommand line 119',
                'default_severity' => 'info',
                'verified' => 1,
            ],
            [
                'canonical' => 'websocket_error',
                'title' => 'WebSocket Error',
                'description' => 'Sent when WebSocket connection encounters errors (any exchange)',
                'usage_reference' => 'Binance/UpdatePricesCommand WebSocket error callback, Bybit/UpdatePricesCommand WebSocket error callback',
                'default_severity' => 'critical',
                'verified' => 1,
            ],
            [
                'canonical' => 'websocket_invalid_json',
                'title' => 'WebSocket: Invalid JSON Response',
                'description' => 'Sent when exchange WebSocket returns invalid JSON (exchange-agnostic, uses relatable ApiSystem)',
                'usage_reference' => 'Binance/UpdatePricesCommand::processWebSocketMessage() line 165, Bybit/UpdatePricesCommand::processWebSocketMessage() line 166',
                'default_severity' => 'medium',
                'verified' => 1,
            ],
            [
                'canonical' => 'websocket_prices_update_error',
                'title' => 'WebSocket Prices: Database Update Error',
                'description' => 'Sent when database update fails for WebSocket price data (any exchange)',
                'usage_reference' => 'Binance/UpdatePricesCommand::updateExchangeSymbol(), Bybit/UpdatePricesCommand::updateExchangeSymbol()',
                'default_severity' => 'critical',
                'verified' => 1,
            ],
            [
                'canonical' => 'server_rate_limit_exceeded',
                'title' => 'Server Rate Limit Exceeded',
                'description' => 'Sent when server hits API rate limit',
                'usage_reference' => 'ApiRequestLogObserver line 84',
                'default_severity' => 'high',
                'verified' => 1,
            ],
            [
                'canonical' => 'server_ip_forbidden',
                'title' => 'Server IP Forbidden by Exchange',
                'description' => 'Sent when server/IP is forbidden from accessing exchange API (HTTP 418 IP ban)',
                'usage_reference' => 'ApiRequestLogObserver line 99',
                'default_severity' => 'critical',
                'verified' => 1,
            ],
            [
                'canonical' => 'exchange_symbol_no_taapi_data',
                'title' => 'Exchange Symbol Auto-Deactivated - No TAAPI Data',
                'description' => 'Sent when an exchange symbol is automatically deactivated due to consistent lack of TAAPI indicator data',
                'usage_reference' => 'ApiRequestLogObserver::sendDeactivationNotification() line 201',
                'default_severity' => 'info',
                'verified' => 1,
            ],
        ];

        foreach ($notifications as $notification) {
            DB::table('notifications')->updateOrInsert(
                ['canonical' => $notification['canonical']],
                [
                    'title' => $notification['title'],
                    'description' => $notification['description'],
                    'detailed_description' => $notification['detailed_description'] ?? $notification['description'],
                    'usage_reference' => $notification['usage_reference'] ?? null,
                    'default_severity' => $notification['default_severity'],
                    'verified' => $notification['verified'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Run all seeding operations with observers disabled.
     */
    private function runSeeding(): void
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
}
