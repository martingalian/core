<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use App\Support\NotificationService;
use App\Support\Throttler;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ApiExceptionHandlers\AlternativeMeExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\BybitExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\CoinmarketCapExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/*
 * BaseExceptionHandler
 *
 * • Abstract base for handling API-specific exceptions in a unified way.
 * • Provides default no-op implementations that can be overridden.
 * • Defines factory method `make()` to instantiate handler per API system.
 * • Enables retries, ignores, or custom resolution logic per provider.
 * • Used in APIable jobs to decide error handling and retry logic.
 */
abstract class BaseExceptionHandler
{
    public int $backoffSeconds = 10;

    public ?Account $account;

    // Just to confirm it's being used by a child class. Should return true.
    abstract public function ping(): bool;

    // Returns the API system canonical name (e.g., 'taapi', 'coinmarketcap', 'binance')
    abstract public function getApiSystem(): string;

    // Check if exception is a recv window mismatch. Provided via ApiExceptionHelpers trait.
    abstract public function isRecvWindowMismatch(Throwable $exception): bool;

    // Check if exception is rate limited. Provided via ApiExceptionHelpers trait.
    abstract public function isRateLimited(Throwable $exception): bool;

    // Check if exception is forbidden (auth/permission). Provided via ApiExceptionHelpers trait.
    abstract public function isForbidden(Throwable $exception): bool;

    // Calculate when to retry after rate limit. Provided via ApiExceptionHelpers trait or overridden by child classes.
    abstract public function rateLimitUntil(RequestException $exception): Carbon;

    /**
     * Record response headers for IP-based rate limiting coordination.
     * Called after every successful API response.
     * Complex APIs (Binance, Bybit) use this to track rate limits in Redis.
     * Simple APIs (TAAPI, CoinMarketCap) implement as no-op.
     */
    abstract public function recordResponseHeaders(ResponseInterface $response): void;

    /**
     * Check if the current server IP is currently banned by the API.
     * Returns true if IP ban is active, false otherwise.
     * Used by shouldStartOrThrottle() to prevent jobs from running during bans.
     */
    abstract public function isCurrentlyBanned(): bool;

    /**
     * Record an IP ban in shared state (Redis) when 418/429 errors occur.
     * Allows all workers on the same IP to coordinate and stop making requests.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban expires
     */
    abstract public function recordIpBan(int $retryAfterSeconds): void;

    /**
     * Pre-flight check before making an API request.
     * Returns false if:
     * - IP is currently banned
     * - Too soon since last request (min delay)
     * - Approaching rate limits (>80%)
     * Returns true if safe to proceed.
     */
    abstract public function isSafeToMakeRequest(): bool;

    final public static function make(string $apiCanonical)
    {
        return match ($apiCanonical) {
            'binance' => new BinanceExceptionHandler,
            'bybit' => new BybitExceptionHandler,
            'taapi' => new TaapiExceptionHandler,
            'alternativeme' => new AlternativeMeExceptionHandler,
            'coinmarketcap' => new CoinmarketCapExceptionHandler,
            default => throw new Exception("Unsupported Exception API Handler: {$apiCanonical}")
        };
    }

    // Eager loads an account for later use.
    final public function withAccount(Account $account)
    {
        $this->account = $account;

        return $this;
    }

    /**
     * Send throttled notifications to the account user for common API exceptions.
     * If no specific user is associated with the account (virtual/system accounts),
     * notifications are sent to all active admins instead.
     * Override in child classes to add API-specific notifications.
     */
    final public function notifyException(Throwable $exception): void
    {
        if (! $this->account) {
            return;
        }

        $apiSystem = $this->getApiSystem();
        $hostname = gethostname();
        $hasSpecificUser = $this->account->user !== null;

        // 429 - Rate Limit Exceeded
        if ($this->isRateLimited($exception)) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_rate_limit_exceeded',
                message: "API rate limit exceeded on {$apiSystem}. Worker: {$hostname}",
                title: 'Rate Limit Exceeded'
            );

