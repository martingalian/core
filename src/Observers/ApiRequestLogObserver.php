<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\ApiSystem;

final class ApiRequestLogObserver
{
    /**
     * Handle the ApiRequestLog "saved" event.
     * Triggers notifications for API errors based on HTTP response codes.
     */
    public function saved(ApiRequestLog $log): void
    {
        // Skip if no HTTP response code yet (request still in progress)
        if ($log->http_response_code === null) {
            return;
        }

        // Skip if successful response (2xx or 3xx)
        if ($log->http_response_code < 400) {
            return;
        }

        // Load API system to determine which exception handler to use
        $apiSystem = ApiSystem::find($log->api_system_id);
        if (! $apiSystem) {
            return;
        }

        // Create the appropriate exception handler for this API system
        $handler = BaseExceptionHandler::make($apiSystem->canonical);

        // Case 1: User-level API call (has account_id)
        if ($log->account_id) {
            $account = Account::find($log->account_id);
            if ($account) {
                $handler->withAccount($account);
                $handler->notifyFromApiLog($log);
            }

            return;
        }

        // Case 2: System-level API call (account_id is NULL)
        // These are Account::admin() calls - notify admin only
        $handler->notifyFromApiLogToAdmin($log);
    }
}
