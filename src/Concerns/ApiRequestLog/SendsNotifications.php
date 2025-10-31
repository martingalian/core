<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ApiRequestLog;

use App\Support\NotificationMessageBuilder;
use App\Support\NotificationService;
use App\Support\Throttler;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;

/**
 * SendsNotifications
 *
 * Handles notification logic for ApiRequestLog based on HTTP response codes.
 * This is the single source of truth for API error notifications.
 */
trait SendsNotifications
{
    /**
     * Send notification if this API request log represents an error.
     * This is called by ApiRequestLogObserver after the log is saved.
     */
    public function sendNotificationIfNeeded(): void
    {
        // Skip if no HTTP response code yet (request still in progress)
        if ($this->http_response_code === null) {
            return;
        }

        // Skip if successful response (2xx or 3xx)
        if ($this->http_response_code < 400) {
            return;
        }

        // Load API system to determine which exception handler to use for code analysis
        $apiSystem = ApiSystem::find($this->api_system_id);
        if (! $apiSystem) {
            return;
        }

        // Create the appropriate exception handler for HTTP code analysis
        $handler = BaseExceptionHandler::make($apiSystem->canonical);

        // Case 1: User-level API call (has account_id)
        if ($this->account_id) {
            $account = Account::find($this->account_id);
            if ($account) {
                $this->sendUserNotification($handler, $account);
            }

            return;
        }

        // Case 2: System-level API call (account_id is NULL)
        // These are Account::admin() calls - notify admin only
        $this->sendAdminNotification($handler);
    }

    /**
     * Send notification for user-level API calls.
     */
    protected function sendUserNotification(BaseExceptionHandler $handler, Account $account): void
    {
        $apiSystem = $handler->getApiSystem();
        $hostname = $this->hostname ?? gethostname();
        $hasSpecificUser = $account->user !== null;
        $httpCode = $this->http_response_code;

        // Extract vendor-specific error code from response
        $vendorCode = $this->extractVendorCodeFromResponse();

        // 429 / 418 / 403 - Rate Limit
        if ($handler->isRateLimitedFromLog($httpCode, $vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_rate_limit_exceeded',
                hostname: $hostname
            );

            return;
        }

        // 403 / 401 with forbidden codes - API Access Denied (ambiguous error)
        if ($handler->isForbiddenFromLog($httpCode, $vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_access_denied',
                hostname: $hostname
            );

            return;
        }

        // 401 - Invalid credentials (without specific vendor codes - generic 401)
        if ($httpCode === 401) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_access_denied',
                hostname: $hostname,
                disableAccount: true  // Disable trading - cannot access account
            );

