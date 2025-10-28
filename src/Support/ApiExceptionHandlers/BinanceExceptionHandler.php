<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Log;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * BinanceExceptionHandler
 *
 * Focus:
 * • Keep the trait generic; put Binance-specific rules here.
 * • Respect Retry-After and the new IP/Order/Weight semantics.
 * • Treat 418 as a rate-limit (temporary IP ban), not a “forbidden” credential error.
 * • Avoid introducing any Redis/local throttling here (handled elsewhere in your app).
 */
final class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        isRateLimited as baseIsRateLimited;
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Ignorable — no-ops / idempotent.
     *
     * 400:
     *  -4046: No need to change margin type
     *  -5027: No need to modify the order
     *  -2011: Unknown order sent
     */
    public $ignorableHttpCodes = [
        400 => [-4046, -5027, -2011],
    ];

    /**
     * Retryable — transient conditions.
     *
     * Notes:
     *  -1021 / -5028 => recvWindow/timestamp mismatch (we will clock-sync + retry).
     *  -2013 can appear under load (eventual consistency) -- Order doesn't exist.
     */
    public $retryableHttpCodes = [
        503,
        504,
        409,
        400 => [-1021, -5028, -2013],
        408 => [-1007],
        -2013,
    ];

    /**
     * Forbidden — real credential/IP whitelist problems.
     *
     * IMPORTANT:
     * • Do NOT include 418 here (it's a rate-limit/IP ban escalation).
     */
    public array $forbiddenHttpCodes = [
        401 => [-2015],  // invalid API key / IP not allowed
    ];

    /**
     * Rate-limited — slow down and back off.
     * • 429 Too Many Requests (may or may not carry Retry-After)
     * • 418 IP ban escalation (always treat as rate-limit, not forbidden)
     * • 403 on Binance is often WAF throttling and should be treated as rate-limit-ish.
     */
    public array $rateLimitedHttpCodes = [
        429,
        418,
        403,
    ];

    /**
     * recvWindow mismatches:
     * • -1021 (spot), -5028 (futures)
     */
    public $recvWindowMismatchedHttpCodes = [
        400 => [-1021, -5028],
    ];

    /**
     * Binance vendor JSON codes that also indicate rate-limit conditions, even if HTTP isn’t 429.
     */
    protected array $binanceRateLimitCodes = [-1003, -1015];

    public function __construct()
    {
        // Base fallback when no Retry-After is available.
        $this->backoffSeconds = 10;
    }

    /**
     * Health check, kept simple.
     */
    public function ping(): bool
    {
        return true;
    }

    public function getApiSystem(): string
    {
        return 'binance';
    }

    /**
     * Treat 418 as an IP ban escalation (temporary).
     */
    public function isIpBanned(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->hasResponse()
            && $exception->getResponse()->getStatusCode() === 418;
    }

    /**
     * Override: in addition to base maps, consider Binance vendor codes and some 403/WAF messages.
     */
    public function isRateLimited(Throwable $exception): bool
    {
        if ($this->baseIsRateLimited($exception)) {
            return true;
        }

        if (! $exception instanceof RequestException || ! $exception->hasResponse()) {
            return false;
        }

        $meta = $this->extractHttpMeta($exception);

        // Vendor JSON codes: -1003 (too many requests), -1015 (too many new orders)
        $vendor = (int) ($meta['status_code'] ?? 0);
        if (in_array($vendor, $this->binanceRateLimitCodes, true)) {
            return true;
        }

        // Some throttles are surfaced as 403 with WAF-ish text; treat them as rate-limit.
        $http = (int) ($meta['http_code'] ?? 0);
        $msg = mb_strtolower((string) ($meta['message'] ?? ''));
        if ($http === 403 && (Str::contains($msg, 'waf') || Str::contains($msg, 'forbidden'))) {
            return true;
        }

        return false;
    }

    /**
     * Override: compute a safe retry time using Binance headers when Retry-After is absent.
     *
     * Rules per Binance:
     * • If Retry-After is present (429/418), honor it.
     * • For 429 caused by Unfilled-Order-Count, Retry-After may be missing — back off to the
     *   next interval boundary (prefer the *shortest* advertised interval).
     * • If no interval headers are present, conservatively wait to the next minute boundary.
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        // 1) Use base logic first (honors Retry-After or falls back)
        $baseUntil = $this->baseRateLimitUntil($exception);

        // If parent already derived a future time (e.g., via Retry-After), keep it.
        if ($baseUntil->greaterThan($now)) {
            return $baseUntil;
        }

        // 2) No usable Retry-After: examine Binance interval headers
        $meta = $this->extractHttpMeta($exception);
        $headers = $meta['headers'] ?? [];

        // Parse intervalized headers for both weight and order count
        $usedWeight = $this->parseIntervalHeaders($headers, 'x-mbx-used-weight-');
        $orderCount = $this->parseIntervalHeaders($headers, 'x-mbx-order-count-');

        // Choose the shortest available interval among both families
        $pick = function (array $arr): ?array {
            if ($arr === []) {
                return null;
            }
            // Order of magnitude: s < m < h < d; then smaller intervalNum first
            $rank = ['s' => 1, 'm' => 2, 'h' => 3, 'd' => 4];
            uasort($arr, function ($a, $b) use ($rank) {
                $la = $rank[$a['intervalLetter']] ?? 99;
                $lb = $rank[$b['intervalLetter']] ?? 99;
                if ($la === $lb) {
                    return $a['intervalNum'] <=> $b['intervalNum'];
                }

                return $la <=> $lb;
            });

            return reset($arr) ?: null;
        };

        $candidate = $pick($orderCount) ?? $pick($usedWeight);

        // Compute next window reset from server Date (if present) or local now.
        $serverNow = isset($meta['server_date']) ? Carbon::parse($meta['server_date']) : $now;

        if ($candidate) {
            $resetAt = $this->nextWindowResetAt(
                $serverNow,
                (int) $candidate['intervalNum'],
                (string) $candidate['intervalLetter']
            );

            // Tiny jitter to avoid stampede at the exact boundary
            return $resetAt->copy()->addMilliseconds(random_int(20, 80));
        }

        // 3) Last resort: next minute boundary (safe default for 429 without headers)
        return $serverNow->copy()->startOfMinute()->addMinute()->addMilliseconds(random_int(20, 80));
    }

    /**
     * Override: slightly escalate default when 418 (ban) and Retry-After absent.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e) || in_array($e->getResponse()->getStatusCode(), [429, 418], true)) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                if ($this->isIpBanned($e)) {
                    // Be extra conservative on bans if no Retry-After was provided.
                    return (int) max($delta, $this->backoffSeconds * 3);
                }

                return (int) max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }

    /**
     * Record Binance response headers in Redis for IP-based rate limit coordination.
     * Parses X-MBX-USED-WEIGHT-* and X-MBX-ORDER-COUNT-* headers and stores them
     * so all workers on the same IP can coordinate.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        try {
            $ip = $this->getCurrentIp();
            $headers = $this->normalizeHeaders($response);

            // Parse and store weight headers
            $weights = $this->parseIntervalHeaders($headers, 'x-mbx-used-weight-');
            foreach ($weights as $data) {
                $interval = $data['interval'];
                $ttl = $this->getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

                Cache::put(
                    "binance:{$ip}:weight:{$interval}",
                    $data['value'],
                    $ttl
                );
            }

            // Parse and store order count headers
            $orders = $this->parseIntervalHeaders($headers, 'x-mbx-order-count-');
            foreach ($orders as $data) {
                $interval = $data['interval'];
                $ttl = $this->getIntervalTTL($data['intervalNum'], $data['intervalLetter']);

                Cache::put(
                    "binance:{$ip}:orders:{$interval}",
                    $data['value'],
                    $ttl
                );
            }

            // Record timestamp of last request
            Cache::put("binance:{$ip}:last_request", now()->timestamp, 60);
        } catch (Throwable $e) {
            // Fail silently - don't break the application if Cache fails
            Log::warning("Failed to record Binance response headers: {$e->getMessage()}");
        }
    }

    /**
     * Check if the current server IP is currently banned by Binance.
     */
    public function isCurrentlyBanned(): bool
    {
        try {
            $ip = $this->getCurrentIp();
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
    public function recordIpBan(int $retryAfterSeconds): void
    {
        try {
            $ip = $this->getCurrentIp();
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
     * Pre-flight safety check before making a Binance API request.
     * Returns false if:
     * - IP is currently banned
     * - Too soon since last request (min delay)
     * - Approaching any rate limit (>80% threshold)
     */
    public function isSafeToMakeRequest(): bool
    {
        try {
            $ip = $this->getCurrentIp();

            // Check if IP is currently banned
            if ($this->isCurrentlyBanned()) {
                return false;
            }

            // Check minimum delay since last request
            $minDelayMs = config('martingalian.throttlers.binance.min_delay_ms', 200);
            $lastRequest = Cache::get("binance:{$ip}:last_request");

            if ($lastRequest) {
                $timeSinceLastRequest = (now()->timestamp - (int) $lastRequest) * 1000; // Convert to ms
                if ($timeSinceLastRequest < $minDelayMs) {
                    return false;
                }
            }

            // Check if approaching any rate limit (>80% threshold)
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

                $key = $type === 'ORDERS'
                    ? "binance:{$ip}:orders:{$interval}"
                    : "binance:{$ip}:weight:{$interval}";

                $current = Cache::get($key) ?? 0;

                if ($current / $limit > $safetyThreshold) {
                    Log::info("Binance rate limit safety threshold exceeded for {$interval}: {$current}/{$limit}");

                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            // Fail safe - if Cache fails, allow the request
            Log::warning("Failed to check Binance safety: {$e->getMessage()}");

            return true;
        }
    }

    /**
     * Parse Binance interval headers (e.g., X-MBX-USED-WEIGHT-1M, X-MBX-ORDER-COUNT-10S).
     * Returns array keyed by interval string (e.g., '1M', '10S') with parsed data.
     *
     * @param  array  $headers  Normalized headers (lowercase keys)
     * @param  string  $prefix  Header prefix to match (e.g., 'x-mbx-used-weight-')
     * @return array Array of ['intervalNum' => int, 'intervalLetter' => string, 'value' => int, 'interval' => string]
     */
    protected function parseIntervalHeaders(array $headers, string $prefix): array
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
    protected function getIntervalTTL(int $intervalNum, string $intervalLetter): int
    {
        return match (mb_strtolower($intervalLetter)) {
            's' => $intervalNum,
            'm' => $intervalNum * 60,
            'h' => $intervalNum * 3600,
            'd' => $intervalNum * 86400,
            default => 60, // Default to 1 minute
        };
    }
}
