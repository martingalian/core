<?php

namespace Martingalian\Core\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Models\TradeConfiguration;
use Martingalian\Core\Models\User;

class SchemaSeeder1 extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Indicator::create([
            'canonical' => 'emas-same-direction',
            'is_active' => true,
            'class' => "App\Indicators\RefreshData\EMAsSameDirection",
            'is_apiable' => false,
        ]);

        Indicator::create([
            'canonical' => 'candle-comparison',
            'is_active' => false,
            'class' => "App\Indicators\Ongoing\CandleComparisonIndicator",
            'parameters' => [
                'results' => 2,
            ],
        ]);

        Indicator::create([
            'canonical' => 'macd',
            'is_active' => false,
            'class' => "App\Indicators\RefreshData\MACDIndicator",
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
            'class' => "App\Indicators\RefreshData\OBVIndicator",
            'parameters' => [
                'results' => 2,
            ],
        ]);

        Indicator::create([
            'canonical' => 'adx',
            'class' => "App\Indicators\RefreshData\ADXIndicator",
            'parameters' => [
                'results' => 1,
            ],
        ]);

        // Add a new indicator EMAsConvergence.
        Indicator::create([
            'canonical' => 'emas-convergence',
            'is_active' => false,
            'class' => "App\Indicators\RefreshData\EMAsConvergence",
            'is_apiable' => false,
        ]);

        Indicator::create([
            'canonical' => 'ema-40',
            'class' => "App\Indicators\RefreshData\EMAIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'period' => '40',
            ],
        ]);

        Indicator::create([
            'canonical' => 'ema-80',
            'class' => "App\Indicators\RefreshData\EMAIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'period' => '80',
            ],
        ]);

        Indicator::create([
            'canonical' => 'ema-120',
            'class' => "App\Indicators\RefreshData\EMAIndicator",
            'parameters' => [
                'backtrack' => 1,
                'results' => 2,
                'period' => '120',
            ],
        ]);

        $binance = ApiSystem::create([
            'name' => 'Binance',
            'canonical' => 'binance',
            'is_exchange' => true,
            'taapi_canonical' => 'binancefutures',
        ]);

        $coinmarketcap = ApiSystem::create([
            'name' => 'CoinmarketCap',
            'canonical' => 'coinmarketcap',
            'is_exchange' => false,
        ]);

        $alternativeMe = ApiSystem::create([
            'name' => 'AlternativeMe',
            'canonical' => 'alternativeme',
            'is_exchange' => false,
        ]);

        $taapi = ApiSystem::create([
            'name' => 'Taapi',
            'canonical' => 'taapi',
            'is_exchange' => false,
        ]);

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

        $trader = User::create([
            'name' => env('TRADER_NAME'),
            'email' => env('TRADER_EMAIL'),
            'password' => bcrypt('password'),
            'is_admin' => true,
            'pushover_key' => env('TRADER_PUSHOVER_KEY'),
        ]);

        $account = Account::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $trader->id,
            'api_system_id' => $binance->id,
            'portfolio_quote_id' => $usdt->id,
            'trading_quote_id' => $usdt->id,
            'trade_configuration_id' => 1,

            'credentials' => [
                'api_key' => env('BINANCE_API_KEY'),
                'api_secret' => env('BINANCE_API_SECRET'),
            ],

            'credentials_testing' => [
                'api_key' => env('BINANCE_TEST_API_KEY'),
                'api_secret' => env('BINANCE_TEST_API_SECRET'),
            ],
        ]);

        $cmcIds = [
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
        ];

        foreach ($cmcIds as $cmcId) {
            Symbol::create([
                'cmc_id' => $cmcId,
            ]);
        }

        // BONK (1000BONK).
        BaseAssetMapper::create([
            'api_system_id' => $binance->id,
            'symbol_token' => 'BONK',
            'exchange_token' => '1000BONK',
        ]);

        TradeConfiguration::create([
            'is_default' => true,
            'canonical' => 'standard',
            'description' => 'Standard trade configuration, default for all tokens',
            'profit_percentage' => 0.350,
            'total_positions_short' => 0,
            'total_positions_long' => 0,
            'position_margin_percentage_long' => 0.15,
            'position_margin_percentage_short' => 0.15,
            'indicator_timeframes' => ['1h', '4h', '6h', '12h', '1d'],
        ]);
    }
}
