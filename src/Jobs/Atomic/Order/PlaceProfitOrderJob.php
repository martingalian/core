<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Order;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Martingalian\Martingalian;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use Throwable;

/**
 * PlaceProfitOrderJob (Atomic)
 *
 * Creates and places a take-profit order on the exchange.
 *
 * Calculation:
 * - Reference price: position.opening_price (market order fill price)
 * - Percentage: position.profit_percentage
 * - Quantity: position.quantity (market order quantity)
 * - Side: opposite of entry (LONG → SELL, SHORT → BUY)
 *
 * Flow:
 * 1. Calculate profit price using Martingalian::calculateProfitOrder()
 * 2. Create Order record with type=PROFIT-LIMIT
 * 3. Place order on exchange via apiPlace()
 * 4. doubleCheck() verifies order was accepted
 * 5. complete() sets reference_* fields
 */
class PlaceProfitOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $profitOrder = null;

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

    /**
     * Verify position is ready for profit order.
     */
    public function startOrFail(): bool
    {
        // Position must be in 'opening' status
        if ($this->position->status !== 'opening') {
            return false;
        }

        // Must have opening_price (market order filled)
        if ($this->position->opening_price === null) {
            return false;
        }

        // Must have quantity
        if ($this->position->quantity === null) {
            return false;
        }

        // Must have profit_percentage
        if ($this->position->profit_percentage === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;

        // Side is opposite to close position
        $side = $direction === 'LONG' ? 'SELL' : 'BUY';

        // Calculate profit order data
        $profitData = Martingalian::calculateProfitOrder(
            direction: $direction,
            referencePrice: $this->position->opening_price,
            profitPercent: $this->position->profit_percentage,
            currentQty: $this->position->quantity,
            exchangeSymbol: $exchangeSymbol,
        );

        // Create Order record
        $this->profitOrder = Order::create([
            'position_id' => $this->position->id,
            'type' => 'PROFIT-LIMIT',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $direction,
            'price' => $profitData['price'],
            'quantity' => $profitData['quantity'],
        ]);

        // Place on exchange
        $this->profitOrder->apiPlace();

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->profitOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $side,
            'price' => $profitData['price'],
            'quantity' => $profitData['quantity'],
            'reference_price' => $this->position->opening_price,
            'profit_percentage' => $this->position->profit_percentage,
            'message' => 'Profit order placed on exchange',
        ];
    }

    /**
     * Verify the profit order was accepted.
     */
    public function doubleCheck(): bool
    {
        if ($this->profitOrder === null) {
            return false;
        }

        $this->profitOrder->apiSync();
        $this->profitOrder->refresh();

        // Profit order is accepted if status is NEW (waiting) or FILLED (price hit immediately)
        return in_array($this->profitOrder->status, ['NEW', 'PARTIALLY_FILLED', 'FILLED'], true);
    }

    /**
     * Set reference data from first sync.
     */
    public function complete(): void
    {
        if ($this->profitOrder === null) {
            return;
        }

        $this->profitOrder->updateSaving([
            'reference_price' => $this->profitOrder->price,
            'reference_quantity' => $this->profitOrder->quantity,
            'reference_status' => $this->profitOrder->status,
        ]);

        // Store first_profit_price on position for reference
        $this->position->updateSaving([
            'first_profit_price' => $this->profitOrder->price,
        ]);
    }

    /**
     * Handle exceptions during profit order placement.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'Profit order failed: ' . $e->getMessage(),
        ]);

        throw $e;
    }
}
