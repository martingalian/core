<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\Throttlers;

use Martingalian\Core\Abstracts\BaseApiThrottler;

/**
 * TaapiThrottler
 *
 * Rate limiter for TAAPI.IO API requests.
 * Enforces Expert plan limits: 75 requests per 15 seconds.
 *
 * Usage:
 *   $secondsToWait = TaapiThrottler::canDispatch();
 *   if ($secondsToWait > 0) {
 *       // Need to wait, retry job with delay
 *       $this->retryJob(now()->addSeconds($secondsToWait));
 *       return;
 *   }
 *   TaapiThrottler::recordDispatch();
 *   // Make API request...
 */
final class TaapiThrottler extends BaseApiThrottler
{
    /**
     * TAAPI Expert Plan Rate Limits:
     * - 75 requests per 15 seconds
     * - Translates to 300 requests/minute, 18,000 requests/hour
     */
    protected static function getRateLimitConfig(): array
    {
        return [
            'requests_per_window' => 75,
            'window_seconds' => 15,
            'min_delay_between_requests_ms' => 200, // 200ms between requests for safety
        ];
    }

    protected static function getCacheKeyPrefix(): string
    {
        return 'taapi_throttler';
    }
}
