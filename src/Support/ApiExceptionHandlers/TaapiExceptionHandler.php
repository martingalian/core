<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * TaapiExceptionHandler
 *
 * Focus:
 * • Taapi.io uses 15-second rate limit windows per plan tier
 * • Returns 429 when rate limit exceeded with JSON error message
 * • No Retry-After or custom rate limit headers documented
 * • Default to 15-second window boundary on rate limit
 */
final class TaapiExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Ignorable — no-ops / idempotent.
     * 400: Bad request (malformed parameters, invalid data)
     */
    public array $ignorableHttpCodes = [
        400,
    ];

    /**
     * Retryable — transient conditions.
     * 503: Service unavailable (temporary)
     * 504: Gateway timeout
     */
    public array $retryableHttpCodes = [
        503,
        504,
    ];

    /**
     * Forbidden — authentication/authorization failures.
     * 401: Unauthorized (invalid/missing API key)
     * 402: Payment required (subscription expired)
     * 403: Forbidden (insufficient permissions)
     */
    public array $forbiddenHttpCodes = [
        401,
        402,
        403,
    ];

    /**
     * Rate-limited — exceeded request quota.
     * 429: Too Many Requests (exceeded plan's request limit)
     */
    public array $rateLimitedHttpCodes = [
        429,
    ];

    public array $recvWindowMismatchedHttpCodes = [];

    public function __construct()
    {
        // Conservative backoff when no rate limit info available
        $this->backoffSeconds = 5;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getApiSystem(): string
    {
        return 'taapi';
    }

    /**
     * Get the maximum number of calculations allowed per bulk request.
     * Based on Taapi Expert plan limits.
     *
     * @return int Maximum calculations per request
     */
    public function getMaxCalculationsPerRequest(): int
    {
        return 20;
    }

    /**
     * Override: compute retry time for Taapi's 15-second rate limit windows.
     *
     * Rules per Taapi.io:
     * • Rate limits are enforced in 15-second windows per plan
     * • HTTP 429 returned when limit exceeded with JSON error message
     * • No Retry-After or custom headers documented
     * • Window implementation details not specified (sliding vs. fixed)
     * • Conservative approach: wait full 15 seconds from rate limit
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        // 1) Use base logic first (honors Retry-After if present)
        $baseUntil = $this->baseRateLimitUntil($exception);

        // If parent already derived a future time (e.g., via Retry-After), keep it
        if ($baseUntil->greaterThan($now)) {
            return $baseUntil;
        }

        // 2) No Retry-After: wait a full 3 seconds from now
        // Since Taapi doesn't document window boundaries or reset timing,
        // conservatively wait the full window duration
        $resetAt = $now->copy()->addSeconds(3);

        // Add small jitter to avoid stampede
        return $resetAt->copy()->addMilliseconds(random_int(100, 300));
    }

    /**
     * Override: calculate backoff for rate limits considering Taapi's 15-second windows.
     */
    public function backoffSeconds(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e) || $e->getResponse()->getStatusCode() === 429) {
                $until = $this->rateLimitUntil($e);
                $delta = max(0, now()->diffInSeconds($until, false));

                return (int) max($delta, $this->backoffSeconds);
            }
        }

        return $this->backoffSeconds;
    }

    /**
     * No-op: TAAPI doesn't require response header tracking.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        // No-op - TAAPI uses simple throttling handled by TaapiThrottler
    }

    /**
     * No-op: TAAPI doesn't implement IP bans.
     */
    public function isCurrentlyBanned(): bool
    {
        return false;
    }

    /**
     * No-op: TAAPI doesn't require IP ban recording.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        // No-op - TAAPI doesn't implement IP bans
    }

    /**
     * No-op: TAAPI safety checks are handled by TaapiThrottler.
     * Always return true to allow normal throttling to proceed.
     */
    public function isSafeToMakeRequest(): bool
    {
        return true;
    }
}
