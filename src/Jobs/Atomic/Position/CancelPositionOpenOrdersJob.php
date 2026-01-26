<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;

/**
 * CancelPositionOpenOrdersJob (Atomic)
 *
 * Cancels all open orders for a position on the exchange.
 *
 * Uses Position::apiCancelOpenOrders() which:
 * - Prepares cancel properties via ApiDataMapper
 * - Calls the exchange API to cancel all open orders for the symbol
 * - Returns the API response
 */
final class CancelPositionOpenOrdersJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $apiResponse = $this->position->apiCancelOpenOrders();

        return [
            'position_id' => $this->position->id,
            'symbol' => $this->position->parsed_trading_pair,
            'result' => $apiResponse->result,
            'message' => 'Open orders cancelled',
        ];
    }
}
