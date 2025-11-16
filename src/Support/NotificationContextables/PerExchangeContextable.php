<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationContextables;

use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\ApiSystem;

/**
 * Throttle per exchange - each exchange gets independent throttle window.
 * Used for exchange-level issues (maintenance, API downtime, system errors).
 */
final class PerExchangeContextable
{
    public function __invoke(ApiRequestLog $log): ?string
    {
        $apiSystem = ApiSystem::find($log->api_system_id);

        return $apiSystem ? "exchange:{$apiSystem->canonical}" : null;
    }
}
