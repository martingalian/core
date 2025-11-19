<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Martingalian\Core\Support\Throttlers\BybitThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class BybitExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Errors that should be ignored (no action needed).
     * HTTP 200 with ignorable retCodes in response body.
     */
    public array $ignorableHttpCodes = [
        200 => [
            34040,   // Not modified (value already set)
            110025,  // Position mode unchanged
            110026,  // Margin mode unchanged
            110043,  // Leverage unchanged
        ],
    ];

    /**
     * Errors that can be retried (transient issues).
     * HTTP 200 with retryable retCodes in response body.
     */
    public array $retryableHttpCodes = [
        200 => [
            10019,   // Service restarting
            170007,  // Backend timeout
            177002,  // Server busy
        ],
    ];

    /**
     * Server forbidden — real exchange-level IP bans (server cannot make ANY calls).
     * HTTP 401: Authentication failed (invalid API key)
     * HTTP 200 with permanent IP ban retCode.
     *
     * IMPORTANT:
     * • 10009 = IP banned by Bybit exchange (permanent until manual unban)
     * • 403 is NOT here (it's a temporary rate limit, handled as rate-limit)
     * • 10003/10004/10005/10007 are NOT here (API key credential issues, not server bans)
     * • 10010 is NOT here (IP whitelist configuration in API key settings, not a server ban)
     */
    public array $serverForbiddenHttpCodes = [
        401,     // HTTP-level: Authentication failed
        200 => [
            10009,   // IP banned by exchange (permanent)
        ],
    ];

    /**
     * Rate limit related error codes.
     * • HTTP 200: Too many visits (per-UID limit), exceeded IP rate limit
     * • HTTP 403: IP rate limit breached (temporary, lifts after 10 minutes)
     * • HTTP 429: IP auto-banned for continuing after 429 codes
     */
    public array $serverRateLimitedHttpCodes = [
        403,     // HTTP-level: IP rate limit breached (temporary)
        429,     // HTTP-level: IP auto-banned
        200 => [
            10006,   // Too many visits (per-UID)
            10018,   // Exceeded IP rate limit
            170005,  // Exceeded max orders per time period
            170222,  // Too many requests
        ],
    ];

    public array $recvWindowMismatchedHttpCodes = [];

    /**
     * Check if exception represents an IP ban (HTTP 429).
     */
    public function isIpBanned(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        return $exception->hasResponse()
            && $exception->getResponse()->getStatusCode() === 429;
    }


    /**
     * Calculate when to retry after rate limit.
     * Override: compute a safe retry time using Bybit headers when Retry-After is absent.
     *
     * Rules:
     * • If Retry-After is present, honor it (handled by base trait).
     * • Check for Bybit-specific X-Bapi-Limit-Reset-Timestamp header.
     * • Otherwise fall back to base backoff.
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        // 1) Check if there's a retry-after header first
        $meta = $this->extractHttpMeta($exception);
        $retryAfter = mb_trim((string) ($meta['retry_after'] ?? ''));

        // If Retry-After is present, use base logic
        if ($retryAfter !== '') {
            return $this->baseRateLimitUntil($exception);
        }

        // 2) No Retry-After: check Bybit-specific rate limit headers
        $headers = $meta['headers'] ?? [];

        // X-Bapi-Limit-Reset-Timestamp (millisecond timestamp when rate limit resets)
        if (isset($headers['x-bapi-limit-reset-timestamp'])) {
            $resetTimestamp = (int) $headers['x-bapi-limit-reset-timestamp'];
            if ($resetTimestamp > 0) {
                $resetAt = Carbon::createFromTimestampMs($resetTimestamp)->utc();

                // Add tiny jitter to avoid stampede at exact boundary
                return $resetAt->copy()->addMilliseconds(random_int(20, 80));
            }
        }

        // 3) Last resort: use the base backoff
        return $this->baseRateLimitUntil($exception);
    }

    /**
     * Check if exception should be retried.
     */
    public function retryException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->retryableHttpCodes);
    }

    /**
     * Check if exception should be ignored.
     */
    public function ignoreException(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ignorableHttpCodes);
    }


    /**
     * Ping the Bybit API to check connectivity.
     */
    public function ping(): bool
    {
        // Bybit doesn't have a dedicated ping endpoint
        // We could use /v5/market/time but for now return true
        return true;
    }

    public function getApiSystem(): string
    {
        return 'bybit';
    }

    /**
     * Extract Bybit error codes from response body.
     * Bybit uses {retCode, retMsg} structure.
     * Overrides parent to map Bybit's retCode to status_code.
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $data = $this->baseExtractHttpErrorCodes($input);

        // Parent extracts 'code' by default, but Bybit uses 'retCode'
        if ($input instanceof RequestException && $input->hasResponse()) {
            $body = (string) $input->getResponse()->getBody();
            $json = json_decode($body, true);

            if (is_array($json) && isset($json['retCode'])) {
                $data['status_code'] = (int) $json['retCode'];
                $data['message'] = $json['retMsg'] ?? $data['message'];
            }
        }

        return $data;
    }

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Delegates to BybitThrottler to record headers in cache.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        BybitThrottler::recordResponseHeaders($response);
    }

    /**
     * Check if current server IP is banned by Bybit.
     * Delegates to BybitThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return BybitThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 429/403 errors occur.
     * Delegates to BybitThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        BybitThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status.
     * Delegates to BybitThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned, return false
        return BybitThrottler::isSafeToDispatch() === 0;
    }
}
