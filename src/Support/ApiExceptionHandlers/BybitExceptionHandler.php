<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Throwable;

final class BybitExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Errors that should be ignored (no action needed).
     */
    protected array $ignorableHttpCodes = [
        34040,   // Not modified (value already set)
        110025,  // Position mode unchanged
        110026,  // Margin mode unchanged
        110043,  // Leverage unchanged
    ];

    /**
     * Errors that can be retried (transient issues).
     */
    protected array $retryableHttpCodes = [
        10019,   // Service restarting
        170007,  // Backend timeout
        177002,  // Server busy
    ];

    /**
     * Errors indicating authentication/authorization failures.
     */
    protected array $forbiddenHttpCodes = [
        10003,   // Invalid API key
        10004,   // Signature error
        10005,   // Permission denied
        10007,   // Authentication failed
        10009,   // IP banned
        10010,   // Unmatched IP address
    ];

    /**
     * Rate limit related error codes.
     */
    protected array $rateLimitedHttpCodes = [
        10006,   // Too many visits
        10018,   // Exceeded IP rate limit
        170005,  // Exceeded max orders per time period
        170222,  // Too many requests
    ];

    /**
     * Bybit-specific rate limit codes from response body.
     */
    protected array $bybitRateLimitCodes = [
        10006,
        10018,
        170005,
        170222,
    ];

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
     * Check if exception is rate limited.
     */
    public function isRateLimited(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        if (! $exception->hasResponse()) {
            return false;
        }

        $response = $exception->getResponse();
        $statusCode = $response->getStatusCode();

        // HTTP 429 is always rate limiting
        if ($statusCode === 429) {
            return true;
        }

        // HTTP 403 can be rate limiting
        if ($statusCode === 403) {
            return true;
        }

        // Check for Bybit retCode in response body
        $data = $this->extractHttpErrorCodes($exception);
        $retCode = $data['status_code'] ?? null;

        return $retCode !== null && in_array($retCode, $this->bybitRateLimitCodes, true);
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
        $now = Carbon::now();

        // 1) Use base logic first (honors Retry-After or falls back)
        $baseUntil = $this->baseRateLimitUntil($exception);

        // If base already derived a future time (e.g., via Retry-After), keep it.
        if ($baseUntil->greaterThan($now)) {
            return $baseUntil;
        }

        // 2) No usable Retry-After: check Bybit-specific rate limit headers
        $headers = $this->normalizeHeaders($exception->getResponse());

        // X-Bapi-Limit-Reset-Timestamp (millisecond timestamp when rate limit resets)
        if (isset($headers['x-bapi-limit-reset-timestamp'])) {
            $resetTimestamp = (int) $headers['x-bapi-limit-reset-timestamp'];
            if ($resetTimestamp > 0) {
                $resetAt = Carbon::createFromTimestampMs($resetTimestamp)->utc();

                // Add tiny jitter to avoid stampede at exact boundary
                return $resetAt->copy()->addMilliseconds(random_int(20, 80));
            }
        }

        // 3) Last resort: use the base backoff result
        return $baseUntil;
    }

    /**
     * Check if exception should be retried.
     */
    public function retryException(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        if (! $exception->hasResponse()) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($exception);
        $retCode = $data['status_code'] ?? null;

        return $retCode !== null && in_array($retCode, $this->retryableHttpCodes, true);
    }

    /**
     * Check if exception should be ignored.
     */
    public function ignoreException(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        if (! $exception->hasResponse()) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($exception);
        $retCode = $data['status_code'] ?? null;

        return $retCode !== null && in_array($retCode, $this->ignorableHttpCodes, true);
    }

    /**
     * Check if exception is forbidden (auth/permission error).
     */
    public function isForbidden(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        if (! $exception->hasResponse()) {
            return false;
        }

        $response = $exception->getResponse();
        $statusCode = $response->getStatusCode();

        // HTTP 401 is always forbidden
        if ($statusCode === 401) {
            return true;
        }

        $data = $this->extractHttpErrorCodes($exception);
        $retCode = $data['status_code'] ?? null;

        return $retCode !== null && in_array($retCode, $this->forbiddenHttpCodes, true);
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

    /**
     * Extract Bybit error codes from response body.
     * Bybit uses {retCode, retMsg} structure.
     * Overrides parent to map Bybit's retCode to status_code.
     */
    protected function extractHttpErrorCodes(Throwable|\Psr\Http\Message\ResponseInterface $input): array
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
}
