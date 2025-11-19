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
        // Notifications are sent by ApiRequestLogObserver after log is persisted

        // Handle connection failures like timeouts or DNS issues.
        if ($e instanceof ConnectException) {
            $this->retryDueToNetworkGlitch();

            return;
        }

        if ($e instanceof RequestException) {
            if ($this->exceptionHandler->ignoreException($e)) {
                // Ignorable exceptions (like 400 Bad Request for invalid symbols)
                // Job completes normally, allowing computeApiable() to return its result
                return;
            }

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
                $this->retryJob(); // Put job back in queue for another worker to pick up

                return;
            }
        }

        // Re-throw if it's not handled by any of the above.
        throw $e;
    }

    protected function handleRecvWindowIssue($e): void
    {
        // Lets improve the recvwindow safety for a higher duration.
        try {
            Artisan::call('martingalian:update-recvwindow-safety-duration');
        } catch (Throwable $commandException) {
            // Command might fail in test environment or when API is unavailable
            // Log but don't fail the job - we'll still retry with existing recvwindow_margin
            info('Failed to update recvwindow safety duration: '.$commandException->getMessage());
        }

        $this->retryPerApiThrottlingDelay($e);
    }

    protected function retryPerApiThrottlingDelay(Throwable $e): void
    {
        /*
         * Set a future dispatch_after time and mark the step as pending.
         * This defers job execution based on rateLimiter's exchange policy.
         */
        $retryAt = $this->exceptionHandler->rateLimitUntil($e);

        // Record IP ban in throttler for coordination across workers when applicable
        if ($e instanceof RequestException && $e->hasResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();

            // Check if this is an IP ban scenario (418/429 for Binance, 403 for Bybit)
            if (in_array($statusCode, [418, 429, 403], true)) {
                $retryAfterSeconds = (int) max(0, now()->diffInSeconds($retryAt, false));

                if ($retryAfterSeconds > 0) {
                    // Record ban in the appropriate throttler
                    $throttler = $this->getThrottlerForApiSystem();
                    if ($throttler && method_exists($throttler, 'recordIpBan')) {
                        $throttler::recordIpBan($retryAfterSeconds);
                    }
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
