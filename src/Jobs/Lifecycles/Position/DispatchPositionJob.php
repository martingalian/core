<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Position;

/*
 * DispatchPositionJob
 *
 * Dispatches a single position for trading on the exchange.
 * TODO: Implement position dispatching logic (place orders, etc.)
 */
final class DispatchPositionJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Guard: Stop if position is not ready to be dispatched.
     * Requires: direction, exchange_symbol_id, and status = 'new'.
     */
    public function startOrStop(): bool
    {
        return filled($this->position->direction)
            && filled($this->position->exchange_symbol_id)
            && $this->position->status === 'new';
    }

    public function compute()
    {
        // TODO: Implement position dispatching logic

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
