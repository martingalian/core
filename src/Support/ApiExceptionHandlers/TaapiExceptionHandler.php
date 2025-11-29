<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
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
        ignoreException as baseIgnoreException;
    }

    public function __construct()
    {
        // Conservative backoff when no rate limit info available
        $this->backoffSeconds = 5;
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
     * HTTP-level errors that can be retried.
     */
    public array $retryableHttpCodes = [
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
        408,     // Request timeout
    ];

    /**
     * Server forbidden — authentication/authorization failures (server cannot make ANY calls).
     * 401: Unauthorized (invalid/missing API key)
     * 402: Payment required (subscription expired)
     * 403: Forbidden (insufficient permissions)
     */
    public array $serverForbiddenHttpCodes = [
        401,
        402,
        403,
    ];

    /**
     * Rate-limited — exceeded request quota.
     * 429: Too Many Requests (exceeded plan's request limit)
     */
    public array $serverRateLimitedHttpCodes = [
        429,
    ];

    /**
     * recvWindow mismatches: not applicable for Taapi.io.
     */
    public array $recvWindowMismatchedHttpCodes = [];

    /**
     * Ping the Taapi API to check connectivity.
     */
    public function ping(): bool
    {
        // Taapi doesn't have a dedicated ping endpoint
        // Return true as we'll discover issues through actual API calls
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
     * Error messages that should NOT be ignored even if HTTP 400.
     * These indicate configuration/plan errors rather than invalid input data.
     *
     * @var string[]
     */
    private array $nonIgnorableErrorPatterns = [
        'constructs than your plan allows',  // Plan limit exceeded
        'calculations than your plan allows', // Plan limit exceeded (alternate wording)
    ];

    /**
     * Override: conditionally ignore HTTP 400 based on error message content.
     *
     * Some 400s are ignorable (invalid symbol, malformed params) while others
     * indicate configuration issues that should cause the job to fail (plan limit exceeded).
     */
    public function ignoreException(Throwable $exception): bool
    {
        // First check if it would normally be ignored
        if (! $this->baseIgnoreException($exception)) {
            return false;
        }

        // If it's ignorable by HTTP code, check the response body for non-ignorable patterns
        // TAAPI returns {"errors": [...]} format, not {"code": ..., "msg": ...}
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = mb_strtolower((string) $exception->getResponse()->getBody());

            // Check for patterns that should NOT be ignored
            foreach ($this->nonIgnorableErrorPatterns as $pattern) {
                if (str_contains($body, mb_strtolower($pattern))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Override: compute retry time for Taapi's 15-second rate limit windows.
     *
     * Rules per Taapi.io:
     * • Rate limits are enforced in 15-second windows per plan
     * • HTTP 429 returned when limit exceeded with JSON error message
     * • No Retry-After or custom headers documented
     * • Window implementation details not specified (sliding vs. fixed)
     * • Conservative approach: wait full 3 seconds from rate limit
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
        // conservatively wait 3 seconds
        $resetAt = $now->copy()->addSeconds(3);

        // Add small jitter to avoid stampede
        return $resetAt->copy()->addMilliseconds(random_int(100, 300));
    }

    /**
     * Override: calculate backoff for rate limits considering Taapi's windows.
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
}
