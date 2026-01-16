<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use RuntimeException;

/**
 * PlaceMarketOrderJob (Atomic)
 *
 * Places the market entry order for a position.
 *
 * Preconditions (set by PreparePositionDataJob):
 * - position.status = 'opening'
 * - position.margin is set
 * - position.leverage is set
 * - position.exchange_symbol_id is set
 *
 * Flow:
 * 1. Compute market order parameters (side, qty, etc.)
 * 2. Create Order record (type=MARKET, status=NEW)
 * 3. Place order on exchange via apiPlace()
 * 4. doubleCheck() verifies order was filled
 * 5. complete() updates position with opening data
 */
class PlaceMarketOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $marketOrder = null;

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
     * Verify position is ready for market order.
     */
    public function startOrFail(): bool
    {
        // Position must be in 'opening' status (set by PreparePositionDataJob)
        if ($this->position->status !== 'opening') {
            return false;
        }

        // Margin must be set (set by PreparePositionDataJob)
        if ($this->position->margin === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $direction = $this->position->direction;
        $leverage = $this->position->leverage;

        // 1. Calculate market order notional using divider formula
        // divider = 2^(totalLimitOrders + 1) e.g., 4 limits = 32
        $divider = get_market_order_amount_divider($this->position->total_limit_orders);
        $margin = (string) $this->position->margin;
        $notional = bcdiv(bcmul($margin, (string) $leverage, 8), (string) $divider, 8);

        // 2. Get quantity using trait method (uses mark_price set by VerifyOrderNotionalJob)
        $quantity = $exchangeSymbol->getQuantityForAmount($notional, respectMinNotional: false);

        if ($quantity === '0') {
            throw new RuntimeException(
                "Failed to calculate quantity for notional {$notional} at price {$exchangeSymbol->mark_price}"
            );
        }

        // 3. Determine side from direction
        $side = $direction === 'LONG' ? 'BUY' : 'SELL';
        $markPrice = api_format_price((string) $exchangeSymbol->mark_price, $exchangeSymbol);

        // 4. Create Order record
        $this->marketOrder = Order::create([
            'position_id' => $this->position->id,
            'type' => 'MARKET',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $direction,
            'quantity' => $quantity,
            'reference_quantity' => $quantity,
            'reference_price' => $markPrice,
        ]);

        // 5. Place order on exchange
        // COMMENTED FOR SAFETY - Uncomment when ready for live testing
        // $apiResponse = $this->marketOrder->apiPlace();

        // Calculate actual notional for response
        $actualNotional = $exchangeSymbol->getAmountForQuantity((float) $quantity);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->marketOrder->id,
            'trading_pair' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $direction,
            'side' => $side,
            'quantity' => $quantity,
            'estimated_price' => $markPrice,
            'margin' => bcdiv($notional, (string) $leverage, 8),
            'notional' => $actualNotional,
            'message' => 'Market order created (apiPlace COMMENTED)',
            // 'exchange_order_id' => $apiResponse->result['orderId'] ?? null,
        ];
    }

    /**
     * Verify the market order was filled.
     *
     * COMMENTED: Enable when apiPlace() is active.
     */
    public function doubleCheck(): bool
    {
        if (! $this->marketOrder) {
            return false;
        }

        // COMMENTED FOR SAFETY - Uncomment when ready for live testing
        // $this->marketOrder->apiSync();
        // $this->marketOrder->refresh();
        // return $this->marketOrder->status === 'FILLED';

        // For now, just verify order was created
        return $this->marketOrder->exists;
    }

    /**
     * Update position with opening data after market order fills.
     *
     * COMMENTED: Enable when apiPlace() is active.
     */
    public function complete(): void
    {
        if (! $this->marketOrder) {
            return;
        }

        // COMMENTED FOR SAFETY - Uncomment when ready for live testing
        // When apiPlace() is active, update position with actual fill data:
        // $this->position->updateSaving([
        //     'quantity' => $this->marketOrder->quantity,
        //     'opening_price' => $this->marketOrder->price,
        //     'opened_at' => now(),
        //     'status' => 'opened',
        // ]);

        // Update order reference data
        // $this->marketOrder->updateSaving([
        //     'reference_status' => $this->marketOrder->status,
        // ]);
    }

    /**
     * Handle exceptions during market order placement.
     */
    public function resolveException(\Throwable $e): void
    {
        // Log error to position
        $this->position->updateSaving([
            'error_message' => $e->getMessage(),
        ]);

        // Let the exception handler notify admins
        throw $e;
    }
}
