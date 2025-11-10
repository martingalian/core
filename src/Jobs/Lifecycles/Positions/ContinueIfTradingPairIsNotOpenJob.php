<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;

final class ContinueIfTradingPairIsNotOpenJob extends BaseQueueableJob
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

    public function compute()
    {
        $previousJobQueue = $this->step->getPrevious()->first();

        if ($previousJobQueue !== null && ($previousJobQueue->response['opened'] ?? false) === true) {
            Step::create([
                'class' => UpdatePositionStatusJob::class,
                'queue' => 'default',
                'arguments' => [
                    'positionId' => $this->position->id,
                    'status' => 'cancelled',
                ],
            ]);

            return $this->stopJob("Exchange Symbol {$this->position->parsed_trading_pair} already opened on the exchange, aborting position dispatch.");
        }

        return true;
    }
}
