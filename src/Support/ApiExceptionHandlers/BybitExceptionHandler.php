<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Log;
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
     * Forbidden — real exchange-level IP bans.
     *
     * IMPORTANT:
     * • 10009 = IP banned by Bybit exchange (permanent until manual unban)
     * • 403 is NOT here (it's a temporary rate limit, handled as rate-limit)
     * • 10003/10004/10005/10007 are NOT here (API key credential issues, not server bans)
     * • 10010 is NOT here (IP whitelist configuration in API key settings, not a server ban)
     */
    protected array $forbiddenHttpCodes = [
        10009,   // IP banned by exchange (permanent)
    ];

    /**
     * Rate limit related error codes.
     * • 10006 = Too many visits (per-UID limit)
     * • 10018 = Exceeded IP rate limit
     * • 403 = HTTP-level IP rate limit (600 req/5s)
     */
    protected array $rateLimitedHttpCodes = [
        403,     // HTTP-level: IP rate limit breached (temporary, lifts after 10 minutes)
        10006,   // Too many visits (per-UID)
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
     * Server overload/busy codes - treated as maintenance (cannot process requests).
     * Critical during price crashes when exchanges get overloaded.
     */
    protected array $serverOverloadCodes = [
        10019,   // Service restarting
        170007,  // Backend timeout
        177002,  // Server busy
    ];

    /**
     * Invalid API key errors (credential issues).
     */
    protected array $invalidApiKeyCodes = [
        10003,   // API key is invalid
    ];

    /**
     * Invalid signature errors (credential issues).
     */
    protected array $invalidSignatureCodes = [
        10004,   // Error sign, signature generation issue
    ];

    /**
     * Insufficient permissions errors (API key permissions).
     */
    protected array $insufficientPermissionsCodes = [
        10005,   // Permission denied, API key permissions insufficient
    ];

    /**
     * IP not whitelisted errors (IP whitelist configuration).
     */
    protected array $ipNotWhitelistedCodes = [
        10010,   // Unmatched IP, IP not in API key whitelist
    ];

    /**
     * Account status errors - critical issues requiring account disabling.
     * These trigger can_trade = 0 on the account.
     */
    protected array $accountStatusCodes = [
        33004,   // API key expired
        10008,   // Common ban applied
        10024,   // Compliance rules triggered
        10027,   // Transactions are banned
        110023,  // Can only reduce positions
        110066,  // Trading currently not allowed
        10007,   // User authentication failed (also in forbidden)
    ];

    /**
     * Balance/margin insufficiency errors.
     */
    protected array $insufficientBalanceCodes = [
        110004,  // Insufficient wallet balance
        110007,  // Insufficient available balance
        110012,  // Insufficient available balance
        110044,  // Insufficient available margin
        110045,  // Insufficient wallet balance
    ];

    /**
     * KYC verification required errors.
     */
    protected array $kycRequiredCodes = [
        20096,   // KYC authentication required
    ];

    /**
     * System errors - unknown errors and timeouts.
     */
    protected array $systemErrorCodes = [
        10016,   // Internal server error
        10000,   // Server timeout
        10002,   // Request time exceeds acceptable window
    ];

    /**
     * Network errors - connectivity issues.
     */
    protected array $networkErrorCodes = [
        170032,  // Network error
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
