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

    /**
     * Health check to confirm handler is operational.
     * Default implementation returns true.
     * Override only if custom health checks are needed.
     */
    public function ping(): bool
    {
        return true;
    }

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
     * Default implementation is no-op (simple APIs don't need header tracking).
     * Complex APIs (Binance, Bybit) override to track rate limits in Redis.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        // No-op by default - only complex APIs need this
    }

    /**
     * Check if the current server IP is currently banned by the API.
     * Default implementation returns false (simple APIs don't track IP bans).
     * Complex APIs (Binance, Bybit) override to check ban state in Redis.
     */
    public function isCurrentlyBanned(): bool
    {
        return false;
    }

    /**
     * Record an IP ban in shared state (Redis) when 418/429 errors occur.
     * Default implementation is no-op (simple APIs don't track IP bans).
     * Complex APIs (Binance, Bybit) override to store ban state in Redis.
     *
     * @param  int  $retryAfterSeconds  Seconds until ban expires
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        // No-op by default - only complex APIs need this
    }

    /**
     * Pre-flight check before making an API request.
     * Default implementation returns true (simple APIs always allow requests).
     * Complex APIs (Binance, Bybit) override to check:
     * - IP ban status
     * - Minimum delay since last request
     * - Rate limit proximity (>80%)
     */
    public function isSafeToMakeRequest(): bool
    {
        return true;
    }

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
     * Check if log represents a server forbidden error based on HTTP code and vendor code.
     */
    final public function isServerForbiddenFromLog(int $httpCode, ?int $vendorCode): bool
    {
        if (! property_exists($this, 'serverForbiddenHttpCodes')) {
            return false;
        }

        // Check if httpCode exists as flat element (e.g., [418, 429])
        if (in_array($httpCode, $this->serverForbiddenHttpCodes, true)) {
            return true;
        }

        // If no vendor code, we can't check nested structure
        if (! $vendorCode) {
            return false;
        }

        // Check nested array structure (e.g., [403 => [-1004, -1005]])
        foreach ($this->serverForbiddenHttpCodes as $code => $subCodes) {
            if ($code === $httpCode && is_array($subCodes) && in_array($vendorCode, $subCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if log represents a server rate limit error based on HTTP code and vendor code.
     */
    final public function isServerRateLimitedFromLog(int $httpCode, ?int $vendorCode): bool
    {
        if (! property_exists($this, 'serverRateLimitedHttpCodes')) {
            return false;
        }

        // Check if httpCode exists as flat element (e.g., [429])
        if (in_array($httpCode, $this->serverRateLimitedHttpCodes, true)) {
            return true;
        }

        // If no vendor code, we can't check nested structure
        if (! $vendorCode) {
            return false;
        }

        // Check nested array structure (e.g., [400 => [-1003]])
        foreach ($this->serverRateLimitedHttpCodes as $code => $subCodes) {
            if ($code === $httpCode && is_array($subCodes) && in_array($vendorCode, $subCodes, true)) {
                return true;
            }
        }

        return false;
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
