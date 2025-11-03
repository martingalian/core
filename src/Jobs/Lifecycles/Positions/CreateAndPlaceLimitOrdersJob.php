<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\Positions;

use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\Order\PlaceLimitOrderJob;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Throwable;

final class CreateAndPlaceLimitOrdersJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    /**
     * Bind the exception handler to the position's API system.
     */
    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount($this->position->account);
    }

    /**
     * For job tracking/UIs.
     */
    public function relatable()
    {
        return $this->position;
    }

    /**
     * Only proceed if the profit order was placed (i.e., we already have entry + TP context).
     */
    public function startOrFail(): bool
    {
        $profit = $this->position->profitOrder();

        return $profit && $profit->exchange_order_id !== null;
    }

    /**
     * Compute the ladder using Martingalian::calculateLimitOrdersData()
     * and enqueue placement steps.
     *
     * Notes:
     * - Reads the market order to obtain reference price/qty.
     * - Creates local LIMIT orders first (status NEW) for audit/reconciliation.
     * - Schedules PlaceLimitOrderJob steps with a shared block_uuid.
     */
    public function compute()
    {
        // --- Gather runtime context ---
        $marketOrder = $this->position->marketOrder();

        // Hard guard: we cannot plan limits without a market order as reference.
        if (! $marketOrder || $marketOrder->price === null || $marketOrder->quantity === null) {
            return;
        }

        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $referencePrice = (string) $marketOrder->price;
        $marketQty = (string) $marketOrder->quantity;
        $N = (int) $this->position->total_limit_orders;

        // If no rungs requested, exit gracefully.
        if ($N <= 0) {
            return;
        }

        // --- Calculate (price, quantity) pairs via Martingalian helper ---
        $planned = Martingalian::calculateLimitOrdersData(
            $this->position->margin,
            $this->position->leverage,
            $N,
            $direction,
            $referencePrice,
            $marketQty,
            $exchangeSymbol
        );

        if (empty($planned)) {
            return;
        }

        // Direction → side mapping remains explicit for clarity.
        $side = match ($direction) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            default => throw new InvalidArgumentException('Invalid position direction. Must be LONG or SHORT.'),
        };

        // --- Create LIMIT orders locally (status NEW) ---
        foreach ($planned as $row) {
            // Row shape guaranteed by calculator: ['price' => string, 'quantity' => string]
            $this->position->orders()->create([
                'type' => 'LIMIT',
                'status' => 'NEW',
                'side' => $side,
                'position_side' => $direction,
                'client_order_id' => Str::uuid()->toString(),
                'quantity' => $row['quantity'],
                'price' => $row['price'],
            ]);
        }

        // --- Enqueue placement steps (each order -> PlaceLimitOrderJob) ---
        // Use a shared block_uuid so the graph/workflow can visualize this batch.
        $blockUuid = $this->uuid();

        // If limitOrders() returns a relationship builder, fetch it; otherwise it may already be a collection.
        $limitOrders = method_exists($this->position, 'limitOrders')
            ? (is_iterable($this->position->limitOrders()) ? $this->position->limitOrders() : $this->position->limitOrders()->get())
            : $this->position->orders()->where('type', 'LIMIT')->orderBy('id')->get();

        foreach ($limitOrders as $limitOrder) {
            Step::create([
                'class' => PlaceLimitOrderJob::class,
                'queue' => 'orders',
                'block_uuid' => $blockUuid,
                'arguments' => [
                    'orderId' => $limitOrder->id,
                ],
            ]);
        }
    }

    /**
     * Centralized exception reporting for this job.
     */
    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('create_place_limit_orders')
            ->execute(function () {
                NotificationService::sendToAdmin(
                    message: "[{$this->position->id}] Placing limit orders for {$this->position->parsed_trading_pair} failed - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
