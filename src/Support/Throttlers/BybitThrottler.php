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
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status, minimum delay, and rate limit threshold.
     *
     * @param  int|null  $accountId  Optional account ID (not used by Bybit - all limits are IP-based)
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    public static function isSafeToDispatch(?int $accountId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        Log::channel('jobs')->info("[BYBIT-THROTTLER] isSafeToDispatch() called");

        // Check if IP is currently banned (403 response)
        if (self::isCurrentlyBanned()) {
            $secondsRemaining = self::getSecondsUntilBanLifts();
            Log::channel('jobs')->info("[BYBIT-THROTTLER] IP currently banned | Wait: {$secondsRemaining}s");

            return $secondsRemaining;
        }

        try {
            $ip = self::getCurrentIp();

            // Check rate limit threshold
            $safetyThreshold = config('martingalian.throttlers.bybit.safety_threshold', 0.1);
            $limitStatus = Cache::get("bybit:{$ip}:limit:status");
            $limitMax = Cache::get("bybit:{$ip}:limit:max");

            Log::channel('jobs')->info("[BYBIT-THROTTLER] Rate limit headers | Status: ".($limitStatus ?? 'NULL')." | Max: ".($limitMax ?? 'NULL')." | Safety threshold: {$safetyThreshold}");

            // If no rate limit data exists, allow request (fail-safe)
            if ($limitStatus === null || $limitMax === null) {
                Log::channel('jobs')->info("[BYBIT-THROTTLER] No rate limit data - allowing request");
                return 0;
            }

            // If max is 0, allow request (avoid division by zero)
            if ($limitMax === 0) {
                Log::channel('jobs')->info("[BYBIT-THROTTLER] Max is 0 - allowing request");
                return 0;
            }

            // If status is 0 or negative, we're at or over limit
            if ($limitStatus <= 0) {
                Log::channel('jobs')->info("[BYBIT-THROTTLER] STATUS THROTTLE! Status <= 0");
                return 1; // Wait at least 1 second
            }

            // Calculate remaining percentage
            $remainingPercentage = $limitStatus / $limitMax;
            Log::channel('jobs')->info("[BYBIT-THROTTLER] Remaining percentage: ".round($remainingPercentage * 100, 2)."%");

            // If below safety threshold, wait
            if ($remainingPercentage < $safetyThreshold) {
                Log::channel('jobs')->info("[BYBIT-THROTTLER] SAFETY THRESHOLD THROTTLE! {$remainingPercentage} < {$safetyThreshold}");
                return 1; // Wait at least 1 second
            }

            Log::channel('jobs')->info("[BYBIT-THROTTLER] isSafeToDispatch() = OK (0 seconds wait)");
        } catch (Throwable $e) {
            // Fail-safe: allow request on error
            Log::warning("Failed to check Bybit safety: {$e->getMessage()}");

            return 0;
        }

        return 0;
    }

    /**
     * Record Bybit response headers.
     * Bybit provides X-Bapi-Limit-Status and X-Bapi-Limit headers for rate limiting.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID (not used by Bybit - all limits are IP-based)
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();

            // Parse Bybit rate limit headers
            // X-Bapi-Limit-Status: Current usage count (remaining requests)
            if ($response->hasHeader('X-Bapi-Limit-Status')) {
                $statusHeader = $response->getHeader('X-Bapi-Limit-Status');
                $status = (int) ($statusHeader[0] ?? 0);
                Cache::put("bybit:{$ip}:limit:status", $status, 60);
            }

            // X-Bapi-Limit: Maximum allowed requests
            if ($response->hasHeader('X-Bapi-Limit')) {
                $limitHeader = $response->getHeader('X-Bapi-Limit');
                $limit = (int) ($limitHeader[0] ?? 0);
                Cache::put("bybit:{$ip}:limit:max", $limit, 60);
            }
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
     * Default configuration: Balanced settings to avoid 403 ban
     * - HTTP limit: 600 requests per 5 seconds
     * - We use 550/5s to stay safe (92% of limit)
     * - Uses sliding window algorithm for burst protection
     *
     * To adjust, update config/martingalian.php:
     * 'throttlers.bybit.requests_per_window'
     * 'throttlers.bybit.window_seconds'
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('martingalian.throttlers.bybit.requests_per_window', 550), // 92% of 600
            'window_seconds' => config('martingalian.throttlers.bybit.window_seconds', 5),
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
        return \Martingalian\Core\Models\Martingalian::ip();
    }
}
