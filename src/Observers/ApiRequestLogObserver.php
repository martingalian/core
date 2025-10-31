<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApiRequestLog;

final class ApiRequestLogObserver
{
    /**
     * Handle the ApiRequestLog "saved" event.
     * Triggers notifications for API errors based on HTTP response codes.
     */
    public function saved(ApiRequestLog $log): void
    {
        // Delegate to the model's notification logic
        $log->sendNotificationIfNeeded();
    }
}
