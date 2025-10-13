<?php

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Jobs\Models\Account\QueryPositionsJob;
use Martingalian\Core\Jobs\Models\Position\CancelPositionOpenOrdersJob;
use Martingalian\Core\Jobs\Models\Position\ClosePositionAtomicallyJob;
use Martingalian\Core\Jobs\Models\Position\SyncPositionOrdersJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;

class CancelPositionJob extends BaseApiableJob
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

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $uuid = Str::uuid()->toString();

        $i = 1;

        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'cancelling',
            ],
        ]);

        Step::create([
            'class' => ClosePositionAtomicallyJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
                'verifyPrice' => true,
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
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $uuid,
            'index' => $i++,
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'cancelled',
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
