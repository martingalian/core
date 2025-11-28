<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobExceptions;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobLifecycle;
use Martingalian\Core\Exceptions\NonNotifiableException;
use Martingalian\Core\Models\ForbiddenHostname;
use Martingalian\Core\Models\Step;
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
        if (! method_exists($this, 'assignExceptionHandler')) {
            throw new Exception('Exception handler not instanciated!');
        }

        $this->assignExceptionHandler();

        // Is this hostname forbidden for this API system and account?
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

        if ($isForbidden) {
            // Place back the job in the queue;
            $this->retryJob();

            return;
        }

        try {
            // Defense-in-depth: If we've reached this point, we're about to execute
            // the API call, so we're definitely not throttled anymore
            $updateData = ['is_throttled' => false];

            if ($this->step->started_at === null) {
                $updateData['started_at'] = now();
            }

            if ($this->step->hostname === null) {
                $updateData['hostname'] = gethostname();
            }

            if ($this->step->completed_at !== null) {
                $updateData['completed_at'] = null;
            }

            $this->step->update($updateData);

            $result = $this->computeApiable();

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
        $this->throttleLog("╔═══════════════════════════════════════════════════════════╗");
        $this->throttleLog("║   BaseApiableJob::shouldStartOrThrottle() START          ║");
        $this->throttleLog("╚═══════════════════════════════════════════════════════════╝");

        // Ensure exception handler is assigned before safety checks
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        // 0. First check exception handler's pre-flight safety check
        $this->throttleLog("[0] Exception handler pre-flight safety check...");
        if (isset($this->exceptionHandler) && ! $this->exceptionHandler->isSafeToMakeRequest()) {
            $this->jobBackoffSeconds = 5; // Default 5 second backoff when not safe
            $this->throttleLog("   ❌ Exception handler says NOT SAFE to make request");
            $this->throttleLog("   └─ DECISION: RESCHEDULE ({$this->jobBackoffSeconds}s backoff)");
            $this->throttleLog("╚═══════════════════════════════════════════════════════════╝");

            return false; // Not safe - wait and retry
        }
        $this->throttleLog("   ✓ Exception handler says safe to proceed");

        // Get throttler for this API system
        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            $this->throttleLog("[1] No throttler for API system");
            $this->throttleLog("   └─ DECISION: PROCEED (no throttler)");
            $this->throttleLog("╚═══════════════════════════════════════════════════════════╝");

            return true; // No throttler = proceed
        }

        $throttlerClass = class_basename($throttler);
        $this->throttleLog("[1] Throttler found: {$throttlerClass}");

        // Extract account ID for per-account rate limit tracking (e.g., Binance ORDER limits)
        $accountId = $this->exceptionHandler->account?->id;
        $this->throttleLog("   └─ Account ID: ".($accountId ?? 'NULL'));

        // 1. First check IP-based safety (bans, rate limit proximity) if throttler supports it
        if (method_exists($throttler, 'isSafeToDispatch')) {
            $this->throttleLog("[2] Calling {$throttlerClass}::isSafeToDispatch()...");
            $secondsToWait = $throttler::isSafeToDispatch($accountId, $this->step->id);

            if ($secondsToWait > 0) {
                $this->jobBackoffSeconds = $secondsToWait;
                $this->throttleLog("   ❌ isSafeToDispatch() returned: {$secondsToWait}s");
                $this->throttleLog("   └─ DECISION: RESCHEDULE ({$this->jobBackoffSeconds}s backoff)");
                $this->throttleLog("╚═══════════════════════════════════════════════════════════╝");

                return false; // Not safe - wait and retry
            }

            $this->throttleLog("   ✓ isSafeToDispatch() returned: 0s (safe to proceed)");
        } else {
            $this->throttleLog("[2] Throttler does not support isSafeToDispatch() - skipping");
        }

        // 2. Then check standard throttling (rate limits, min delays, etc.)
        $retryCount = $this->step->retries ?? 0;
        $this->throttleLog("[3] Calling {$throttlerClass}::canDispatch()...");
        $this->throttleLog("   ├─ Retry count: {$retryCount}");
        $this->throttleLog("   └─ Account ID: ".($accountId ?? 'NULL'));
        $secondsToWait = $throttler::canDispatch($retryCount, $accountId, $this->step->id);

        if ($secondsToWait > 0) {
            $this->jobBackoffSeconds = $secondsToWait;
            $this->throttleLog("   ❌ canDispatch() returned: {$secondsToWait}s");
            $this->throttleLog("   └─ DECISION: RESCHEDULE ({$this->jobBackoffSeconds}s backoff)");
            $this->throttleLog("╚═══════════════════════════════════════════════════════════╝");

            return false; // Throttled - retry
        }

        $this->throttleLog("   ✓ canDispatch() returned: 0s (proceed)");
        $this->throttleLog("└─ DECISION: PROCEED");
        $this->throttleLog("╚═══════════════════════════════════════════════════════════╝");

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
     * Log a throttle message for this step using the API-specific log type.
     */
    protected function throttleLog(string $message): void
    {
        $throttler = $this->getThrottlerForApiSystem();

        if ($throttler && method_exists($throttler, 'getThrottleLogType')) {
            $logType = $throttler::getThrottleLogType();
        } else {
            // Fallback to job log if no throttler
            $logType = 'job';
        }

        Step::log($this->step->id, $logType, $message);
    }

    /**
     * Override shouldExitEarly to inject API throttling checks
     * into the lifecycle before standard BaseQueueableJob checks.
     */
    protected function shouldExitEarly(): bool
    {
        // Run standard lifecycle checks first
        if (! $this->shouldStartOrStop()) {
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            $this->retryJob();

            return true;
        }

        // API-specific: Check throttling
        if (! $this->shouldStartOrThrottle()) {
            $this->rescheduleWithoutRetry();

            return true;
        }

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
                $stepId = $this->step->id;
                $throttler::recordDispatch($accountId, $stepId);
            }

            // Now call the parent implementation
            parent::executeJobLogic();
        }
    }

    /**
     * Override to provide API-specific diagnostic information for retry failures.
     * Checks if the current hostname is forbidden for this account/API system.
     */
    protected function getRetryDiagnostics(): array
    {
        $diagnostics = [];

        try {
            if (! isset($this->exceptionHandler)) {
                $this->assignExceptionHandler();
            }

            $hostname = \Martingalian\Core\Models\Martingalian::ip();
            $isForbidden = ForbiddenHostname::query()
                ->where('account_id', $this->exceptionHandler->account->id)
                ->where('ip_address', $hostname)
                ->exists();

            if ($isForbidden) {
                $diagnostics[] = "Hostname {$hostname} is FORBIDDEN for account {$this->exceptionHandler->account->id}";
            }
        } catch (Throwable $e) {
            // Silently skip diagnostics if anything fails
        }

        return $diagnostics;
    }
}
