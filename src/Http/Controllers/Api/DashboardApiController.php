<?php

declare(strict_types=1);

namespace Martingalian\Core\Http\Controllers\Api;

use Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Log;

final class DashboardApiController extends Controller
{
    /**
     * Get dashboard data with global statistics and positions.
     *
     * Caching behavior:
     * - If refresh=true (F5/page refresh): Bypass cache and regenerate data
     * - Otherwise (dashboard auto-refresh): Use cached data (30 min TTL)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $cacheKey = "dashboard_data_user_{$user->id}";
        $shouldRefresh = $request->boolean('refresh', false);

        // If refresh=true (F5), clear cache and regenerate
        if ($shouldRefresh) {
            Cache::forget($cacheKey);
            Log::info('🔄 Dashboard cache cleared (F5 refresh)', ['user_id' => $user->id]);
        }

        // Get data from cache or generate new
        $data = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
            Log::info('🎯 Generating fresh dashboard data', ['user_id' => $user->id]);

            $globalStats = $this->generateGlobalStats();
            $longPositions = $this->generatePositions('LONG', 6);
            $shortPositions = $this->generatePositions('SHORT', 6);

            return [
                'global_stats' => $globalStats,
                'positions' => [
                    'long' => $longPositions,
                    'short' => $shortPositions,
                ],
            ];
        });

        if (! $shouldRefresh) {
            Log::info('📦 Using cached dashboard data', ['user_id' => $user->id]);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Generate stub global statistics.
     *
     * Flatter structure as requested: ['gross_revenue' => XXX, 'daily_gross' => XXX, ...]
     */
    public function generateGlobalStats(): array
    {
        return [
            'gross_revenue' => round(random_int(1000, 5000) + (random_int(0, 99) / 100), precision: 2),
            'daily_gross' => round(random_int(50, 300) + (random_int(0, 99) / 100), precision: 2),
            'margin_ratio' => round(random_int(20, 100) + (random_int(0, 99) / 100), precision: 2),
            'drawdown' => round(random_int(-100, -5) + (random_int(0, 99) / 100), precision: 2),
            'clean_revenue' => round(random_int(500, 4000) + (random_int(0, 99) / 100), precision: 2),
            'avg_variation' => round(random_int(-50, 50) + (random_int(0, 99) / 100), precision: 2),
        ];
    }

