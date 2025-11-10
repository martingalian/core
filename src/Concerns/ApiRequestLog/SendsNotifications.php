<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ApiRequestLog;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Notification;
use Martingalian\Core\Models\Repeater;
use Martingalian\Core\Models\Server;
use Martingalian\Core\Repeaters\ServerIpNotWhitelistedRepeater;
use Martingalian\Core\Support\NotificationMessageBuilder;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;

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

        // Binance ambiguous error -2015 (could be credentials, IP, or permissions)
        if ($handler->isCredentialsOrIpErrorFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'invalid_api_credentials',
                hostname: $hostname,
                disableAccount: true  // Disable trading - cannot access account
            );

            return;
        }

        // Bybit specific: Invalid API Key (10003)
        if ($handler->isInvalidApiKeyFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'invalid_api_key',
                hostname: $hostname,
                disableAccount: true  // Disable trading - cannot access account
            );

            return;
        }

        // Bybit specific: Invalid Signature (10004)
        if ($handler->isInvalidSignatureFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'invalid_signature',
                hostname: $hostname,
                disableAccount: true  // Disable trading - cannot access account
            );

            return;
        }

        // Bybit specific: Insufficient Permissions (10005)
        if ($handler->isInsufficientPermissionsFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'insufficient_permissions',
                hostname: $hostname,
                disableAccount: true  // Disable trading - some operations blocked
            );

            return;
        }

        // Bybit specific: IP Not Whitelisted (10010)
        if ($handler->isIpNotWhitelistedFromLog($vendorCode)) {
            $this->sendThrottledNotification(
                handler: $handler,
                account: $account,
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'ip_not_whitelisted',
                hostname: $hostname
            );

            // Create IP whitelist repeater for automatic retry
            $this->createIpWhitelistRepeater($handler, $account, $hostname);

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
        $serverIp = Martingalian::ip();

        // Binance ambiguous error -2015 (could be credentials, IP, or permissions)
        if ($handler->isCredentialsOrIpErrorFromLog($vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'invalid_api_credentials',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                    'account_info' => 'System-level API call (admin account)',
                    'account_name' => 'Admin Account',
                ]
            );

            $throttleCanonical = $apiSystem.'_invalid_api_credentials';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'invalid_api_credentials',
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

        // Bybit specific: Invalid API Key (10003)
        if ($handler->isInvalidApiKeyFromLog($vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'invalid_api_key',
                context: [
                    'exchange' => $apiSystem,
                    'account_name' => 'Admin Account',
                ]
            );

            $throttleCanonical = $apiSystem.'_invalid_api_key';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'invalid_api_key',
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

        // Bybit specific: Invalid Signature (10004)
        if ($handler->isInvalidSignatureFromLog($vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'invalid_signature',
                context: [
                    'exchange' => $apiSystem,
                    'account_name' => 'Admin Account',
                ]
            );

            $throttleCanonical = $apiSystem.'_invalid_signature';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'invalid_signature',
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

        // Bybit specific: Insufficient Permissions (10005)
        if ($handler->isInsufficientPermissionsFromLog($vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'insufficient_permissions',
                context: [
                    'exchange' => $apiSystem,
                    'account_name' => 'Admin Account',
                ]
            );

            $throttleCanonical = $apiSystem.'_insufficient_permissions';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'insufficient_permissions',
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

        // Bybit specific: IP Not Whitelisted (10010)
        if ($handler->isIpNotWhitelistedFromLog($vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'ip_not_whitelisted',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                    'account_info' => 'System-level API call (admin account)',
                    'account_name' => 'Admin Account',
                ]
            );

            $throttleCanonical = $apiSystem.'_ip_not_whitelisted';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'ip_not_whitelisted',
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

        // Rate Limit
        if ($handler->isRateLimitedFromLog($httpCode, $vendorCode)) {
            $throttleCanonical = $apiSystem.'_api_rate_limit_exceeded';

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

            $messageData = NotificationMessageBuilder::build(
                canonical: 'api_rate_limit_exceeded',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                    'account_info' => $accountInfo,
                ]
            );

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'api_rate_limit_exceeded',
                        deliveryGroup: 'default',
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

        // Forbidden / API Access Denied
        if ($handler->isForbiddenFromLog($httpCode, $vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'api_access_denied',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                    'account_info' => 'System-level API call (admin account)',
                ]
            );

            $throttleCanonical = $apiSystem.'_api_access_denied';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'api_access_denied',
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

        // Invalid credentials (generic 401)
        if ($httpCode === 401) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'api_access_denied',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                    'account_info' => 'System-level API call (admin account)',
                ]
            );

            $throttleCanonical = $apiSystem.'_api_access_denied';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'api_access_denied',
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

        // Service unavailable / Server Overload
        // CRITICAL: During price crashes, exchanges get overloaded and cannot process requests
        if ($handler->isServerOverloadFromLog($httpCode, $vendorCode)) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'exchange_maintenance',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                ]
            );

            $throttleCanonical = $apiSystem.'_exchange_maintenance';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'exchange_maintenance',
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

        // Connection failures
        if (! $httpCode && $this->error_message) {
            $messageData = NotificationMessageBuilder::build(
                canonical: 'api_connection_failed',
                context: [
                    'exchange' => $apiSystem,
                    'ip' => $serverIp,
                    'hostname' => $hostname,
                ]
            );

            $throttleCanonical = $apiSystem.'_api_connection_failed';

            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->execute(function () use ($messageData, $apiSystem, $serverIp) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: 'api_connection_failed',
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
        $notification = Notification::findByCanonical($messageCanonical);
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

        // Fetch API system model to get the proper name
        $exchangeCanonical = $handler->getApiSystem();
        $apiSystem = ApiSystem::where('canonical', $exchangeCanonical)->first();
        $exchange = $apiSystem ? $apiSystem->name : ucfirst($exchangeCanonical);

        // Build account info string
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
            'api_credentials_or_ip',
            'invalid_api_key',
            'invalid_signature',
            'insufficient_permissions',
        ];

        $isServerRelated = in_array($messageCanonical, $serverRelatedCanonicals, true);

        // Build context - only include server info for server-related notifications
        $context = [
            'exchange' => $exchangeCanonical,
            'account_name' => $exchange.' Account #'.$account->id,
            'account_info' => $accountInfo,
        ];

        if ($isServerRelated) {
            $context['ip'] = Martingalian::ip();
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
            $exchangeCanonical = $handler->getApiSystem();
            $apiSystem = ApiSystem::where('canonical', $exchangeCanonical)->first();
            $exchangeName = $apiSystem ? $apiSystem->name : ucfirst($exchangeCanonical);
            $serverIp = $isServerRelated ? Martingalian::ip() : null;
            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical)
                ->for($user)
                ->execute(function () use ($user, $messageData, $messageCanonical, $exchangeName, $serverIp) {
                    NotificationService::send(
                        user: $user,
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: $messageCanonical,
                        deliveryGroup: null,  // User notifications should NOT use delivery groups (those route to admin group keys)
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
        // Note: Admin notifications do NOT include exchange/serverIp parameters to keep email subjects clean
        // Send to admin independently of whether we sent to user (both can receive notifications)
        // Use separate throttle key to avoid throttling admin notification when user notification was just sent
        if ($shouldSendToAdmin) {
            Throttler::using(NotificationService::class)
                ->withCanonical($throttleCanonical.'_admin')
                ->execute(function () use ($messageData, $messageCanonical) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        canonical: $messageCanonical,
                        deliveryGroup: 'exceptions',
                        severity: $messageData['severity'],
                        pushoverMessage: $messageData['pushoverMessage'],
                        actionUrl: $messageData['actionUrl'],
                        actionLabel: $messageData['actionLabel']
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

    /**
     * Create IP whitelist repeater for automatic retry.
     * Only called for Bybit error 10010 (IP not whitelisted).
     */
    protected function createIpWhitelistRepeater(
        BaseExceptionHandler $handler,
        Account $account,
        string $hostname
    ): void {
        // Get or create Server record for this hostname
        $server = Server::where('hostname', $hostname)->first();

        if (! $server) {
            // Create server record if it doesn't exist
            $server = Server::create([
                'hostname' => $hostname,
                'ip_address' => Martingalian::ip(),
                'type' => 'worker',
            ]);
        }

        // Check if repeater already exists for this account+server combination
        $existingRepeater = Repeater::where('class', ServerIpNotWhitelistedRepeater::class)
            ->where('parameters->account_id', $account->id)
            ->where('parameters->server_id', $server->id)
            ->first();

        // Only create repeater if one doesn't already exist
        if (! $existingRepeater) {
            Repeater::create([
                'class' => ServerIpNotWhitelistedRepeater::class,
                'parameters' => [
                    'account_id' => $account->id,
                    'server_id' => $server->id,
                ],
                'retry_strategy' => 'proportional',
                'retry_interval_minutes' => 1,
                'max_attempts' => 60, // 1min intervals for up to 1 hour
            ]);
        }
    }
}
