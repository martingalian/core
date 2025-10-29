<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

final class CancelPositionOpenOrdersJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $response = $this->position->apiCancelOpenOrders();

        return $response->result;
    }
}
