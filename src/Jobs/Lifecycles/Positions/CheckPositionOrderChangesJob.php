<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use App\Support\NotificationService;
use App\Support\Throttler;
use Throwable;

final class CheckPositionOrderChangesJob extends BaseQueueableJob
{
    public Position $position;

    public bool $continue = true;

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
        $this->checkIfPositionWasClosedOnExchange();

        if ($this->continue) {
            $this->checkIfProfitOrderWasSomehowFilled();
        }

        if ($this->continue) {
            $this->checkIfALimitOrderWasFilled();
        }

        if ($this->continue) {
            $this->dispatchFinalPositionStatusUpdate();
        }
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
                ->withCanonical('check_position_order_changes')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
                        title: '['.class_basename(self::class).'] - Error',
                        deliveryGroup: 'exceptions'
                    );
                });

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }

    public function checkIfALimitOrderWasFilled(): void
    {
        $this->position->limitOrders()
            ->each(function ($limitOrder) {
                if ($limitOrder->status === 'FILLED' &&
                    $limitOrder->reference_status !== 'FILLED' &&
                    $limitOrder->position_side === $limitOrder->position->direction) {
                    Step::create([
                        'class' => ApplyWAPJob::class,
                        'queue' => 'positions',
                        'block_uuid' => $this->uuid(),
                        'child_block_uuid' => Str::uuid()->toString(),
                        'index' => 1,
                        'arguments' => [
                            'positionId' => $this->position->id,
                            'by' => 'watcher',
                        ],
                    ]);
                }
            });
    }

    public function checkIfPositionWasClosedOnExchange(): void
    {
        if (! $this->position->isOpenedOnExchange()) {
            $this->position->updateSaving(['closed_by' => 'watcher']);

            Step::create([
                'class' => ClosePositionJob::class,
                'queue' => 'positions',
                'block_uuid' => $this->uuid(),
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
            ]);

            $this->continue = false;
        }
    }

    public function checkIfProfitOrderWasSomehowFilled(): void
    {
        $profit = $this->position->profitOrder();

        if ($profit && $profit->status === 'FILLED' && $profit->reference_status !== 'FILLED') {
            $this->position->updateSaving(['closed_by' => 'watcher']);

            Step::create([
                'class' => ClosePositionJob::class,
                'queue' => 'positions',
                'block_uuid' => $this->uuid(),
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
            ]);

            $this->continue = false;
        }
        /**
         * In case there is a partially filled profit order, we just need to
         * update the profit order quantity, and also the position quantity.
         */
        elseif ($profit && $profit->status === 'PARTIALLY_FILLED' && $profit->reference_status !== 'PARTIALLY_FILLED') {
            $profit->updateSaving([
                'reference_quantity' => $profit->quantity,
                'reference_status' => 'PARTIALLY_FILLED',
            ]);

            $profit->position->updateSaving([
                'quantity' => $profit->quantity]);

            $this->continue = false;
        }
    }

    public function dispatchFinalPositionStatusUpdate(): void
    {
        Step::create([
            'class' => UpdatePositionStatusJob::class,
            'queue' => 'positions',
            'block_uuid' => $this->uuid(),
            'arguments' => [
                'positionId' => $this->position->id,
                'status' => 'active',
            ],
        ]);
    }
}
