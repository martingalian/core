<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;

final class UpdateRemainingClosingDataJob extends BaseApiableJob
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
        if ($this->position->profitOrder()) {
            $apiResponse = $this->position->apiQueryTokenTrades();

            if (isset($apiResponse->result[0])) {
                $trade = $apiResponse->result[0];

                $this->position->updateSaving([
                    'closing_price' => $trade['price'],
                ]);
            }
        }

        $fastTradingDuration = $this->position
            ->account
            ->tradeConfiguration
            ->fast_trade_position_duration_seconds;

        $wasFast = false;

        if ($this->position->opened_at) {
            $duration = $this->position->opened_at->diffInSeconds(now());
            $wasFast = $duration < $fastTradingDuration;

            if ($wasFast) {
                $this->position->updateSaving(['was_fast_traded' => $wasFast]);
            }
        }

        // Finally update all orders reference_status from status.
        $this->position->orders->each(function (Order $order) {
            $order->updateSaving([
                'reference_status' => $order->status,
            ]);
        });

        return ['response' => 'Fast traded: '.($wasFast ? 'true' : 'false')];
    }
}
