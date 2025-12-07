<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Martingalian\Core\Abstracts\BaseApiThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * KrakenThrottler
 *
 * Rate limiter for Kraken Futures API based on their documented limits:
 * - HTTP Level: 500 requests per 10 seconds per IP
 *
 * Kraken Ban Behavior:
 * - Exceeding 500 req/10s triggers HTTP 429 "Too Many Requests"
 * - Ban duration varies based on severity
 *
 * This throttler:
 * 1. Enforces conservative rate limiting to stay under 500 req/10s
 * 2. Tracks IP ban status (429 responses)
 * 3. Enforces minimum delays between requests
 * 4. Uses sliding window to prevent bursts
 *
 * Usage:
 *   $secondsToWait = KrakenThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Throttled - retry later
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   KrakenThrottler::recordDispatch();
 *   // Make API request...
 *   KrakenThrottler::recordResponseHeaders($response); // Optional
 */
final class KrakenThrottler extends BaseApiThrottler
{
    /**
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status, minimum delay, and rate limit threshold.
     *
     * @param  int|null  $accountId  Optional account ID (not used by Kraken - all limits are IP-based)
     * @param  int|string|null  $stepId  Optional step ID for throttle logging
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    public static function isSafeToDispatch(?int $accountId = null, int|string|null $stepId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        throttle_log($stepId, "   └─ KrakenThrottler::isSafeToDispatch() called");

        // 1. Check minimum delay between requests
        $ip = self::getCurrentIp();
        $minDelayMs = config('martingalian.throttlers.kraken.min_delay_ms', 0);

        throttle_log($stepId, "      [Check] Minimum delay between requests...");
        throttle_log($stepId, "         ├─ Server IP: {$ip}");
        throttle_log($stepId, "         └─ Min delay configured: {$minDelayMs}ms");

        if ($minDelayMs > 0) {
            // Check both IP-based timestamp (from recordResponseHeaders)
            // and prefix-based Carbon (from recordDispatch)
            $lastRequest = Cache::get("kraken:{$ip}:last_request");
            $lastDispatch = Cache::get($prefix.':last_dispatch');

            $lastTimestamp = null;
            if ($lastRequest) {
                $lastTimestamp = $lastRequest;
            } elseif ($lastDispatch && $lastDispatch instanceof \Illuminate\Support\Carbon) {
                $lastTimestamp = $lastDispatch->timestamp;
            }

            if ($lastTimestamp) {
                $minDelaySeconds = $minDelayMs / 1000;
                $elapsedSeconds = now()->timestamp - $lastTimestamp;

                throttle_log($stepId, "         ├─ Last request timestamp: {$lastTimestamp}");
                throttle_log($stepId, "         ├─ Elapsed since last: {$elapsedSeconds}s");
                throttle_log($stepId, "         └─ Min delay required: {$minDelaySeconds}s");

                if ($elapsedSeconds < $minDelaySeconds) {
                    $waitSeconds = (int) ceil($minDelaySeconds - $elapsedSeconds);
                    throttle_log($stepId, "         ❌ THROTTLED by minimum delay");
                    throttle_log($stepId, "            └─ Must wait: {$waitSeconds}s");

                    return $waitSeconds;
                }
            }
            throttle_log($stepId, "         ✓ Minimum delay check passed");
        } else {
            throttle_log($stepId, "         ✓ No minimum delay configured - skipping");
        }

        // 2. Check if IP is currently banned (429 response)
        throttle_log($stepId, "      [Check] IP ban status...");
        if (self::isCurrentlyBanned()) {
            $secondsRemaining = self::getSecondsUntilBanLifts();
            throttle_log($stepId, "         ❌ THROTTLED by IP ban");
            throttle_log($stepId, "            ├─ IP: {$ip}");
            throttle_log($stepId, "            └─ Ban lifts in: {$secondsRemaining}s");

            return $secondsRemaining;
        }
        throttle_log($stepId, "         ✓ IP not banned");

        // 3. Use base class window limit check
        return 0;
    }

    /**
     * Record Kraken response headers.
     * Kraken doesn't provide specific rate limit headers like Bybit,
     * but we track request timestamps for minimum delay enforcement.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID (not used by Kraken - all limits are IP-based)
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();

            // Record last request timestamp for minimum delay enforcement
            Cache::put("kraken:{$ip}:last_request", now()->timestamp, 60);
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
        }
    }

    /**
     * Check if the current server IP is currently banned by Kraken (429 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("kraken:{$ip}:banned_until");

            return $bannedUntil && now()->timestamp < (int) $bannedUntil;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            return false;
        }
    }

    /**
     * Record an IP ban in Cache when 429 errors occur.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban lifts (default: 60 seconds)
     */
    public static function recordIpBan(int $retryAfterSeconds = 60): void
    {
        try {
            $ip = self::getCurrentIp();
            $expiresAt = now()->addSeconds($retryAfterSeconds);

            Cache::put(
                "kraken:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );
        } catch (Throwable $e) {
            // Fail silently - failing to record ban shouldn't break the app
        }
    }

    /**
     * Kraken Rate Limits (configurable via config/martingalian.php)
     *
     * Default configuration: Balanced settings to avoid 429 ban
     * - HTTP limit: 500 requests per 10 seconds
     * - We use 425/10s to stay safe (85% of limit)
     * - Uses sliding window algorithm for burst protection
     *
     * To adjust, update config/martingalian.php:
     * 'throttlers.kraken.requests_per_window'
     * 'throttlers.kraken.window_seconds'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('martingalian.throttlers.kraken.requests_per_window', 425), // 85% of 500
            'window_seconds' => config('martingalian.throttlers.kraken.window_seconds', 10),
            'min_delay_between_requests_ms' => config('martingalian.throttlers.kraken.min_delay_ms', 200),
            'safety_threshold' => config('martingalian.throttlers.kraken.safety_threshold', 0.85),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'kraken_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("kraken:{$ip}:banned_until");

            if ($bannedUntil) {
                return max(0, (int) $bannedUntil - now()->timestamp);
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Get current server IP address.
     */
    protected static function getCurrentIp(): string
    {
        return \Martingalian\Core\Models\Martingalian::ip();
    }
}
