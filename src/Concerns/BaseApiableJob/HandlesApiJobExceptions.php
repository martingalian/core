<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\BaseApiableJob;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;
use Martingalian\Core\States\Pending;
use Throwable;

/*
 * HandlesApiJobExceptions
 *
 * • Trait for BaseApiableJob classes to handle API-specific exceptions.
 * • Detects throttling, connectivity, and known RecvWindow issues.
 * • Automatically retries failed jobs by deferring dispatch with backoff.
 * • Triggers custom command to adjust recvWindow timing when applicable.
 * • Sets step state to Pending and flags it as updated after deferral.
 */
trait HandlesApiJobExceptions
{
    protected function handleApiException(Throwable $e): void
    {
        // Handle connection failures like timeouts or DNS issues.
        if ($e instanceof ConnectException) {
            $this->retryDueToNetworkGlitch();

            return;
        }

        if ($e instanceof RequestException) {
            if ($this->exceptionHandler->isRecvWindowMismatch($e)) {
                $this->handleRecvWindowIssue($e);

                return;
            }

            if ($this->exceptionHandler->isRateLimited($e)) {
                $this->retryPerApiThrottlingDelay($e);

                return;
            }

            if ($this->exceptionHandler->isForbidden($e)) {
                $this->exceptionHandler->forbid();

                return;
            }
        }

        // Re-throw if it's not handled by any of the above.
        throw $e;
    }

    protected function handleRecvWindowIssue($e): void
    {
        // Lets improve the recvwindow safety for a higher duration.
        Artisan::call('martingalian:update-recvwindow-safety-duration');

        $this->retryPerApiThrottlingDelay($e);
    }

    protected function retryPerApiThrottlingDelay(Throwable $e): void
    {
        /*
         * Set a future dispatch_after time and mark the step as pending.
         * This defers job execution based on rateLimiter's exchange policy.
         */
        $retryAt = $this->exceptionHandler->rateLimitUntil($e);

        // Record IP ban for coordination across workers when applicable
        if ($e instanceof RequestException && $e->hasResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();

            // Check if this is an IP ban scenario (429 or 418 for Binance)
            if (in_array($statusCode, [418, 429], true)) {
                $retryAfterSeconds = max(0, now()->diffInSeconds($retryAt, false));

                if ($retryAfterSeconds > 0) {
                    $this->exceptionHandler->recordIpBan($retryAfterSeconds);
                }
            }
        }

        $this->retryJob($retryAt);
    }

    protected function retryDueToNetworkGlitch(): void
    {
        // Just apply a standard rate limiter retry.
        $this->retryJob(now()->addSeconds($this->exceptionHandler->backoffSeconds));
    }
}
