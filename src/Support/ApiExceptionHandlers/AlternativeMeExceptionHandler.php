<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;

/**
 * AlternativeMeExceptionHandler
 *
 * Focus:
 * • Alternative.me is a simple public API
 * • Rate limit: 60 requests per minute (10-minute window)
 * • Returns 429 when rate limit exceeded
 * • No authentication required for public endpoints
 */
final class AlternativeMeExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    public function __construct()
    {
        // Conservative backoff for simple API
        $this->backoffSeconds = 5;
    }

    /**
     * Ignorable — no-ops / idempotent.
     * 400: Bad request (malformed parameters)
     */
    public array $ignorableHttpCodes = [
        400,
    ];

    /**
     * Retryable — transient conditions.
     * Standard HTTP errors that can be retried.
     */
    public array $retryableHttpCodes = [
        408,     // Request timeout
        500,     // Internal server error
        502,     // Bad gateway
        503,     // Service unavailable
        504,     // Gateway timeout
    ];

    /**
     * Server forbidden — authentication/authorization failures.
     * 401: Unauthorized
     * 402: Payment required
     * 403: Forbidden
     */
    public array $serverForbiddenHttpCodes = [
        401,
        402,
        403,
    ];

    /**
     * Rate-limited — exceeded request quota.
     * 429: Too Many Requests (exceeded 60 requests per minute limit)
     */
    public array $serverRateLimitedHttpCodes = [
        429,
    ];

    /**
     * recvWindow mismatches: not applicable for Alternative.me.
     */
    public array $recvWindowMismatchedHttpCodes = [];

    /**
     * Ping the Alternative.me API to check connectivity.
     */
    public function ping(): bool
    {
        // Alternative.me doesn't have a dedicated ping endpoint
        // Return true as we'll discover issues through actual API calls
        return true;
    }

    public function getApiSystem(): string
    {
        return 'alternativeme';
    }
}
