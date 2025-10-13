<?php

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Jobs\Models\Position\CancelPositionOpenOrdersJob;
use Martingalian\Core\Jobs\Models\Position\ClosePositionAtomicallyJob;
use Martingalian\Core\Jobs\Models\Position\SyncPositionOrdersJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Jobs\Models\Position\UpdateRemainingClosingDataJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;

class ClosePositionJob extends BaseApiableJob
{
    public Position $position;

    public ?string $message = null;

    public function __construct(int $positionId, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->message = $message;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->position->account);
    }

    public function startOrStop()
    {
        // We can only close a position if it's still opened on the database.
        return in_array($this->position->status, $this->position->openedStatuses());
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $uuid = $this->uuid();

        $i = 1;

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'closing',
            ],
        ]);

        Step::create([
            'class' => CancelPositionOpenOrdersJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => ClosePositionAtomicallyJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => SyncPositionOrdersJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        /**
         * Lets verify now if a residual quantity position is still open.
         */
        Step::create([
            'class' => QueryPositionsJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'accountId' => $this->position->account->id,
            ],
        ]);

        Step::create([
            'class' => VerifyPositionResidualAmountJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => UpdateRemainingClosingDataJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => 7,
            'arguments' => [
                'positionId' => $this->position->id,
            ],
        ]);

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => 8,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'closed',
            ],
        ]);

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'type' => 'resolve-exception',
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'failed',
            ],
        ]);
    }
}
