<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Position;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\Position\VerifyTradingPairNotOpenJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;

/*
 * DispatchPositionJob
 *
 * Dispatches a single position for trading on the exchange.
 * • Step 1: VerifyTradingPairNotOpenJob - Verifies trading pair is not already open, stops if it is
 * • Step 2+: TODO - Remaining position dispatch steps (margin, leverage, orders, etc.)
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
        // Step 1: Verify trading pair is not already open on exchange
        Step::create([
            'class' => VerifyTradingPairNotOpenJob::class,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 1,
        ]);

        // TODO: Add remaining steps (margin, leverage, orders, etc.)

        return [
            'position_id' => $this->position->id,
            'message' => 'Position dispatching initiated',
        ];
    }
}
