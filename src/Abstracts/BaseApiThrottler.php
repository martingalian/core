<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Log;

/**
 * BaseApiThrottler
 *
 * Abstract class for API rate limiting/throttling.
 * Provides a reusable mechanism to enforce rate limits for external APIs.
 * Uses Cache to track request timestamps and counts.
 *
 * Subclasses must implement getRateLimitConfig() to define API-specific limits.
 */
abstract class BaseApiThrottler
{
    /**
     * Returns the rate limit configuration for the API
     *
     * @return array{
     *     requests_per_window: int,
     *     window_seconds: int,
     *     min_delay_between_requests_ms?: int
     * }
     */
    abstract protected static function getRateLimitConfig(): array;

    /**
     * Returns the cache key prefix for this API
     */
    abstract protected static function getCacheKeyPrefix(): string;

    /**
     * Check if we can dispatch a request to the API right now.
     * Returns 0 if we can dispatch immediately.
     * Returns number of seconds to wait if we need to throttle.
     *
     * @param  int  $retryCount  Number of retries already attempted (for exponential backoff)
     */
    final public static function canDispatch(int $retryCount = 0): int
    {
        $config = static::getRateLimitConfig();
        $prefix = static::getCacheKeyPrefix();

        Log::channel('jobs')->info("[THROTTLER] {$prefix} | canDispatch() called");

        // Check minimum delay between requests (if configured)
        if (isset($config['min_delay_between_requests_ms'])) {
            $secondsToWait = static::checkMinimumDelay($prefix, $config['min_delay_between_requests_ms']);
            if ($secondsToWait > 0) {
                Log::channel('jobs')->info("[THROTTLER] {$prefix} | Throttled by min delay: {$secondsToWait}s");

                return $secondsToWait;
            }
        }

        // Check requests per window limit
        $windowKey = static::getCurrentWindowKey($prefix, $config['window_seconds']);
        $currentCount = Cache::get($windowKey, 0);
        Log::channel('jobs')->info("[THROTTLER] {$prefix} | Window: {$windowKey} | Count: {$currentCount}/{$config['requests_per_window']}");

        $secondsToWait = static::checkWindowLimit($prefix, $config['requests_per_window'], $config['window_seconds']);

        if ($secondsToWait > 0) {
            Log::channel('jobs')->info("[THROTTLER] {$prefix} | Throttled by window limit: {$secondsToWait}s");
        } else {
            Log::channel('jobs')->info("[THROTTLER] {$prefix} | OK to dispatch");
        }

        // Apply exponential backoff if this is a retry
        if ($retryCount > 0 && $secondsToWait > 0) {
            $exponentialDelay = static::calculateExponentialBackoff($retryCount);
            $secondsToWait += $exponentialDelay;
            Log::channel('jobs')->info("[THROTTLER] {$prefix} | Retry #{$retryCount} | Added exponential backoff: +{$exponentialDelay}s | Total: {$secondsToWait}s");
        }

        return $secondsToWait;
    }

    /**
     * Record that a dispatch happened right now
     */
    final public static function recordDispatch(): void
    {
        $prefix = static::getCacheKeyPrefix();
        $config = static::getRateLimitConfig();

        // Update last dispatch timestamp
        Cache::put($prefix.':last_dispatch', Carbon::now(), $config['window_seconds']);

        // Increment counter for current window
        $windowKey = static::getCurrentWindowKey($prefix, $config['window_seconds']);
        $currentCount = Cache::get($windowKey, 0);
        $newCount = $currentCount + 1;
        Cache::put($windowKey, $newCount, $config['window_seconds'] * 2);

        Log::channel('jobs')->info("[THROTTLER] {$prefix} | recordDispatch() | Window: {$windowKey} | New count: {$newCount}/{$config['requests_per_window']}");
    }

    /**
     * Clear all throttling data for this API (useful for testing)
     */
    final public static function reset(): void
    {
        $prefix = static::getCacheKeyPrefix();
        Cache::forget($prefix.':last_dispatch');

        // Clear current and previous windows
        $config = static::getRateLimitConfig();
        $windowSeconds = max(1, $config['window_seconds']); // Guard against division by zero
        $currentWindow = floor(Carbon::now()->timestamp / $windowSeconds);

        for ($i = -2; $i <= 2; $i++) {
            Cache::forget("{$prefix}:window:".($currentWindow + $i));
        }
    }

    /**
     * Calculate exponential backoff delay based on retry count.
     * Formula: retryCount^1.5 + random jitter (0-2 seconds)
     */
    protected static function calculateExponentialBackoff(int $retryCount): int
    {
        // Exponential growth: retryCount^1.5 for smoother curve
        $exponential = (int) ceil(pow($retryCount, 1.5));

        // Add random jitter (0-2 seconds) to spread out retries
        $jitter = random_int(0, 2);

        return $exponential + $jitter;
    }

    /**
     * Check if minimum delay between requests is satisfied
     */
    protected static function checkMinimumDelay(string $prefix, int $minDelayMs): int
    {
        $lastDispatch = Cache::get($prefix.':last_dispatch');

        if (! $lastDispatch) {
            return 0;
        }

        // diffInMilliseconds returns negative if $lastDispatch is in the past
        // We need the absolute value to get "time since last"
        $timeSinceLastMs = abs(Carbon::now()->diffInMilliseconds($lastDispatch, false));
        $requiredDelayMs = $minDelayMs;

        if ($timeSinceLastMs < $requiredDelayMs) {
            return (int) ceil(($requiredDelayMs - $timeSinceLastMs) / 1000);
        }

        return 0;
    }

    /**
     * Check if we're within the requests-per-window limit
     */
    protected static function checkWindowLimit(string $prefix, int $maxRequests, int $windowSeconds): int
    {
        // Guard against division by zero - default to 1 second window
        if ($windowSeconds <= 0) {
            $windowSeconds = 1;
        }

        $windowKey = static::getCurrentWindowKey($prefix, $windowSeconds);
        $currentCount = Cache::get($windowKey, 0);

        if ($currentCount >= $maxRequests) {
            // Calculate how long until this window expires
            $currentWindow = floor(Carbon::now()->timestamp / $windowSeconds);
            $windowStartTime = $currentWindow * $windowSeconds;
            $windowEndTime = $windowStartTime + $windowSeconds;
            $secondsUntilWindowEnd = $windowEndTime - Carbon::now()->timestamp;

            return max(1, (int) ceil($secondsUntilWindowEnd));
        }

        return 0;
    }

    /**
     * Generate a cache key for the current time window
     */
    protected static function getCurrentWindowKey(string $prefix, int $windowSeconds): string
    {
        // Guard against division by zero - default to 1 second window
        if ($windowSeconds <= 0) {
            $windowSeconds = 1;
        }

        $currentWindow = floor(Carbon::now()->timestamp / $windowSeconds);

        return "{$prefix}:window:{$currentWindow}";
    }
}
