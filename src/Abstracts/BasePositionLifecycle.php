<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * BasePositionLifecycle
 *
 * Abstract base class for position lifecycle orchestrators.
 * Lifecycles are NOT steps themselves - they create step(s) for atomic jobs.
 *
 * Usage:
 * ```php
 * $resolver = JobProxy::with($position->account);
 * $lifecycleClass = $resolver->resolve(Lifecycle\Position\DispatchPositionJob::class);
 * $lifecycle = new $lifecycleClass($position);
 * $nextIndex = $lifecycle->dispatch(
 *     blockUuid: $parentBlockUuid,
 *     startIndex: 1,
 *     workflowId: $workflowId
 * );
 * ```
 */
abstract class BasePositionLifecycle
{
    protected Position $position;

    protected JobProxy $resolver;

    public function __construct(Position $position)
    {
        $this->position = $position;
        $this->resolver = JobProxy::with($position->account);
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
