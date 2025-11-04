<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Martingalian\Core\Support\Throttlers\BinanceThrottler;
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
     * Forbidden — real exchange-level IP bans.
     *
     * IMPORTANT:
     * • 403 is NOT here (it's treated as rate-limit when it has WAF/forbidden messages)
     * • 418 is NOT here (it's a temporary rate-limit ban, handled as rate limit)
     * • 401/-2015 is NOT here (it's an API key config issue, handled separately)
     */
    public array $forbiddenHttpCodes = [];

    /**
     * Rate-limited — slow down and back off.
     * • 429 Too Many Requests (may or may not carry Retry-After)
     * • 418 IP ban escalation (temporary, treat as rate-limit with longer backoff)
     *
     * NOTE: 401/-2015 is handled separately as an API key configuration issue.
     */
    public array $rateLimitedHttpCodes = [
        429,
        418,
    ];

    /**
     * recvWindow mismatches:
     * • -1021 (spot), -5028 (futures)
     */
    public $recvWindowMismatchedHttpCodes = [
        400 => [-1021, -5028],
    ];

    /**
     * Ambiguous credential/IP/permission errors.
     * -2015 REJECTED_MBX_KEY: "Invalid API-key, IP, or permissions for action"
     * This single error code covers 3 scenarios:
     * 1. Invalid API credentials
     * 2. IP not whitelisted
     * 3. Insufficient API key permissions
     */
    protected array $credentialsOrIpCodes = [
        -2015,
    ];

    /**
     * Account status errors - critical issues requiring account disabling.
     * These trigger can_trade = 0 on the account.
     */
    protected array $accountStatusCodes = [
        -2017,   // API keys locked
        -2023,   // User in liquidation
        -4087,   // Reduce-only order permission
        -4088,   // No place order permission
        -4400,   // Trading quantitative rule (risk control)
        -1002,   // Unauthorized
    ];

    /**
     * Balance/margin insufficiency errors.
     */
    protected array $insufficientBalanceCodes = [
        -2018,   // Balance not sufficient
        -2019,   // Margin not sufficient
    ];

    /**
     * KYC verification required errors.
     */
    protected array $kycRequiredCodes = [
        -4202,   // Adjust leverage KYC failed (>20x requires enhanced KYC)
    ];

    /**
     * System errors - unknown errors and timeouts.
     */
    protected array $systemErrorCodes = [
        -1000,   // Unknown error
        -1007,   // Timeout
        -1008,   // Server overload - request throttled
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
     * Record response headers for IP-based rate limiting coordination.
     * Delegates to BinanceThrottler to parse and cache rate limit headers.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        BinanceThrottler::recordResponseHeaders($response);
    }

    /**
     * Check if current server IP is banned by Binance.
     * Delegates to BinanceThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return BinanceThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 418/429 errors with Retry-After occur.
     * Delegates to BinanceThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        BinanceThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status, rate limit proximity (>80%).
     * Delegates to BinanceThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned or approaching rate limits, return false
        return BinanceThrottler::isSafeToDispatch() === 0;
    }

    /**
     * Parse Binance interval headers (e.g., X-MBX-USED-WEIGHT-1M, X-MBX-ORDER-COUNT-10S).
     * Used internally by rateLimitUntil() to calculate retry times.
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
}