    /**
     * Generate stub positions.
     *
     * Structure matches real data from Position model:
     * - position: "long" or "short" (lowercase)
     * - symbol->token: e.g. "BTC"
     * - opened_at: Carbon datetime instance
     * - opened_at_human: Human-readable time
     * - timeframes: ["1d" => 1, "4h" => -1] where 1=positive, -1=negative
     * - chart: [{timestamp, mark_price}] array of objects
     * - ladder: {start_price, end_price, tick_prices[], profit_price, current_price}
     *
     * @param  string  $positionType  LONG or SHORT
     * @param  int  $count  Number of positions to generate
     */
    public function generatePositions(string $positionType, int $count): array
    {
        $tokens = [
            ['token' => 'BTC', 'name' => 'Bitcoin', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1.png', 'base_price' => 100000],
            ['token' => 'ETH', 'name' => 'Ethereum', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1027.png', 'base_price' => 3800],
            ['token' => 'BNB', 'name' => 'BNB', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1839.png', 'base_price' => 600],
            ['token' => 'XRP', 'name' => 'Ripple', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/52.png', 'base_price' => 2.5],
            ['token' => 'ADA', 'name' => 'Cardano', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/2010.png', 'base_price' => 1],
            ['token' => 'AVAX', 'name' => 'Avalanche', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/5805.png', 'base_price' => 40],
            ['token' => 'SOL', 'name' => 'Solana', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/5426.png', 'base_price' => 200],
            ['token' => 'DOGE', 'name' => 'Dogecoin', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/74.png', 'base_price' => 0.3],
            ['token' => 'MATIC', 'name' => 'Polygon', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/3890.png', 'base_price' => 0.9],
            ['token' => 'DOT', 'name' => 'Polkadot', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/6636.png', 'base_price' => 7],
            ['token' => 'LINK', 'name' => 'Chainlink', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1975.png', 'base_price' => 15],
            ['token' => 'UNI', 'name' => 'Uniswap', 'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/7083.png', 'base_price' => 11],
        ];

        $positions = [];

        for ($i = 0; $i < $count; $i++) {
            $tokenData = $tokens[$i % count($tokens)];
            $basePrice = $tokenData['base_price'];
            $markPrice = round($basePrice * (1 + (random_int(-20, 20) / 100)), precision: 2);
            $variationPercent = round(((random_int(-2000, 2000) / 100)), precision: 2);

            // Generate random opened_at datetime (1-14 days ago)
            $openedAt = now()->subHours(random_int(1, 336)); // Random time in last 14 days

            // Random badge states
            $isHedged = random_int(0, 3) === 0; // 25% chance
            $isWaped = ! $isHedged && random_int(0, 4) === 0; // 20% chance if not hedged
            $isRecentlyOpened = ! $isHedged && ! $isWaped && random_int(0, 5) === 0; // ~16% chance

            // Generate ladder data with actual prices (UI will calculate percentages)
            $openingPrice = round($basePrice * (1 + (random_int(-10, 10) / 100)), precision: 2);

            // For LONG: ladder goes from opening price DOWN (buying lower)
            // For SHORT: ladder goes from opening price UP (selling higher)
            $ladderDirection = mb_strtolower($positionType) === 'long' ? -1 : 1;
            $ladderStartPrice = $openingPrice;
            $ladderEndPrice = round($openingPrice * (1 + ($ladderDirection * 0.05)), precision: 2); // 5% range

            // Generate profit price within ladder range (10-40% through the ladder)
            $profitPricePercent = random_int(10, 40) / 100;
            $profitPrice = round($ladderStartPrice + (($ladderEndPrice - $ladderStartPrice) * $profitPricePercent), precision: 2);

            // Generate current price (30-70% through the ladder, AFTER profit price)
            // This ensures the P marker will show (profit already passed, P is behind green bar)
            $currentPricePercent = random_int(30, 70) / 100; // After profit (10-40%)
            $currentPrice = round($ladderStartPrice + (($ladderEndPrice - $ladderStartPrice) * $currentPricePercent), precision: 2);

            $numTicks = 4;

            $tickPrices = [];
            for ($t = 1; $t <= $numTicks; $t++) {
                $tickPrices[] = round($ladderStartPrice + (($ladderEndPrice - $ladderStartPrice) * ($t / $numTicks)), precision: 2);
            }

            $positions[] = [
                'id' => $i + 1,
                'token' => $tokenData['token'], // Will be $position->symbol->token in real data
                'name' => $tokenData['name'],
                'icon_url' => $tokenData['icon_url'],
                'position' => mb_strtolower($positionType), // "long" or "short" (lowercase like real data)
                'leverage' => random_int(10, 25).'x',
                'opened_at' => $openedAt->toIso8601String(), // ISO 8601 datetime string
                'opened_at_human' => $openedAt->diffForHumans(), // e.g., "2 hours ago"
                'is_hedged' => $isHedged,
                'is_waped' => $isWaped,
                'is_recently_opened' => $isRecentlyOpened,
                'mark_price' => $markPrice,
                'variation_percent' => $variationPercent,

                // Chart data: array of {timestamp, mark_price} objects
                'chart' => $this->generateChartDataRealFormat($basePrice),

                // Ladder: actual prices, UI calculates positions/percentages
                'ladder' => [
                    'start_price' => $ladderStartPrice,
                    'end_price' => $ladderEndPrice,
                    'tick_prices' => $tickPrices, // Array of 4 prices for vertical markers
                    'profit_price' => $profitPrice, // Price where "P" marker appears
                    'current_price' => $currentPrice, // Used to calculate progress bar fill
                ],

                'stats' => [
                    'size' => round(random_int(10000, 100000) + (random_int(0, 99) / 100), precision: 2),
                    'alpha_path' => round(random_int(80, 120) + (random_int(0, 99) / 100), precision: 1),
                    'limit_filled_count' => random_int(0, 4),
                    'limit_filled_percent' => round(random_int(0, 100) + (random_int(0, 99) / 100), precision: 1),
                ],

                'prices' => [
                    'opening_price' => $openingPrice,
                    'profit_price' => $profitPrice,
                    'next_limit_order' => random_int(0, 1) ? round($basePrice * (1 + (random_int(-5, 5) / 100)), precision: 2) : null,
                ],

                // Timeframes: 1 = positive (green), -1 = negative (red)
                'timeframes' => [
                    '1d' => random_int(0, 1) ? 1 : -1,
                    '4h' => random_int(0, 1) ? 1 : -1,
                ],
            ];
        }

        return $positions;
    }

    /**
     * Generate chart data in real format: array of {timestamp, mark_price} objects.
     */
    public function generateChartDataRealFormat(float $basePrice): array
    {
        $now = now()->timestamp * 1000; // JavaScript timestamp (milliseconds)
        $ticks = [];

        // Generate 56 ticks (approximately 1 hour at ~1 minute intervals)
        for ($i = 0; $i < 56; $i++) {
            $timestamp = $now - ((55 - $i) * 60 * 1000); // 1 minute intervals, going backwards

            // Create realistic price movement
            $priceVariation = (random_int(-500, 500) / 100); // -5% to +5%
            $markPrice = round($basePrice * (1 + ($priceVariation / 100)), precision: 2);

            $ticks[] = [
                'timestamp' => $timestamp,
                'mark_price' => $markPrice,
            ];
        }

        return $ticks;
    }
}
