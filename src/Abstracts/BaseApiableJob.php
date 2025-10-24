<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
use Log;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobExceptions;
use Martingalian\Core\Concerns\BaseApiableJob\HandlesApiJobLifecycle;
use Martingalian\Core\Models\ForbiddenHostname;
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
}
