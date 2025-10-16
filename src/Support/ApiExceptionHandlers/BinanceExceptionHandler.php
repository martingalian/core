<?php

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;

/**
 * BinanceExceptionHandler
 *
 * Focus:
 * • Keep the trait generic; put Binance-specific rules here.
 * • Respect Retry-After and the new IP/Order/Weight semantics.
 * • Treat 418 as a rate-limit (temporary IP ban), not a “forbidden” credential error.
 * • Avoid introducing any Redis/local throttling here (handled elsewhere in your app).
 */
class BinanceExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    public function __construct()
    {
        // Base fallback when no Retry-After is available.
        $this->backoffSeconds = 10;
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
        403
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

    /**
     * Health check, kept simple.
     */
    public function ping(): bool
    {
        return true;
    }

    /**
     * Treat 418 as an IP ban escalation (temporary).
     */
    public function isIpBanned(\Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->hasResponse()
            && $exception->getResponse()->getStatusCode() === 418;
    }

    /**
     * Override: in addition to base maps, consider Binance vendor codes and some 403/WAF messages.
     */
    public function isRateLimited(\Throwable $exception): bool
    {
        if (parent::isRateLimited($exception)) {
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
        $msg = strtolower((string) ($meta['message'] ?? ''));
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
        $baseUntil = parent::rateLimitUntil($exception);

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
    public function backoffSeconds(\Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e) || in_array($e->getResponse()->getStatusCode(), [429, 418], true)) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                if ($this->isIpBanned($e)) {
                    // Be extra conservative on bans if no Retry-After was provided.
                    return max($delta, $this->backoffSeconds * 3);
                }

                return max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }
}
