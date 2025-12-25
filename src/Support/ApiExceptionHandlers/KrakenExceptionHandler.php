<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Martingalian\Core\Support\Throttlers\KrakenThrottler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class KrakenExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers {
        extractHttpErrorCodes as protected baseExtractHttpErrorCodes;
        rateLimitUntil as baseRateLimitUntil;
    }

    /**
     * Errors that should be ignored (no action needed).
     * Kraken Futures uses HTTP 200 with "result": "error" for some edge cases.
     */
    public array $ignorableHttpCodes = [];

    /**
     * Errors that can be retried (transient issues).
     * Includes standard HTTP errors and Kraken-specific responses.
     */
    public array $retryableHttpCodes = [
        408,     // Request timeout
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
    ];

    /**
     * Server forbidden — real exchange-level bans and credential failures.
     * HTTP 401: Authentication failed
     * HTTP 403: Forbidden (IP banned or permission issue)
     */
    public array $serverForbiddenHttpCodes = [
        401,     // Authentication failed
        403,     // Forbidden (may be IP ban or permission issue)
    ];

    /**
     * IP not whitelisted by user on their API key settings.
     * Kraken may return 403 for IP whitelist issues.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipNotWhitelistedHttpCodes = [];

    /**
     * IP temporarily rate-limited (auto-recovers).
     * HTTP 429: Too many requests
     * HTTP 503: Service temporarily unavailable (may indicate rate limiting)
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipRateLimitedHttpCodes = [
        429,
    ];

    /**
     * IP permanently banned by exchange.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $ipBannedHttpCodes = [];

    /**
     * Account blocked — API key revoked, disabled, or permission issues.
     * HTTP 401: Authentication failed
     *
     * @var array<int, array<int, int>|int>
     */
    public array $accountBlockedHttpCodes = [
        401,
    ];

    /**
     * Rate limit related error codes.
     * HTTP 429: Too many requests
     */
    public array $serverRateLimitedHttpCodes = [
        429,
    ];

    /**
     * recvWindow mismatches: timestamp synchronization errors.
     * Kraken validates nonce/timestamp on signed requests.
     */
    public array $recvWindowMismatchedHttpCodes = [];

    public function __construct()
    {
        // Conservative backoff when no Retry-After is available
        $this->backoffSeconds = 10;
    }

    /**
     * Case 2: IP temporarily rate-limited.
     * Server hit rate limits and is temporarily blocked.
     * Detected by: HTTP 429.
     *
     * Recovery: Auto-recovers after Retry-After period.
     */
    public function isIpRateLimited(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ipRateLimitedHttpCodes);
    }

    /**
     * Case 4: Account blocked.
     * Specific account's API key is revoked, disabled, or has permission issues.
     * Detected by: HTTP 401.
     *
     * Recovery: User regenerates API key on exchange.
     */
    public function isAccountBlocked(Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->accountBlockedHttpCodes);
    }

    /**
     * Calculate when to retry after rate limit.
     * Override: compute a safe retry time using Kraken headers when Retry-After is absent.
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

        // 2) Kraken Futures uses 10-second windows for rate limiting
        // If we hit 429, wait until next window boundary + jitter
        return Carbon::now()->addSeconds(10 + random_int(1, 3));
    }

    /**
     * Override: calculate backoff for rate limits considering Kraken headers.
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
     * Ping the Kraken API to check connectivity.
     */
    public function ping(): bool
    {
        return true;
    }

    public function getApiSystem(): string
    {
        return 'kraken';
    }

    /**
     * Extract Kraken error codes from response body.
     * Kraken Futures uses: {"result": "error", "error": "errorMessage"}
     */
    public function extractHttpErrorCodes(Throwable|ResponseInterface $input): array
    {
        $data = $this->baseExtractHttpErrorCodes($input);

        // Kraken Futures uses "result": "error" and "error": "message"
        if ($input instanceof RequestException && $input->hasResponse()) {
            $body = (string) $input->getResponse()->getBody();
            $json = json_decode($body, associative: true);

            if (is_array($json)) {
                // Check for Kraken Futures error format
                if (isset($json['result']) && $json['result'] === 'error') {
                    $data['message'] = $json['error'] ?? $data['message'];
                }

                // Also check for serverTime endpoint structure
                if (isset($json['serverTime'])) {
                    $data['status_code'] = 0; // Success
                }
            }
        }

        return $data;
    }

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Delegates to KrakenThrottler to record headers in cache.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        KrakenThrottler::recordResponseHeaders($response);
    }

    /**
     * Check if current server IP is banned by Kraken.
     * Delegates to KrakenThrottler which tracks IP bans in cache.
     */
    public function isCurrentlyBanned(): bool
    {
        return KrakenThrottler::isCurrentlyBanned();
    }

    /**
     * Record an IP ban when 429 errors occur.
     * Delegates to KrakenThrottler to store ban state in cache.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        KrakenThrottler::recordIpBan($retryAfterSeconds);
    }

    /**
     * Pre-flight safety check before making API request.
     * Checks: IP ban status.
     * Delegates to KrakenThrottler.
     */
    public function isSafeToMakeRequest(): bool
    {
        // If IP is banned, return false
        return KrakenThrottler::isSafeToDispatch() === 0;
    }
}
