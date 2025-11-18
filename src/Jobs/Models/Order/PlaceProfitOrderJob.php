<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class PlaceProfitOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $profitOrder = null;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->position->account);
    }

    public function startOrFail()
    {
        $result = $this->position->marketOrder()->exchange_order_id !== null &&
                   $this->position->status === 'opening';

        if ($result === false) {
            $reason = '';

            if (is_null($this->position->marketOrder()->exchange_order_id)) {
                $reason = 'Market order exchange order id is null.';
            }

            if ($this->position->status !== 'opening') {
                $reason .= 'Position status not in opening';
            }

            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'place_profit_order_start_failed',
                referenceData: [
                    'position_id' => $this->position->id,
                    'trading_pair' => $this->position->parsed_trading_pair,
                    'reason' => $reason,
                    'job_class' => class_basename(self::class),
                ],
                cacheKey: "place_profit_order_start_failed:{$this->position->id}"
            );

            return $result;
        }
    }

    public function computeApiable()
    {
        $marketOrder = $this->position->marketOrder();
        $exchangeSymbol = $this->position->exchangeSymbol;

        // Calculate formatted price/qty (no side returned)
        $calc = Martingalian::calculateProfitOrder(
            $this->position->direction,          // used only to choose price path
            $marketOrder->price,                 // reference
            $this->position->profit_percentage,  // target %
            $this->position->quantity,           // initial qty; later WAP logic may adjust
            $exchangeSymbol,                     // position exchange symbol
            true                                 // take in account the profit price threshold
        );

        // Map direction → side HERE (outside calculator)
        $side = match ($this->position->direction) {
            'LONG' => 'SELL',
            'SHORT' => 'BUY',
            default => throw new InvalidArgumentException('Invalid position direction. Must be LONG or SHORT.'),
        };

        $this->profitOrder = $this->position->orders()->create([
            'type' => 'PROFIT-LIMIT',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $this->position->direction,
            'quantity' => $calc['quantity'],
            'price' => $calc['price'],
        ]);

        $this->profitOrder->apiPlace();

        return ['order' => format_model_attributes($this->profitOrder)];
    }

    public function doubleCheck(): bool
    {
        if (! $this->profitOrder) {
            $this->profitOrder = $this->position->profitOrder();
        }

        if (! $this->profitOrder->exchange_order_id) {
            $this->profitOrder->apiSync();
        }

        if (! $this->profitOrder->exchange_order_id) {
            $this->step->updateSaving([
                'error_message' => 'Double-check failed: exchange_order_id missing after apiSync.',
            ]);

            return false;
        }

        return true;
    }

    public function complete()
    {
        if (! $this->profitOrder) {
            $this->profitOrder = $this->position->profitOrder();
        }

        // Update reference data from current data.
        $this->profitOrder->updateSaving([
            'reference_price' => $this->profitOrder->price,
            'reference_quantity' => $this->profitOrder->quantity,
            'reference_status' => $this->profitOrder->status,
        ]);
    }

    public function resolveException(Throwable $e)
    {
        $this->step->updateSaving(['error_message' => $e->getMessage()]);

        if ($this->profitOrder) {
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'profit_order_placement_error',
                referenceData: [
                    'order_id' => $this->profitOrder->id,
                    'order_type' => $this->profitOrder->type,
                    'order_side' => $this->profitOrder->side,
                    'position_id' => $this->position->id,
                    'job_class' => class_basename(self::class),
                    'error_message' => $e->getMessage(),
                ],
                cacheKey: "profit_order_placement_error:{$this->profitOrder->id}"
            );
        } else {
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'profit_order_placement_error_no_order',
                referenceData: [
                    'position_id' => $this->position->id,
                    'job_class' => class_basename(self::class),
                    'error_message' => $e->getMessage(),
                ],
                cacheKey: "profit_order_placement_error_no_order:{$this->position->id}"
            );
        }
    }
}
