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
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'position_validation_inactive_status',
                referenceData: [
                    'position_id' => $this->position->id,
                    'trading_pair' => $this->position->parsed_trading_pair,
                    'job_class' => class_basename(self::class),
                ],
                cacheKey: "position_validation_inactive_status:{$this->position->id}"
            );

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->whereNull('orders.exchange_order_id')
            ->count() > 0) {
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'position_validation_unsynced_orders',
                referenceData: [
                    'position_id' => $this->position->id,
                    'trading_pair' => $this->position->parsed_trading_pair,
                    'job_class' => class_basename(self::class),
                ],
                cacheKey: "position_validation_unsynced_orders:{$this->position->id}"
            );

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->where('orders.type', 'LIMIT')
            ->active()
            ->count() !== $this->position->total_limit_orders) {
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'position_validation_incorrect_limit_count',
                referenceData: [
                    'position_id' => $this->position->id,
                    'trading_pair' => $this->position->parsed_trading_pair,
                    'job_class' => class_basename(self::class),
                ],
                cacheKey: "position_validation_incorrect_limit_count:{$this->position->id}"
            );

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
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'position_validation_exception',
            referenceData: [
                'position_id' => $this->position->id,
                'trading_pair' => $this->position->parsed_trading_pair,
                'job_class' => class_basename(self::class),
                'error_message' => ExceptionParser::with($e)->friendlyMessage(),
            ],
            cacheKey: "position_validation_exception:{$this->position->id}"
        );

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
