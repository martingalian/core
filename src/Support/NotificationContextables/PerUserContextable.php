<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationContextables;

use Martingalian\Core\Models\ApiRequestLog;

/**
 * Throttle per user - each user gets independent throttle window.
 * Used for user-specific notifications (email bounces, personal alerts).
 */
final class PerUserContextable
{
    public function __invoke(ApiRequestLog $log): ?string
    {
        $user = $log->account?->user;

        return $user ? "user:{$user->id}" : null;
    }
}
