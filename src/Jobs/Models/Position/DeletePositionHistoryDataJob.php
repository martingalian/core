<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;
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
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'delete_position_history',
            referenceData: [
                'position_id' => $this->position->id,
                'trading_pair' => $this->position->parsed_trading_pair,
                'job_class' => class_basename(self::class),
                'error_message' => $e->getMessage(),
            ],
            cacheKey: "delete_position_history:{$this->position->id}"
        );
    }
}
