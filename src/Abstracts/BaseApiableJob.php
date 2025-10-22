<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Exception;
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
        if (! method_exists($this, 'assignExceptionHandler')) {
            throw new Exception('Exception handler not instanciated!');
        }

        $this->assignExceptionHandler();

        // Is this hostname forbidden on this account?
        if (ForbiddenHostname::query()
            ->where('account_id', $this->exceptionHandler->account->id)
            ->where('ip_address', gethostbyname(gethostname()))
            ->exists()
        ) {
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
            return $this->computeApiable();
        } catch (Throwable $e) {
            // Let the API-specific exception handler deal with the error.
            $this->handleApiException($e);
        }
    }
}
