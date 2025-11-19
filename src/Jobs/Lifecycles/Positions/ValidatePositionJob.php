<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class ValidatePositionJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function compute()
    {
        $shouldCancel = false;

        // Update first profit price, to be able to compute the alpha path.
        $this->position->updateSaving([
            'first_profit_price' => $this->position->profitOrder()->price,
        ]);

        if (! in_array($this->position->status, $this->position->activeStatuses(), true)) {
            // Removed NotificationService::send - invalid canonical: position_validation_inactive_status

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->whereNull('orders.exchange_order_id')
            ->count() > 0) {
            // Removed NotificationService::send - invalid canonical: position_validation_unsynced_orders

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->where('orders.type', 'LIMIT')
            ->active()
            ->count() !== $this->position->total_limit_orders) {
            // Removed NotificationService::send - invalid canonical: position_validation_incorrect_limit_count

            $shouldCancel = true;
        }

        if ($shouldCancel) {
            Step::create([
                'class' => CancelPositionJob::class,
                'queue' => 'default',
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
            ]);
        }
    }

    public function resolveException(Throwable $e)
    {
        // Removed NotificationService::send - invalid canonical: position_validation_exception

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
