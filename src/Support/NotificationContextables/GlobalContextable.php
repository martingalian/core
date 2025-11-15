<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationContextables;

use Martingalian\Core\Models\ApiRequestLog;

/**
 * Global throttle - single throttle window for all notifications system-wide.
 * Used for system-level issues that affect all users (rate limits, maintenance, system errors).
 */
final class GlobalContextable
{
    public function __invoke(ApiRequestLog $log): ?string
    {
        return null;  // No context = global throttle
    }
}
