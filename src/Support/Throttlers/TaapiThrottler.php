<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Throttlers;

use Martingalian\Core\Abstracts\BaseApiThrottler;

/**
 * TaapiThrottler
 *
 * Rate limiter for TAAPI.IO API requests.
 * Configuration is loaded from config/martingalian.php ('throttlers.taapi').
 *
 * Default: Expert plan limits (75 requests per 15 seconds)
 * Adjust in config file based on your plan tier.
 *
 * Usage:
 *   $secondsToWait = TaapiThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Need to wait, retry job with delay
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   TaapiThrottler::recordDispatch();
 *   // Make API request...
 */
final class TaapiThrottler extends BaseApiThrottler
{
    /**
     * TAAPI Rate Limits (configurable via config/martingalian.php)
     *
     * Default configuration: Expert Plan
     * - 75 requests per 15 seconds (300/min, 18,000/hour)
     * - 200ms minimum delay between requests
     *
     * To adjust, update config/martingalian.php:
     * 'throttlers.taapi.requests_per_window'
     * 'throttlers.taapi.window_seconds'
     * 'throttlers.taapi.min_delay_between_requests_ms'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('martingalian.throttlers.taapi.requests_per_window', 75),
            'window_seconds' => config('martingalian.throttlers.taapi.window_seconds', 15),
            'min_delay_between_requests_ms' => config('martingalian.throttlers.taapi.min_delay_between_requests_ms', 200),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'taapi_throttler';
    }
}
