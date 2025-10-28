<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\NotificationThrottler;
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
            $this->position->logApplicationEvent(
                "Position {$this->position->parsed_trading_pair} not in an active-related status. Canceling position...",
                self::class,
                __FUNCTION__
            );

            NotificationThrottler::sendToAdmin(
                messageCanonical: 'validate_position',
                message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} not in an active-related status. Canceling position...",
                title: '['.class_basename(self::class).'] - Warning',
                deliveryGroup: 'exceptions'
            );

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->whereNull('orders.exchange_order_id')
            ->count() > 0) {
            $this->position->logApplicationEvent(
                "Position {$this->position->parsed_trading_pair} have orders with null exchange order id. Canceling position",
                self::class,
                __FUNCTION__
            );

            NotificationThrottler::sendToAdmin(
                messageCanonical: 'validate_position_2',
                message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} have invalid sync'ed orders. Canceling position...",
                title: '['.class_basename(self::class).'] - Warning',
                deliveryGroup: 'exceptions'
            );

            $shouldCancel = true;
        }

        if ($this->position->orders()
            ->where('orders.position_side', $this->position->direction)
            ->where('orders.type', 'LIMIT')
            ->active()
            ->count() !== $this->position->total_limit_orders) {
            $this->position->logApplicationEvent(
                "Position {$this->position->parsed_trading_pair} have a different number of total active limit orders. Canceling position...",
                self::class,
                __FUNCTION__
            );

            NotificationThrottler::sendToAdmin(
                messageCanonical: 'validate_position_3',
                message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} have a different number of total active limit orders. Canceling position...",
                title: '['.class_basename(self::class).'] - Warning',
                deliveryGroup: 'exceptions'
            );

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
        NotificationThrottler::sendToAdmin(
            messageCanonical: 'validate_position_4',
            message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} validation error - ".ExceptionParser::with($e)->friendlyMessage(),
            title: '['.class_basename(self::class).'] - Error',
            deliveryGroup: 'exceptions'
        );

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
