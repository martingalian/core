<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationHandlers;

/**
 * BinanceNotificationHandler
 *
 * Maps Binance API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 418: IP auto-banned (teapot error) → server_ip_forbidden
 * - 429: Too many requests → server_rate_limit_exceeded
 * - 400 with -1003: WAF limit exceeded → server_rate_limit_exceeded
 */
final class BinanceNotificationHandler extends BaseNotificationHandler
{
    /**
     * HTTP codes that indicate server IP is forbidden/banned.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $serverForbiddenHttpCodes = [418];

    /**
     * HTTP codes that indicate server rate limiting.
     *
     * @var array<int, array<int, int>|int>
     */
    public array $serverRateLimitedHttpCodes = [
        429,
        400 => [-1003],
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
