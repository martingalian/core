<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Log;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobExceptions;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobLifecycle;
use Martingalian\Core\Exceptions\NonNotifiableException;
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
        $ipAddress = \Martingalian\Core\Models\Martingalian::ip();

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
        Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | Starting throttle check");

        // Ensure exception handler is assigned before safety checks
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        // 0. First check exception handler's pre-flight safety check
        if (isset($this->exceptionHandler) && ! $this->exceptionHandler->isSafeToMakeRequest()) {
            $this->jobBackoffSeconds = 5; // Default 5 second backoff when not safe
            Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | Not safe to make request, backoff: 5s");

            return false; // Not safe - wait and retry
        }

        // Get throttler for this API system
        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | No throttler found, OK to proceed");
            return true; // No throttler = proceed
        }

        Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | Using throttler: ".class_basename($throttler));

        // Extract account ID for per-account rate limit tracking (e.g., Binance ORDER limits)
        $accountId = $this->exceptionHandler->account?->id;

        // 1. First check IP-based safety (bans, rate limit proximity) if throttler supports it
        if (method_exists($throttler, 'isSafeToDispatch')) {
            $secondsToWait = $throttler::isSafeToDispatch($accountId);
            if ($secondsToWait > 0) {
                $this->jobBackoffSeconds = $secondsToWait;
                Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | IP-based safety check failed, backoff: {$secondsToWait}s");

                return false; // Not safe - wait and retry
            }
        }

        // 2. Then check standard throttling (rate limits, min delays, etc.)
        $retryCount = $this->step->retries ?? 0;
        Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | Calling canDispatch(retryCount={$retryCount}, accountId={$accountId})");
        $secondsToWait = $throttler::canDispatch($retryCount, $accountId);

        if ($secondsToWait > 0) {
            $this->jobBackoffSeconds = $secondsToWait;
            Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | Throttled! Wait: {$secondsToWait}s | Will set backoff to {$this->jobBackoffSeconds}s");

            return false; // Throttled - retry
        }

        Log::info("[API-THROTTLE-CHECK] Step #{$this->step->id} | Throttle check passed, OK to proceed");
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
     * Override shouldExitEarly to inject API throttling checks
     * into the lifecycle before standard BaseQueueableJob checks.
     */
    protected function shouldExitEarly(): bool
    {
        Log::info("[API-LIFECYCLE] Step #{$this->step->id} | ".class_basename($this)." | State: {$this->step->state} | Retries: {$this->step->retries} | Checking shouldExitEarly");

        // Run standard lifecycle checks first
        if (! $this->shouldStartOrStop()) {
            Log::info("[API-LIFECYCLE] Step #{$this->step->id} | shouldStartOrStop = false, calling stopJob()");
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            Log::info("[API-LIFECYCLE] Step #{$this->step->id} | shouldStartOrFail = false, throwing exception");
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            Log::info("[API-LIFECYCLE] Step #{$this->step->id} | shouldStartOrSkip = false, calling skipJob()");
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            Log::info("[API-LIFECYCLE] Step #{$this->step->id} | shouldStartOrRetry = false, calling retryJob()");
            $this->retryJob();

            return true;
        }

        // API-specific: Check throttling
        Log::info("[API-LIFECYCLE] Step #{$this->step->id} | Checking shouldStartOrThrottle()...");
        if (! $this->shouldStartOrThrottle()) {
            Log::info("[API-LIFECYCLE] Step #{$this->step->id} | THROTTLED! Backoff: {$this->jobBackoffSeconds}s | Current retries: {$this->step->retries} | Calling rescheduleWithoutRetry()");
            $this->rescheduleWithoutRetry();
            Log::info("[API-LIFECYCLE] Step #{$this->step->id} | After rescheduleWithoutRetry() | Retries: {$this->step->fresh()->retries} | New state: {$this->step->fresh()->state}");

            return true;
        }

        Log::info("[API-LIFECYCLE] Step #{$this->step->id} | NOT throttled, OK to proceed");

        // Check max retries AFTER throttle check to avoid failing jobs that are just waiting for rate limit
        $this->checkMaxRetries();

        return false;
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
                // Extract account ID for per-account rate limit tracking
                $accountId = $this->exceptionHandler->account?->id;
                $throttler::recordDispatch($accountId);
            }

            // Now call the parent implementation
            parent::executeJobLogic();
        }
    }
}
