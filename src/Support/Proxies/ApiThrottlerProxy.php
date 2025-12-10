<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Proxies;

use Martingalian\Core\Support\Throttlers\BinanceThrottler;
use Martingalian\Core\Support\Throttlers\BitgetThrottler;
use Martingalian\Core\Support\Throttlers\BybitThrottler;
use Martingalian\Core\Support\Throttlers\CoinmarketCapThrottler;
use Martingalian\Core\Support\Throttlers\KrakenThrottler;
use Martingalian\Core\Support\Throttlers\KucoinThrottler;
use Martingalian\Core\Support\Throttlers\TaapiThrottler;

/**
 * ApiThrottlerProxy
 *
 * Maps API systems to their corresponding throttler classes.
 * Returns null for APIs without throttlers (graceful degradation).
 */
final class ApiThrottlerProxy
{
    /**
     * Get the throttler class for a given API system.
     *
     * @param  string  $apiSystem  The API system canonical name ('taapi', 'coinmarketcap', 'binance', 'bybit', etc.)
     * @return string|null The fully-qualified throttler class name, or null if no throttler exists
     */
    public static function getThrottler(string $apiSystem): ?string
    {
        return match ($apiSystem) {
            'taapi' => TaapiThrottler::class,
            'coinmarketcap' => CoinmarketCapThrottler::class,
            'binance' => BinanceThrottler::class,
            'bybit' => BybitThrottler::class,
            'kraken' => KrakenThrottler::class,
            'kucoin' => KucoinThrottler::class,
            'bitget' => BitgetThrottler::class,
            default => null, // No throttler = no rate limiting
        };
    }
}
