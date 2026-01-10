<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationHandlers;

/**
 * KrakenNotificationHandler
 *
 * Maps Kraken API error codes to notification canonicals.
 *
 * HTTP Code Mappings:
 * - 401: Authentication failed → server_ip_forbidden
 * - 403: IP banned/permission issues → server_ip_forbidden
 * - 429: Too many requests → server_rate_limit_exceeded
 */
final class KrakenNotificationHandler extends BaseNotificationHandler
{
    public array $serverForbiddenHttpCodes = [401, 403];

    public array $serverRateLimitedHttpCodes = [429];
}
