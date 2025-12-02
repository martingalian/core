<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationHandlers;

/**
 * BybitNotificationHandler
 *
 * Maps Bybit API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 401: Authentication failed → server_ip_forbidden
 * - 200 with 10003/10004/10005/10007/10009/10010: API key/IP issues → server_ip_forbidden
 * - 403: IP rate limit breached → server_rate_limit_exceeded
 * - 429: IP auto-banned → server_rate_limit_exceeded
 * - 200 with 10006/10018/170005/170222: Rate limit errors → server_rate_limit_exceeded
 */
final class BybitNotificationHandler extends BaseNotificationHandler
{
    /**
     * HTTP codes that indicate server IP is forbidden/banned.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $serverForbiddenHttpCodes = [
        401,
        200 => [
            10003,   // API key is invalid or domain mismatch
            10004,   // Invalid signature
            10005,   // Permission denied, check API key permissions
            10007,   // User authentication failed
            10009,   // IP banned by exchange (permanent)
            10010,   // Unmatched IP, check API key's bound IP addresses
        ],
    ];

    /**
     * HTTP codes that indicate server rate limiting.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $serverRateLimitedHttpCodes = [
        403,
        429,
        200 => [
            10006,   // Too many visits (per-UID)
            10018,   // Exceeded IP rate limit
            170005,  // Exceeded max orders per time period
            170222,  // Too many requests
        ],
    ];

    public function getCanonical(int $httpCode, ?int $vendorCode): ?string
    {
        // Check rate limit first (more common)
        if ($this->matchesMapping($httpCode, $vendorCode, $this->serverRateLimitedHttpCodes)) {
            return 'server_rate_limit_exceeded';
        }

        // Check forbidden/banned
        if ($this->matchesMapping($httpCode, $vendorCode, $this->serverForbiddenHttpCodes)) {
            return 'server_ip_forbidden';
        }

        return null;
    }
}
