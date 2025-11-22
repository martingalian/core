<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
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
        $stepId = $this->step->id;

        throttle_log($stepId, "╔═══════════════════════════════════════════════════════════╗");
        throttle_log($stepId, "║   BaseApiableJob::shouldStartOrThrottle() START          ║");
        throttle_log($stepId, "╚═══════════════════════════════════════════════════════════╝");

        // Ensure exception handler is assigned before safety checks
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        // 0. First check exception handler's pre-flight safety check
        throttle_log($stepId, "[0] Exception handler pre-flight safety check...");
        if (isset($this->exceptionHandler) && ! $this->exceptionHandler->isSafeToMakeRequest()) {
            $this->jobBackoffSeconds = 5; // Default 5 second backoff when not safe
            throttle_log($stepId, "   ❌ Exception handler says NOT SAFE to make request");
            throttle_log($stepId, "   └─ DECISION: RESCHEDULE ({$this->jobBackoffSeconds}s backoff)");
            throttle_log($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return false; // Not safe - wait and retry
        }
        throttle_log($stepId, "   ✓ Exception handler says safe to proceed");

        // Get throttler for this API system
        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            throttle_log($stepId, "[1] No throttler for API system");
            throttle_log($stepId, "   └─ DECISION: PROCEED (no throttler)");
            throttle_log($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return true; // No throttler = proceed
        }

        $throttlerClass = class_basename($throttler);
        throttle_log($stepId, "[1] Throttler found: {$throttlerClass}");

        // Extract account ID for per-account rate limit tracking (e.g., Binance ORDER limits)
        $accountId = $this->exceptionHandler->account?->id;
        throttle_log($stepId, "   └─ Account ID: ".($accountId ?? 'NULL'));

        // 1. First check IP-based safety (bans, rate limit proximity) if throttler supports it
        if (method_exists($throttler, 'isSafeToDispatch')) {
            throttle_log($stepId, "[2] Calling {$throttlerClass}::isSafeToDispatch()...");
            $secondsToWait = $throttler::isSafeToDispatch($accountId, $stepId);

            if ($secondsToWait > 0) {
                $this->jobBackoffSeconds = $secondsToWait;
                throttle_log($stepId, "   ❌ isSafeToDispatch() returned: {$secondsToWait}s");
                throttle_log($stepId, "   └─ DECISION: RESCHEDULE ({$this->jobBackoffSeconds}s backoff)");
                throttle_log($stepId, "╚═══════════════════════════════════════════════════════════╝");

                return false; // Not safe - wait and retry
            }

            throttle_log($stepId, "   ✓ isSafeToDispatch() returned: 0s (safe to proceed)");
        } else {
            throttle_log($stepId, "[2] Throttler does not support isSafeToDispatch() - skipping");
        }

        // 2. Then check standard throttling (rate limits, min delays, etc.)
        $retryCount = $this->step->retries ?? 0;
        throttle_log($stepId, "[3] Calling {$throttlerClass}::canDispatch()...");
        throttle_log($stepId, "   ├─ Retry count: {$retryCount}");
        throttle_log($stepId, "   └─ Account ID: ".($accountId ?? 'NULL'));
        $secondsToWait = $throttler::canDispatch($retryCount, $accountId, $stepId);

        if ($secondsToWait > 0) {
            $this->jobBackoffSeconds = $secondsToWait;
            throttle_log($stepId, "   ❌ canDispatch() returned: {$secondsToWait}s");
            throttle_log($stepId, "   └─ DECISION: RESCHEDULE ({$this->jobBackoffSeconds}s backoff)");
            throttle_log($stepId, "╚═══════════════════════════════════════════════════════════╝");

            return false; // Throttled - retry
        }

        throttle_log($stepId, "   ✓ canDispatch() returned: 0s (proceed)");
        throttle_log($stepId, "└─ DECISION: PROCEED");
        throttle_log($stepId, "╚═══════════════════════════════════════════════════════════╝");

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
