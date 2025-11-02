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

        // Is this hostname forbidden for this API system and account?
        $forbiddenStart = microtime(true);
        $apiSystemCanonical = $this->exceptionHandler->getApiSystem();
        $apiSystem = \Martingalian\Core\Models\ApiSystem::where('canonical', $apiSystemCanonical)->firstOrFail();
        $accountId = $this->exceptionHandler->account->id;
        $ipAddress = gethostbyname(gethostname());

        // Check forbidden status based on account type:
        // - Admin accounts (transient, id = NULL): Check system-wide ban only
        // - User accounts (real, id != NULL): Check both account-specific AND system-wide bans
        if ($accountId === null) {
            // Admin account - check system-wide ban only
            $isForbidden = ForbiddenHostname::query()
                ->where('api_system_id', $apiSystem->id)
                ->where('ip_address', $ipAddress)
                ->whereNull('account_id')
                ->exists();
        } else {
            // User account - check both account-specific AND system-wide bans
            $isForbidden = ForbiddenHostname::query()
                ->where('api_system_id', $apiSystem->id)
                ->where('ip_address', $ipAddress)
                ->where(function ($query) use ($accountId) {
                    $query->where('account_id', $accountId)
                        ->orWhereNull('account_id');
                })
                ->exists();
        }

        $forbiddenTime = round((microtime(true) - $forbiddenStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE] Step #{$stepId} | {$jobClass} | Forbidden check: {$forbiddenTime}ms | Result: ".($isForbidden ? 'YES' : 'NO'));

        if ($isForbidden) {
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
        // Ensure exception handler is assigned before safety checks
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        // 0. First check exception handler's pre-flight safety check
        if (isset($this->exceptionHandler) && ! $this->exceptionHandler->isSafeToMakeRequest()) {
            $this->jobBackoffSeconds = 5; // Default 5 second backoff when not safe

            return false; // Not safe - wait and retry
        }

        // Get throttler for this API system
        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            return true; // No throttler = proceed
        }

        // 1. First check IP-based safety (bans, rate limit proximity) if throttler supports it
        if (method_exists($throttler, 'isSafeToDispatch')) {
            $secondsToWait = $throttler::isSafeToDispatch();
            if ($secondsToWait > 0) {
                $this->jobBackoffSeconds = $secondsToWait;

                return false; // Not safe - wait and retry
            }
        }

        // 2. Then check standard throttling (rate limits, min delays, etc.)
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
