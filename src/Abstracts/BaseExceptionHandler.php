<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ApiExceptionHandlers\AlternativeMeExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\BybitExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\CoinmarketCapExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler;
use Martingalian\Core\Support\NotificationThrottler;
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
     * Get the current server's IP address.
     * Used for IP-based rate limiting and ban coordination.
     */
    protected function getCurrentIp(): string
    {
        return gethostbyname(gethostname());
    }

    /**
     * Send notification to specific user if available, otherwise to admin from config.
     * This handles both user-specific accounts and virtual/system accounts.
     */
    private function sendThrottledNotification(
        bool $hasSpecificUser,
        string $messageCanonical,
        string $message,
        string $title
    ): void {
        if ($hasSpecificUser) {
            // Notify the specific user associated with this account
            NotificationThrottler::sendToUser(
                user: $this->account->user,
                messageCanonical: $messageCanonical,
                message: $message,
                title: $title,
                deliveryGroup: 'exceptions'
            );
        } else {
            // No specific user (virtual/system account) - notify admin from config
            NotificationThrottler::sendToAdmin(
                messageCanonical: $messageCanonical,
                message: $message,
                title: $title,
                deliveryGroup: 'exceptions'
            );
        }
    }
}
