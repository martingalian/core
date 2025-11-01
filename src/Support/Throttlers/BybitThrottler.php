<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Log;
use Martingalian\Core\Abstracts\BaseApiThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * BybitThrottler
 *
 * Rate limiter for Bybit API based on their documented limits:
 * - HTTP Level: 600 requests per 5 seconds per IP (hard limit, triggers 403 ban)
 * - API Level: Varies by endpoint and account tier (tracked via retCode 10006)
 *
 * Bybit Ban Behavior:
 * - Exceeding 600 req/5s triggers HTTP 403 "access too frequent"
 * - Ban is temporary and lifts automatically after 10 minutes
 *
 * This throttler:
 * 1. Enforces conservative rate limiting to stay under 600 req/5s
 * 2. Tracks IP ban status (403 responses)
 * 3. Enforces minimum delays between requests
 * 4. Uses sliding window to prevent bursts
 *
 * Usage:
 *   $secondsToWait = BybitThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Throttled - retry later
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   BybitThrottler::recordDispatch();
 *   // Make API request...
 *   BybitThrottler::recordResponseHeaders($response); // Optional
 */
final class BybitThrottler extends BaseApiThrottler
{
    /**
     * Override: Check IP ban status before allowing dispatch.
     */
    public static function canDispatch(int $retryCount = 0): int
    {
        $prefix = self::getCacheKeyPrefix();

        Log::channel('jobs')->info("[THROTTLER] {$prefix} | canDispatch() called");

        // 1. Check if IP is currently banned (403 response)
        if (self::isCurrentlyBanned()) {
            $secondsRemaining = self::getSecondsUntilBanLifts();
            Log::channel('jobs')->info("[THROTTLER] {$prefix} | IP currently banned | Wait: {$secondsRemaining}s");

            return $secondsRemaining;
        }

        // 2. Use base throttling logic
        return parent::canDispatch($retryCount);
    }

    /**
     * Record Bybit response headers (currently minimal - Bybit doesn't expose rate limit headers like Binance).
     * If Bybit adds rate limit headers in the future, parse them here.
     */
    public static function recordResponseHeaders(ResponseInterface $response): void
    {
        try {
            $ip = self::getCurrentIp();

            // Record timestamp of last request
            Cache::put("bybit:{$ip}:last_request", now()->timestamp, 60);

            // Future: Parse Bybit rate limit headers if they add them
            // Currently Bybit doesn't expose X-Rate-Limit-* headers
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
            Log::warning("Failed to record Bybit response headers: {$e->getMessage()}");
        }
    }

    /**
     * Check if the current server IP is currently banned by Bybit (403 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("bybit:{$ip}:banned_until");

            return $bannedUntil && now()->timestamp < (int) $bannedUntil;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            Log::warning("Failed to check Bybit ban status: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Record an IP ban in Cache when 403 "access too frequent" error occurs.
     * According to Bybit docs, ban lifts automatically after 10 minutes.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban lifts (default: 600 = 10 minutes)
     */
    public static function recordIpBan(int $retryAfterSeconds = 600): void
    {
        try {
            $ip = self::getCurrentIp();
            $expiresAt = now()->addSeconds($retryAfterSeconds);

            Cache::put(
                "bybit:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );

            Log::warning("Bybit IP ban recorded for {$ip} until {$expiresAt->toDateTimeString()}");
        } catch (Throwable $e) {
            // Log but don't throw - failing to record ban shouldn't break the app
            Log::error("Failed to record Bybit IP ban: {$e->getMessage()}");
        }
    }

    /**
     * Bybit Rate Limits (configurable via config/martingalian.php)
     *
     * Default configuration: Conservative settings to avoid 403 ban
     * - HTTP limit: 600 requests per 5 seconds
     * - We use 500/5s to stay safe (83% of limit)
     * - 100ms minimum delay between requests (prevents bursts)
     *
     * To adjust, update config/martingalian.php:
     * 'throttlers.bybit.requests_per_window'
     * 'throttlers.bybit.window_seconds'
     * 'throttlers.bybit.min_delay_between_requests_ms'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('martingalian.throttlers.bybit.requests_per_window', 500), // 83% of 600
            'window_seconds' => config('martingalian.throttlers.bybit.window_seconds', 5),
            'min_delay_between_requests_ms' => config('martingalian.throttlers.bybit.min_delay_between_requests_ms', 100),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'bybit_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("bybit:{$ip}:banned_until");

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
        return gethostbyname(gethostname());
    }
}
