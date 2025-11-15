<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationContextables;

use Martingalian\Core\Models\ApiRequestLog;

/**
 * Throttle per account - each account gets independent throttle window.
 * Used for account-specific issues like API credentials, permissions, balance.
 */
final class PerAccountContextable
{
    public function __invoke(ApiRequestLog $log): ?string
    {
        $account = $log->account;

        return $account ? "account:{$account->id}" : null;
    }
}