            return;
        }

        // NEW: Account status errors - CRITICAL errors requiring account disabling
        if ($handler->isAccountStatusErrorFromLog($vendorCode)) {
            // Map vendor codes to specific canonicals
            $canonical = $this->mapAccountStatusCodeToCanonical($vendorCode, $handler->getApiSystem());

            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: $canonical,
                hostname: $hostname,
                disableAccount: true  // Disable trading
            );

            return;
        }

        // NEW: Insufficient balance/margin - high priority but don't disable account
        if ($handler->isInsufficientBalanceFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'insufficient_balance_margin',
                hostname: $hostname
            );

            return;
        }

        // NEW: KYC verification required - limits features but doesn't disable
        if ($handler->isKycRequiredFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'kyc_verification_required',
                hostname: $hostname
            );

            return;
        }

        // NEW: System errors (admin notifications - timeouts, unknown errors)
        if ($handler->isSystemErrorFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_system_error',
                hostname: $hostname
            );

            return;
        }

        // NEW: Network errors (admin notifications)
        if ($handler->isNetworkErrorFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_network_error',
                hostname: $hostname
            );

            return;
        }

        // 503/504 or Server Overload - Service unavailable / Maintenance / Server Busy
        // CRITICAL: During price crashes, exchanges get overloaded and cannot process requests
        if ($handler->isServerOverloadFromLog($httpCode, $vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'exchange_maintenance',
                hostname: $hostname
            );

            return;
        }

        // Connection failures (no http_response_code but has error_message)
        if (! $httpCode && $this->error_message) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_connection_failed',
                hostname: $hostname
            );

            return;
        }
    }

    /**
     * Map account status error codes to specific notification canonicals.
     */
    protected function mapAccountStatusCodeToCanonical(int $vendorCode, string $apiSystem): string
    {
        // Binance error codes
        if ($apiSystem === 'binance') {
            return match ($vendorCode) {
                -2015 => 'invalid_api_credentials',  // Invalid API key / IP not allowed
                -2017 => 'invalid_api_credentials',  // API keys locked (treat as creds invalid)
                -2023 => 'account_in_liquidation',   // User in liquidation
                -4087, -4088 => 'account_reduce_only_mode',  // Reduce-only / no place order permission
                -4400 => 'account_trading_banned',   // Risk control triggered
                -1002 => 'account_unauthorized',     // Unauthorized
                default => 'account_trading_banned', // Fallback for unknown account status errors
            };
        }

        // Bybit error codes
        if ($apiSystem === 'bybit') {
            return match ($vendorCode) {
                33004 => 'api_key_expired',          // API key expired
                10007 => 'account_unauthorized',     // User authentication failed
                10008 => 'account_trading_banned',   // Common ban applied
                10024 => 'account_trading_banned',   // Compliance rules triggered
                10027 => 'account_trading_banned',   // Transactions are banned
                110023 => 'account_reduce_only_mode', // Can only reduce positions
                110066 => 'account_trading_banned',  // Trading currently not allowed
                default => 'account_trading_banned', // Fallback for unknown account status errors
            };
        }

        // Fallback for other API systems
        return 'account_trading_banned';
    }

    /**
     * Send notification for admin-only (system-level) API calls.
     */
    protected function sendAdminNotification(BaseExceptionHandler $handler): void
    {
        $apiSystem = $handler->getApiSystem();
        $hostname = $this->hostname ?? gethostname();
        $httpCode = $this->http_response_code;
        $vendorCode = $this->extractVendorCodeFromResponse();
        $prefixedCanonical = $apiSystem.'_';

        // Rate Limit
        if ($handler->isRateLimitedFromLog($httpCode, $vendorCode)) {
            $prefixedCanonical .= 'api_rate_limit_exceeded';

            // Load account if available
            $accountInfo = 'System-level API call (no specific account)';
            if ($this->account_id) {
                $account = Account::find($this->account_id);
                if ($account) {
                    $accountInfo = "Account ID: {$account->id}";
                    if ($account->user) {
                        $accountInfo .= " (User: {$account->user->name})";
                    }
                }
            }

            $serverIp = gethostbyname($hostname);
            $messageData = NotificationMessageBuilder::build(
                canonical: 'api_rate_limit_exceeded',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $hostname,  // Use hostname as IP for display purposes
                    'hostname' => $hostname,
                    'account_info' => $accountInfo,
                ]
            );

            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::sendToAdmin(
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        deliveryGroup: 'exceptions',
                        severity: $messageData['severity'],
                        pushoverMessage: $messageData['pushoverMessage'],
                        actionUrl: $messageData['actionUrl'],
                        actionLabel: $messageData['actionLabel'],
                        exchange: $apiSystem,
                        serverIp: $serverIp
                    );
                });

            return;
        }

        // Forbidden / API Access Denied (ambiguous)
        if ($handler->isForbiddenFromLog($httpCode, $vendorCode)) {
            $prefixedCanonical .= 'api_access_denied';
            $serverIp = gethostbyname(gethostname());
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $hostname, $serverIp) {
                    NotificationService::sendToAdmin(
                        message: "System API access denied on {$apiSystem} from {$hostname}",
                        title: 'System API Access Denied',
                        deliveryGroup: 'exceptions',
                        exchange: $apiSystem,
                        serverIp: $serverIp
                    );
                });

            return;
        }

        // Invalid credentials (generic 401)
        if ($httpCode === 401) {
            $prefixedCanonical .= 'api_access_denied';
            $serverIp = gethostbyname(gethostname());
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $hostname, $serverIp) {
                    NotificationService::sendToAdmin(
                        message: "System API access denied on {$apiSystem} from {$hostname}",
                        title: 'System API Access Denied',
                        deliveryGroup: 'exceptions',
                        exchange: $apiSystem,
                        serverIp: $serverIp
                    );
                });

            return;
        }

        // Service unavailable / Server Overload
        // CRITICAL: During price crashes, exchanges get overloaded and cannot process requests
        if ($handler->isServerOverloadFromLog($httpCode, $vendorCode)) {
            $prefixedCanonical .= 'exchange_maintenance';
            $serverIp = gethostbyname(gethostname());
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $serverIp) {
                    NotificationService::sendToAdmin(
                        message: "{$apiSystem} is currently under maintenance, unavailable, or overloaded",
                        title: 'System API Maintenance/Overload',
                        deliveryGroup: 'exceptions',
                        exchange: $apiSystem,
                        serverIp: $serverIp
                    );
                });

            return;
        }

        // Connection failures
        if (! $httpCode && $this->error_message) {
            $prefixedCanonical .= 'api_connection_failed';
            $serverIp = gethostbyname(gethostname());
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $hostname, $serverIp) {
                    NotificationService::sendToAdmin(
                        message: "Unable to connect to {$apiSystem} from {$hostname}",
                        title: 'System API Connection Failed',
                        deliveryGroup: 'exceptions',
                        exchange: $apiSystem,
                        serverIp: $serverIp
                    );
                });

            return;
        }
    }

    /**
     * Send throttled notification to user or admin based on user_types.
     */
    protected function sendThrottledNotification(
        BaseExceptionHandler $handler,
        Account $account,
        bool $hasSpecificUser,
        string $messageCanonical,
        string $hostname,
        bool $disableAccount = false
    ): void {
        // Check who should receive this notification type
        $notification = \App\Models\Notification::findByCanonical($messageCanonical);
        if (! $notification) {
            return;
        }

        // Disable account if this is a critical error requiring trading to be stopped
        if ($disableAccount) {
            $account->update([
                'can_trade' => false,
                'disabled_reason' => $messageCanonical,
                'disabled_at' => now(),
            ]);
        }

        $userTypes = $notification->user_types ?? ['user'];
        $shouldSendToUser = in_array('user', $userTypes, true);
        $shouldSendToAdmin = in_array('admin', $userTypes, true);

        // Throttle canonical: Prefixed with API system to segregate throttle windows per API
        $throttleCanonical = $handler->getApiSystem().'_'.$messageCanonical;

        // Build account info string
        $exchange = ucfirst($handler->getApiSystem());
        $accountInfo = "Account ID: {$account->id}";
        if ($account->user) {
            $accountInfo = "Account ID: {$account->id} ({$account->user->name} / {$exchange})";
        }

        // Determine if this notification is server-related or account-related
        // Server-related notifications need IP/hostname context
        $serverRelatedCanonicals = [
            'api_rate_limit_exceeded',
            'api_connection_failed',
            'api_system_error',
            'api_network_error',
            'ip_not_whitelisted',
            'api_access_denied',
            'exchange_maintenance',
        ];

        $isServerRelated = in_array($messageCanonical, $serverRelatedCanonicals, true);

        // Build context - only include server info for server-related notifications
        $context = [
            'exchange' => $handler->getApiSystem(),
            'account_name' => ucfirst($handler->getApiSystem()).' Account #'.$account->id,
            'account_info' => $accountInfo,
        ];

        if ($isServerRelated) {
            $context['ip'] = $hostname;
            $context['hostname'] = $hostname;
        }

        // Build user-friendly message
        $messageData = NotificationMessageBuilder::build(
            canonical: $messageCanonical,
            context: $context,
            user: $hasSpecificUser ? $account->user : null
        );

        // Send to user if appropriate
        if ($shouldSendToUser && $hasSpecificUser) {
            $user = $account->user;
            $exchangeName = $handler->getApiSystem();
            $serverIp = $isServerRelated ? gethostbyname(gethostname()) : null;
            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->for($user)
                ->execute(function () use ($user, $messageData, $exchangeName, $serverIp) {
                    NotificationService::sendToUser(
                        user: $user,
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        deliveryGroup: 'exceptions',
                        severity: $messageData['severity'],
                        pushoverMessage: $messageData['pushoverMessage'],
                        actionUrl: $messageData['actionUrl'],
                        actionLabel: $messageData['actionLabel'],
                        exchange: $exchangeName,
                        serverIp: $serverIp
                    );
                });
        }

        // Send to admin if appropriate
        if ($shouldSendToAdmin && (! $hasSpecificUser || ! $shouldSendToUser)) {
            $exchangeName = $handler->getApiSystem();
            $serverIp = $isServerRelated ? gethostbyname(gethostname()) : null;
            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $exchangeName, $serverIp) {
                    NotificationService::sendToAdmin(
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        deliveryGroup: 'exceptions',
                        severity: $messageData['severity'],
                        pushoverMessage: $messageData['pushoverMessage'],
                        actionUrl: $messageData['actionUrl'],
                        actionLabel: $messageData['actionLabel'],
                        exchange: $exchangeName,
                        serverIp: $serverIp
                    );
                });
        }
    }

    /**
     * Extract vendor-specific error code from response.
     */
    protected function extractVendorCodeFromResponse(): ?int
    {
        $response = $this->response;

        if (! is_array($response)) {
            return null;
        }

        // Binance uses 'code', Bybit uses 'retCode'
        return $response['code'] ?? $response['retCode'] ?? null;
    }
}
