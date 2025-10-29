<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use App\Support\NotificationService;
use App\Support\Throttler;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Position;
use Throwable;

final class DeletePositionHistoryDataJob extends BaseQueueableJob
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

    public function startOrFail()
    {
        return $this->position->status === 'closed';
    }

    public function compute()
    {
        // Delete everything that is not needed.
        $this->position->indicatorsHistory()->delete();
        // $this->position->steps()->delete();
        $this->position->orders->each(fn ($order) => $order->ordersHistory()->delete());

        return ['response' => 'All position historical data deleted'];
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('delete_position_history')
            ->execute(function () {
                NotificationService::sendToAdmin(
                    message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} historical data delete error - {$e->getMessage()}",
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
