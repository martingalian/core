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

    public ?Account $account = null;

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
     * Check if log represents a rate limit error based on HTTP code and vendor code.
     */
    final public function isRateLimitedFromLog(int $httpCode, ?int $vendorCode): bool
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
    final public function isForbiddenFromLog(int $httpCode, ?int $vendorCode): bool
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
     * Check if log represents a server overload/busy error based on HTTP code and vendor code.
     * These are treated the same as exchange maintenance - server cannot process requests.
     */
    final public function isServerOverloadFromLog(int $httpCode, ?int $vendorCode): bool
    {
        // HTTP 503/504 indicate service unavailable or gateway timeout
        if (in_array($httpCode, [503, 504], true)) {
            return true;
        }

        // Check vendor-specific server overload codes if available
        if ($vendorCode && property_exists($this, 'serverOverloadCodes')) {
            return in_array($vendorCode, $this->serverOverloadCodes, true);
        }

        return false;
    }

    /**
     * Check if log represents a critical account status error requiring account disabling.
     * These errors trigger can_trade = 0 on the account.
     */
    final public function isAccountStatusErrorFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'accountStatusCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->accountStatusCodes, true);
    }

    /**
     * Check if log represents insufficient balance/margin error.
     */
    final public function isInsufficientBalanceFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'insufficientBalanceCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->insufficientBalanceCodes, true);
    }

    /**
     * Check if log represents KYC verification required error.
     */
    final public function isKycRequiredFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'kycRequiredCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->kycRequiredCodes, true);
    }

    /**
     * Check if log represents system error (unknown error, timeout).
     */
    final public function isSystemErrorFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'systemErrorCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->systemErrorCodes, true);
    }

    /**
     * Check if log represents network error.
     */
    final public function isNetworkErrorFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'networkErrorCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->networkErrorCodes, true);
    }

    /**
     * Check if log represents ambiguous credentials/IP/permissions error.
     * Only used by Binance (-2015) which doesn't distinguish between these scenarios.
     */
    final public function isCredentialsOrIpErrorFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'credentialsOrIpCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->credentialsOrIpCodes, true);
    }

    /**
     * Check if log represents invalid API key error.
     * Used by Bybit (10003) for specific API key validation failures.
     */
    final public function isInvalidApiKeyFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'invalidApiKeyCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->invalidApiKeyCodes, true);
    }

    /**
     * Check if log represents invalid signature error.
     * Used by Bybit (10004) for signature generation/validation failures.
     */
    final public function isInvalidSignatureFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'invalidSignatureCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->invalidSignatureCodes, true);
    }

    /**
     * Check if log represents insufficient permissions error.
     * Used by Bybit (10005) for API key permission restrictions.
     */
    final public function isInsufficientPermissionsFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'insufficientPermissionsCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->insufficientPermissionsCodes, true);
    }

    /**
     * Check if log represents IP not whitelisted error.
     * Used by Bybit (10010) for IP whitelist configuration issues.
     */
    final public function isIpNotWhitelistedFromLog(?int $vendorCode): bool
    {
        if (! $vendorCode || ! property_exists($this, 'ipNotWhitelistedCodes')) {
            return false;
        }

        return in_array($vendorCode, $this->ipNotWhitelistedCodes, true);
    }

    /**
     * Get the current server's IP address.
     * Used for IP-based rate limiting and ban coordination.
     */
    protected function getCurrentIp(): string
    {
        return \Martingalian\Core\Models\Martingalian::ip();
    }
}
