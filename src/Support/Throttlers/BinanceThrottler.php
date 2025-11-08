<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Throttlers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Log;
use Martingalian\Core\Abstracts\BaseApiThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * BinanceThrottler
 *
 * Advanced rate limiter for Binance API that uses response headers to track
 * real-time rate limit consumption across multiple time windows.
 *
 * Binance uses interval-based rate limits with headers like:
 * - X-MBX-USED-WEIGHT-1M: Request weight used in the last minute
 * - X-MBX-USED-WEIGHT-10S: Request weight used in the last 10 seconds
 * - X-MBX-ORDER-COUNT-10S: Orders placed in the last 10 seconds
 *
 * This throttler:
 * 1. Parses response headers after EVERY request
 * 2. Tracks IP ban status (418 responses)
 * 3. Checks rate limit proximity (>80% = throttle)
 * 4. Enforces minimum delays between requests
 *
 * Usage:
 *   $secondsToWait = BinanceThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Throttled - retry later
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   BinanceThrottler::recordDispatch();
 *   // Make API request...
 *   BinanceThrottler::recordResponseHeaders($response);
 */
final class BinanceThrottler extends BaseApiThrottler
{
    /**
     * Pre-flight safety check called before canDispatch().
     * Checks IP ban status and rate limit proximity.
     *
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     * @return int Seconds to wait, or 0 if safe to proceed
     */
    public static function isSafeToDispatch(?int $accountId = null): int
    {
        $prefix = self::getCacheKeyPrefix();

        // 1. Check if IP is currently banned (418 response)
        if (self::isCurrentlyBanned()) {
            $secondsRemaining = self::getSecondsUntilBanLifts();
            Log::channel('jobs')->info("[THROTTLER] {$prefix} | IP currently banned | Wait: {$secondsRemaining}s");

            return $secondsRemaining;
        }

        // 2. Check minimum delay since last request
        try {
            $ip = self::getCurrentIp();
            $minDelayMs = config('martingalian.throttlers.binance.min_delay_ms', 0);
            if ($minDelayMs > 0) {
                $lastRequest = Cache::get("binance:{$ip}:last_request");
                if ($lastRequest) {
                    $elapsedMs = (now()->timestamp - $lastRequest) * 1000;
                    if ($elapsedMs < $minDelayMs) {
                        $waitSeconds = (int) ceil(($minDelayMs - $elapsedMs) / 1000);

                        return $waitSeconds > 0 ? $waitSeconds : 1;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fail-safe: allow request on error
            Log::warning("Failed to check Binance min delay: {$e->getMessage()}");
        }

        // 3. Check if approaching any rate limit (>80% threshold)
        $secondsToWait = self::checkRateLimitProximity($accountId);
        if ($secondsToWait > 0) {
            Log::channel('jobs')->info("[THROTTLER] {$prefix} | Throttled by rate limit proximity: {$secondsToWait}s");

            return $secondsToWait;
        }

        return 0;
    }

    /**
     * Record Binance response headers after EVERY API request.
     * Parses X-MBX-USED-WEIGHT-* and X-MBX-ORDER-COUNT-* headers and stores them
     * so all workers on the same IP can coordinate.
     *
     * @param  ResponseInterface  $response  The API response
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     */
    public static function recordResponseHeaders(ResponseInterface $response, ?int $accountId = null): void
    {
        try {
            $ip = self::getCurrentIp();
            $headers = self::normalizeHeaders($response);

            // Parse and store weight headers
            $weights = self::parseIntervalHeaders($headers, 'x-mbx-used-weight-');
            foreach ($weights as $data) {
                $interval = $data['interval'];
                $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

                Cache::put(
                    "binance:{$ip}:weight:{$interval}",
                    $data['value'],
                    $ttl
                );
            }

            // Parse and store order count headers (UID-based, per account)
            $orders = self::parseIntervalHeaders($headers, 'x-mbx-order-count-');
            foreach ($orders as $data) {
                $interval = $data['interval'];
                $ttl = self::getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

                // ORDER limits are per UID (account), so include account ID in cache key
                $key = $accountId !== null
                    ? "binance:{$ip}:uid:{$accountId}:orders:{$interval}"
                    : "binance:{$ip}:orders:{$interval}"; // Fallback for backward compatibility

                Cache::put($key, $data['value'], $ttl);
            }

            // Record timestamp of last request
            Cache::put("binance:{$ip}:last_request", now()->timestamp, 60);
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
            Log::warning("Failed to record Binance response headers: {$e->getMessage()}");
        }
    }

    /**
     * Check if the current server IP is currently banned by Binance (418 response).
     */
    public static function isCurrentlyBanned(): bool
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("binance:{$ip}:banned_until");

            return $bannedUntil && now()->timestamp < (int) $bannedUntil;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            Log::warning("Failed to check Binance ban status: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Record an IP ban in Cache when 418/429 errors with Retry-After occur.
     * This allows all workers on the same IP to coordinate and stop making requests.
     */
    public static function recordIpBan(int $retryAfterSeconds): void
    {
        try {
            $ip = self::getCurrentIp();
            $expiresAt = now()->addSeconds($retryAfterSeconds);

            Cache::put(
                "binance:{$ip}:banned_until",
                $expiresAt->timestamp,
                $retryAfterSeconds
            );

            Log::warning("Binance IP ban recorded for {$ip} until {$expiresAt->toDateTimeString()}");
        } catch (Throwable $e) {
            // Log but don't throw - failing to record ban shouldn't break the app
            Log::error("Failed to record Binance IP ban: {$e->getMessage()}");
        }
    }

    /**
     * Basic rate limit config (used as fallback when headers unavailable)
     * Real limits are tracked via response headers.
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => config('martingalian.throttlers.binance.requests_per_window', 100),
            'window_seconds' => config('martingalian.throttlers.binance.window_seconds', 60),
            'min_delay_between_requests_ms' => config('martingalian.throttlers.binance.min_delay_ms', 200),
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'binance_throttler';
    }

    /**
     * Get seconds until ban lifts.
     */
    protected static function getSecondsUntilBanLifts(): int
    {
        try {
            $ip = self::getCurrentIp();
            $bannedUntil = Cache::get("binance:{$ip}:banned_until");

            if ($bannedUntil) {
                return max(0, (int) $bannedUntil - now()->timestamp);
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Check if approaching any rate limit (>80% threshold).
     * Returns seconds to wait if too close to limits.
     *
     * @param  int|null  $accountId  Optional account ID for UID-based ORDER limits
     */
    protected static function checkRateLimitProximity(?int $accountId = null): int
    {
        try {
            $ip = self::getCurrentIp();
            $safetyThreshold = config('martingalian.throttlers.binance.safety_threshold', 0.8);

            // Get rate limits from config or use conservative defaults
            $rateLimits = config('martingalian.throttlers.binance.rate_limits', [
                ['type' => 'REQUEST_WEIGHT', 'interval' => '1m', 'limit' => 1200],
                ['type' => 'REQUEST_WEIGHT', 'interval' => '10s', 'limit' => 100],
                ['type' => 'ORDERS', 'interval' => '10s', 'limit' => 50],
            ]);

            foreach ($rateLimits as $rateLimit) {
                $interval = $rateLimit['interval'];
                $limit = $rateLimit['limit'];
                $type = $rateLimit['type'];

                // ORDER limits are per UID (account), WEIGHT limits are per IP
                if ($type === 'ORDERS' && $accountId !== null) {
                    $key = "binance:{$ip}:uid:{$accountId}:orders:{$interval}";
                } elseif ($type === 'ORDERS') {
                    $key = "binance:{$ip}:orders:{$interval}"; // Fallback
                } else {
                    $key = "binance:{$ip}:weight:{$interval}";
                }

                $current = Cache::get($key) ?? 0;

                if ($current / $limit > $safetyThreshold) {
                    Log::info("Binance rate limit safety threshold exceeded for {$interval}: {$current}/{$limit}");

                    // Calculate time until this window resets
                    return self::calculateWindowResetTime($interval);
                }
            }

            return 0;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            Log::warning("Failed to check Binance rate limit proximity: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Calculate seconds until a rate limit window resets.
     */
    protected static function calculateWindowResetTime(string $interval): int
    {
        // Parse interval like "1m", "10s"
        if (preg_match('/^(\d+)([smhd])$/i', $interval, $matches)) {
            $intervalNum = (int) $matches[1];
            $intervalLetter = mb_strtolower($matches[2]);

            $seconds = match ($intervalLetter) {
                's' => $intervalNum,
                'm' => $intervalNum * 60,
                'h' => $intervalNum * 3600,
                'd' => $intervalNum * 86400,
                default => 60,
            };

            // Return time until current window expires (conservative estimate)
            return min($seconds, 60); // Max 60 seconds wait
        }

        return 5; // Default conservative wait
    }

    /**
     * Parse Binance interval headers (e.g., X-MBX-USED-WEIGHT-1M, X-MBX-ORDER-COUNT-10S).
     * Returns array keyed by interval string (e.g., '1M', '10S') with parsed data.
     *
     * @param  array  $headers  Normalized headers (lowercase keys)
     * @param  string  $prefix  Header prefix to match (e.g., 'x-mbx-used-weight-')
     * @return array Array of ['intervalNum' => int, 'intervalLetter' => string, 'value' => int, 'interval' => string]
     */
    protected static function parseIntervalHeaders(array $headers, string $prefix): array
    {
        $result = [];

        foreach ($headers as $key => $value) {
            // Match headers like: x-mbx-used-weight-1m => 50
            if (! Str::startsWith($key, $prefix)) {
                continue;
            }

            // Extract interval part (e.g., "1m" from "x-mbx-used-weight-1m")
            $interval = Str::after($key, $prefix);

            // Parse intervalNum and intervalLetter (e.g., "1m" => num=1, letter=m)
            if (preg_match('/^(\d+)([smhd])$/i', $interval, $matches)) {
                $result[$interval] = [
                    'intervalNum' => (int) $matches[1],
                    'intervalLetter' => mb_strtolower($matches[2]),
                    'value' => (int) $value,
                    'interval' => $interval,
                ];
            }
        }

        return $result;
    }

    /**
     * Calculate TTL in seconds for a given interval.
     */
    protected static function getIntervalTTL(int $intervalNum, string $intervalLetter): int
    {
        return match (mb_strtolower($intervalLetter)) {
            's' => $intervalNum,
            'm' => $intervalNum * 60,
            'h' => $intervalNum * 3600,
            'd' => $intervalNum * 86400,
            default => 60, // Default to 1 minute
        };
    }

    /**
     * Normalize headers (lower-case keys, comma-joined values).
     */
    protected static function normalizeHeaders($msg): array
    {
        $headers = [];
        foreach ($msg->getHeaders() as $k => $vals) {
            $headers[mb_strtolower($k)] = implode(', ', $vals);
        }

        return $headers;
    }

    /**
     * Get current server IP address.
     */
    protected static function getCurrentIp(): string
    {
        return \Martingalian\Core\Models\Martingalian::ip();
    }
}
