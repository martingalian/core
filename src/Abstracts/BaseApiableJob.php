<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Log;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobExceptions;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobLifecycle;
use Martingalian\Core\Models\ForbiddenHostname;
use Martingalian\Core\Support\Proxies\ApiThrottlerProxy;
use Throwable;

abstract class BaseApiableJob extends BaseQueueableJob
{
    use HandlesApiJobExceptions;
    use HandlesApiJobLifecycle;

    public ?BaseExceptionHandler $exceptionHandler;

    abstract public function computeApiable();

    protected function compute()
    {
        $stepId = $this->step->id ?? 'unknown';
        $jobClass = class_basename($this);

        if (! method_exists($this, 'assignExceptionHandler')) {
            throw new Exception('Exception handler not instanciated!');
        }

        $handlerStart = microtime(true);
        $this->assignExceptionHandler();
        $handlerTime = round((microtime(true) - $handlerStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE] Step #{$stepId} | {$jobClass} | assignExceptionHandler: {$handlerTime}ms");

        // Is this hostname forbidden on this account?
        $forbiddenStart = microtime(true);
        $isForbidden = ForbiddenHostname::query()
            ->where('account_id', $this->exceptionHandler->account->id)
            ->where('ip_address', gethostbyname(gethostname()))
            ->exists();
        $forbiddenTime = round((microtime(true) - $forbiddenStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE] Step #{$stepId} | {$jobClass} | Forbidden check: {$forbiddenTime}ms | Result: ".($isForbidden ? 'YES' : 'NO'));

        if ($isForbidden) {
            $this->step->logApplicationEvent(
                'This hostname is FORBIDDEN on this exchange. Retrying again so the job can be picked up by another worker server',
                self::class,
                __FUNCTION__
            );

            // Place back the job in the queue;
            $this->retryJob();

            return;
        }

        try {
            $apiableStart = microtime(true);
            Log::channel('jobs')->info("[COMPUTE] Step #{$stepId} | {$jobClass} | Starting computeApiable()...");
            $result = $this->computeApiable();
            $apiableTime = round((microtime(true) - $apiableStart) * 1000, 2);
            Log::channel('jobs')->info("[COMPUTE] Step #{$stepId} | {$jobClass} | computeApiable() completed: {$apiableTime}ms");

            return $result;
        } catch (Throwable $e) {
            // Let the API-specific exception handler deal with the error.
            $this->handleApiException($e);
        }
    }

    /**
     * Automatic API throttling.
     * Checks if the API system has a throttler and enforces rate limits.
     * Also performs pre-flight safety checks (IP bans, rate limit proximity).
     */
    protected function shouldStartOrThrottle(): bool
    {
        // First, check IP-based safety (bans, rate limit proximity)
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        if (! $this->exceptionHandler->isSafeToMakeRequest()) {
            // Not safe to proceed - wait and retry
            $this->jobBackoffSeconds = 5; // Conservative backoff

            return false;
        }

        // Then check standard throttler
        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            return true; // No throttler = proceed
        }

        $retryCount = $this->step->retries ?? 0;
        $secondsToWait = $throttler::canDispatch($retryCount);

        if ($secondsToWait > 0) {
            $this->jobBackoffSeconds = $secondsToWait;

            return false; // Throttled - retry
        }

        return true; // OK to proceed
    }

    /**
     * Get the throttler class for the current API system.
     * Returns null if no throttler exists (graceful degradation).
     */
    protected function getThrottlerForApiSystem(): ?string
    {
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        $apiSystem = $this->exceptionHandler->getApiSystem();

        return ApiThrottlerProxy::getThrottler($apiSystem);
    }

    /**
     * Override to automatically record API dispatch before executing.
     */
    protected function executeJobLogic(): void
    {
        if ($this->step->double_check === 0) {
            // Automatically record API dispatch BEFORE calling computeApiable()
            $throttler = $this->getThrottlerForApiSystem();

            if ($throttler) {
                $throttler::recordDispatch();
            }

            // Now call the parent implementation
            parent::executeJobLogic();
        }
    }
}
