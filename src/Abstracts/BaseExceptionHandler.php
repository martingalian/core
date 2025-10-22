<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ApiExceptionHandlers\AlternativeMeExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\BybitExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\CoinmarketCapExceptionHandler;
use Martingalian\Core\Support\ApiExceptionHandlers\TaapiExceptionHandler;
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

    public static function make(string $apiCanonical)
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

    // Exception can be retried without causing major issues.
    public function retryException(Throwable $exception): bool
    {
        return false;
    }

    // Exception should be ignored, no further actions taken.
    public function ignoreException(Throwable $exception): bool
    {
        return false;
    }

    // Exception is valid and needs to be resolved to avoid major issues.
    public function resolveException(Throwable $e)
    {
        return null;
    }

    // Eager loads an account for later use.
    public function withAccount(Account $account)
    {
        $this->account = $account;

        return $this;
    }
}