            return;
        }

        // 403 - Forbidden / IP Not Whitelisted
        if ($this->isForbidden($exception)) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'ip_not_whitelisted',
                message: "Worker IP {$hostname} is not whitelisted on {$apiSystem}",
                title: 'IP Not Whitelisted'
            );

            return;
        }

        // Connection failures
        if ($exception instanceof RequestException && ! $exception->hasResponse()) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_connection_failed',
                message: "Unable to connect to {$apiSystem} from {$hostname}",
                title: 'API Connection Failed'
            );

            return;
        }

        // 401 - Invalid credentials
        if ($exception instanceof RequestException && $exception->getResponse()?->getStatusCode() === 401) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'invalid_api_credentials',
                message: "Invalid API credentials for {$apiSystem} on account {$this->account->name}",
                title: 'Invalid API Credentials'
            );

            return;
        }

        // 503 - Service unavailable / Maintenance
        if ($exception instanceof RequestException && $exception->getResponse()?->getStatusCode() === 503) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'exchange_maintenance',
                message: "{$apiSystem} is currently under maintenance or unavailable",
                title: 'Exchange Maintenance'
            );

            return;
        }
    }

    /**
     * Send notifications based on API request log data.
     * This method analyzes the log and sends appropriate throttled notifications.
     * Used by ApiRequestLogObserver to notify on API errors.
     */
    final public function notifyFromApiLog(\Martingalian\Core\Models\ApiRequestLog $log): void
    {
        if (! $this->account) {
            return;
        }

        $apiSystem = $this->getApiSystem();
        $hostname = $log->hostname ?? gethostname();
        $hasSpecificUser = $this->account->user !== null;
        $httpCode = $log->http_response_code;

        // Extract vendor-specific error code from response
        $vendorCode = $this->extractVendorCodeFromLog($log);

        // 429 / 418 / 403 - Rate Limit
        if ($this->isRateLimitedFromLog($httpCode, $vendorCode)) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_rate_limit_exceeded',
                message: "API rate limit exceeded on {$apiSystem}. Worker: {$hostname}",
                title: 'Rate Limit Exceeded'
            );

            return;
        }

        // 403 / 401 with IP whitelist codes - Forbidden / IP Not Whitelisted
        if ($this->isForbiddenFromLog($httpCode, $vendorCode)) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'ip_not_whitelisted',
                message: "Worker IP {$hostname} is not whitelisted on {$apiSystem}",
                title: 'IP Not Whitelisted'
            );

            return;
        }

        // 401 - Invalid credentials (without specific IP codes)
        if ($httpCode === 401) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'invalid_api_credentials',
                message: "Invalid API credentials for {$apiSystem}",
                title: 'Invalid API Credentials'
            );

            return;
        }

        // 503 - Service unavailable / Maintenance
        if ($httpCode === 503) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'exchange_maintenance',
                message: "{$apiSystem} is currently under maintenance or unavailable",
                title: 'Exchange Maintenance'
            );

            return;
        }

        // Connection failures (no http_response_code but has error_message)
        if (! $httpCode && $log->error_message) {
            $this->sendThrottledNotification(
                hasSpecificUser: $hasSpecificUser,
                messageCanonical: 'api_connection_failed',
                message: "Unable to connect to {$apiSystem} from {$hostname}",
                title: 'API Connection Failed'
            );

            return;
        }
    }

    /**
     * Send admin-only notifications based on API request log data.
     * Used when there's no account associated (system-level API calls).
     */
    final public function notifyFromApiLogToAdmin(\Martingalian\Core\Models\ApiRequestLog $log): void
    {
        $apiSystem = $this->getApiSystem();
        $hostname = $log->hostname ?? gethostname();
        $httpCode = $log->http_response_code;
        $vendorCode = $this->extractVendorCodeFromLog($log);
        $prefixedCanonical = $this->getApiSystem().'_';

        // Rate Limit
        if ($this->isRateLimitedFromLog($httpCode, $vendorCode)) {
            $prefixedCanonical .= 'api_rate_limit_exceeded';
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $hostname) {
                    NotificationService::sendToAdmin(
                        message: "System API rate limit exceeded on {$apiSystem}. Worker: {$hostname}",
                        title: 'System Rate Limit',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        // Forbidden / IP Not Whitelisted
        if ($this->isForbiddenFromLog($httpCode, $vendorCode)) {
            $prefixedCanonical .= 'ip_not_whitelisted';
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $hostname) {
                    NotificationService::sendToAdmin(
                        message: "System worker IP {$hostname} is not whitelisted on {$apiSystem}",
                        title: 'System IP Not Whitelisted',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        // Invalid credentials
        if ($httpCode === 401) {
            $prefixedCanonical .= 'invalid_api_credentials';
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem) {
                    NotificationService::sendToAdmin(
                        message: "Invalid system API credentials for {$apiSystem}",
                        title: 'System API Credentials Invalid',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        // Service unavailable
        if ($httpCode === 503) {
            $prefixedCanonical .= 'exchange_maintenance';
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem) {
                    NotificationService::sendToAdmin(
                        message: "{$apiSystem} is currently under maintenance or unavailable",
                        title: 'System API Maintenance',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        // Connection failures
        if (! $httpCode && $log->error_message) {
            $prefixedCanonical .= 'api_connection_failed';
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($apiSystem, $hostname) {
                    NotificationService::sendToAdmin(
                        message: "Unable to connect to {$apiSystem} from {$hostname}",
                        title: 'System API Connection Failed',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }
    }

    /**
     * Get the current server's IP address.
     * Used for IP-based rate limiting and ban coordination.
     */
    protected function getCurrentIp(): string
    {
        return gethostbyname(gethostname());
    }

    /**
     * Extract vendor-specific error code from API request log response.
     */
    protected function extractVendorCodeFromLog(\Martingalian\Core\Models\ApiRequestLog $log): ?int
    {
        $response = $log->response;

        if (! is_array($response)) {
            return null;
        }

        // Binance uses 'code', Bybit uses 'retCode'
        return $response['code'] ?? $response['retCode'] ?? null;
    }

    /**
     * Check if log represents a rate limit error based on HTTP code and vendor code.
     */
    protected function isRateLimitedFromLog(int $httpCode, ?int $vendorCode): bool
    {
        // HTTP 429, 418, 403 are rate limits
        if (in_array($httpCode, [429, 418, 403], true)) {
            return true;
        }

        // Check vendor-specific rate limit codes
        if ($vendorCode && property_exists($this, 'rateLimitedHttpCodes')) {
            return in_array($vendorCode, $this->rateLimitedHttpCodes, true);
        }

        return false;
    }

    /**
     * Check if log represents a forbidden/IP whitelist error based on HTTP code and vendor code.
     */
    protected function isForbiddenFromLog(int $httpCode, ?int $vendorCode): bool
    {
        if (! in_array($httpCode, [401, 403], true)) {
            return false;
        }

        if (! $vendorCode || ! property_exists($this, 'forbiddenHttpCodes')) {
            return false;
        }

        // Check nested array structure (e.g., [401 => [-2015]])
        if (is_array($this->forbiddenHttpCodes)) {
            foreach ($this->forbiddenHttpCodes as $code => $subCodes) {
                if ($code === $httpCode && is_array($subCodes) && in_array($vendorCode, $subCodes, true)) {
                    return true;
                }
            }

            // Also check flat array (e.g., [10003, 10004])
            return in_array($vendorCode, $this->forbiddenHttpCodes, true);
        }

        return false;
    }

    /**
     * Send notification to specific user if available, otherwise to admin from config.
     * This handles both user-specific accounts and virtual/system accounts.
     * Canonicals are automatically prefixed with API system to prevent cross-API throttling.
     */
    private function sendThrottledNotification(
        bool $hasSpecificUser,
        string $messageCanonical,
        string $message,
        string $title
    ): void {
        // Prefix canonical with API system to segregate throttle windows per API
        // Example: 'ip_not_whitelisted' becomes 'binance_ip_not_whitelisted'
        $prefixedCanonical = $this->getApiSystem().'_'.$messageCanonical;

        // Build user-friendly message with context
        $messageData = \App\Support\NotificationMessageBuilder::build(
            canonical: $prefixedCanonical,
            context: [
                'exchange' => $this->getApiSystem(),
                'ip' => $this->getCurrentIp(),
                'hostname' => gethostname(),
                'account_name' => $this->account?->name ?? 'your account',
            ],
            user: $hasSpecificUser ? $this->account->user : null
        );

        if ($hasSpecificUser) {
            // Notify the specific user associated with this account
            $user = $this->account->user;
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->for($user)
                ->execute(function () use ($user, $messageData) {
                    NotificationService::sendToUser(
                        user: $user,
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        deliveryGroup: 'exceptions',
                        severity: $messageData['severity'],
                        pushoverMessage: $messageData['pushoverMessage'],
                        actionUrl: $messageData['actionUrl'],
                        actionLabel: $messageData['actionLabel']
                    );
                });
        } else {
            // No specific user (virtual/system account) - notify admin from config
            Throttler::using(NotificationService::class)
                ->withCanonical($prefixedCanonical)
                ->execute(function () use ($messageData) {
                    NotificationService::sendToAdmin(
                        message: $messageData['emailMessage'],
                        title: $messageData['title'],
                        deliveryGroup: 'exceptions',
                        severity: $messageData['severity'],
                        pushoverMessage: $messageData['pushoverMessage'],
                        actionUrl: $messageData['actionUrl'],
                        actionLabel: $messageData['actionLabel']
                    );
                });
        }
    }
}
