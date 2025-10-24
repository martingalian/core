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
     * Get the current server's IP address.
     * Used for IP-based rate limiting and ban coordination.
     */
    protected function getCurrentIp(): string
    {
        return gethostbyname(gethostname());
    }
}
