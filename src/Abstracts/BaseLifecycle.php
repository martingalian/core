<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * BaseLifecycle
 *
 * Abstract base class for lifecycle orchestrators.
 * Lifecycles are NOT steps themselves - they create step(s) for atomic jobs.
 *
 * Usage:
 * ```php
 * $resolver = JobProxy::with($account);
 * $lifecycleClass = $resolver->resolve(Lifecycle\Account\VerifyMinAccountBalanceJob::class);
 * $lifecycle = new $lifecycleClass($account);
 * $nextIndex = $lifecycle->dispatch(
 *     blockUuid: $parentBlockUuid,
 *     startIndex: 1,
 *     workflowId: $workflowId
 * );
 * ```
 */
abstract class BaseLifecycle
{
    protected Account $account;

    protected JobProxy $resolver;

    public function __construct(Account $account)
    {
        $this->account = $account;
        $this->resolver = JobProxy::with($account);
    }

    /**
     * Dispatch the lifecycle steps.
     *
     * @param  string  $blockUuid  The block UUID for step grouping
     * @param  int  $startIndex  The starting index for steps
     * @param  string|null  $workflowId  Optional workflow ID for grouping related steps
     * @return int The next available index (for chaining lifecycles)
     */
    abstract public function dispatch(
        string $blockUuid,
        int $startIndex,
        ?string $workflowId = null
    ): int;
}
