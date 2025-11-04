<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;
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
            Throttler::using(NotificationService::class)
                ->withCanonical('position_validation_inactive_status')
                ->execute(function () {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} not in an active-related status. Canceling position...",
                        title: '['.class_basename(self::class).'] - Warning',
                        canonical: 'position_validation_inactive_status',
                        deliveryGroup: 'exceptions'
                    );
                });

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->whereNull('orders.exchange_order_id')
            ->count() > 0) {
            Throttler::using(NotificationService::class)
                ->withCanonical('position_validation_unsynced_orders')
                ->execute(function () {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} have invalid sync'ed orders. Canceling position...",
                        title: '['.class_basename(self::class).'] - Warning',
                        canonical: 'position_validation_unsynced_orders',
                        deliveryGroup: 'exceptions'
                    );
                });

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->where('orders.type', 'LIMIT')
            ->active()
            ->count() !== $this->position->total_limit_orders) {
            Throttler::using(NotificationService::class)
                ->withCanonical('position_validation_incorrect_limit_count')
                ->execute(function () {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} have a different number of total active limit orders. Canceling position...",
                        title: '['.class_basename(self::class).'] - Warning',
                        canonical: 'position_validation_incorrect_limit_count',
                        deliveryGroup: 'exceptions'
                    );
                });

            $shouldCancel = true;
        }

        if ($shouldCancel) {
            Step::create([
                'class' => CancelPositionJob::class,
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
            ]);
        }
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('position_validation_exception')
            ->execute(function () use ($e) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} validation error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    canonical: 'position_validation_exception',
                    deliveryGroup: 'exceptions'
                );
            });

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
