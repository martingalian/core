<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Order;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use App\Support\NotificationService;
use App\Support\Throttler;
use Throwable;

final class PlaceMarketOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $marketOrder = null;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    /**
     * This job relates to a Position (for job tracking/UIs).
     */
    public function relatable()
    {
        return $this->position;
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
     * Start-or-fail gate for the job.
     *
     * Conditions:
     *  - Position must be in 'opening'
     *  - Position must have an exchange_symbol_id
     *
     * If it fails:
     *  - We safely guard against null exchangeSymbol before attempting to inactivate it.
     *  - We log and notify admins.
     */
    public function startOrFail(): bool
    {
        $ok = $this->position->status === 'opening'
            && $this->position->exchange_symbol_id !== null;

        if ($ok) {
            return true;
        }

        // Null-guard: exchange_symbol_id may be null; only inactivate if we can.
        if ($this->position->exchange_symbol_id && $this->position->exchangeSymbol) {
            $this->position->exchangeSymbol->updateSaving(['is_tradeable' => false]);

            $this->position->logApplicationEvent(
                '[StartOrFail] Preconditions failed. Exchange Symbol inactivated (is_tradeable = false).',
                self::class,
                __FUNCTION__
            );
        } else {
            $this->position->logApplicationEvent(
                '[StartOrFail] Preconditions failed. No exchange_symbol_id set; cannot inactivate symbol.',
                self::class,
                __FUNCTION__
            );
        }

        Throttler::using(NotificationService::class)
                ->withCanonical('place_market_order')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "{$this->position->parsed_trading_pair} trading deactivated due to an issue on startOrFail()",
                        title: '['.class_basename(self::class).'] - startOrFail() returned false',
                        deliveryGroup: 'exceptions'
                    );
                });

        return false;
    }

    /**
     * Build and place the MARKET order using Martingalian calculators.
     *
     * - Computes notional and market slice via Martingalian::calculateMarketOrderData()
     * - Creates the local NEW order before hitting the API
     * - Places the order through apiPlace()
     */
    public function computeApiable()
    {
        // --- Calculator: compute notional, divider slice, and exchange-snapped quantity.
        $calc = Martingalian::calculateMarketOrderData(
            $this->position->margin,
            $this->position->leverage,
            $this->position->total_limit_orders ?? 0,
            $this->position->exchangeSymbol
        );

        $marketQty = $calc['marketQty']; // already quantized for this symbol
        $side = match ($this->position->direction) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            default => throw new InvalidArgumentException('Invalid position direction. Must be LONG or SHORT.'),
        };

        // Create local order record (capture intent for reconciliation even if API fails).
        $this->marketOrder = $this->position->orders()->create([
            'type' => 'MARKET',
            'status' => 'NEW',
            'side' => $side,
            'position_side' => $this->position->direction,
            'client_order_id' => Str::uuid()->toString(),
            'quantity' => $marketQty,
            // No price on MARKET orders.
        ]);

        // Informational logs to aid audits/debugging.
        $this->position->logApplicationEvent(
            sprintf(
                '[Attempting] MARKET order [%s] Qty: %s | Notional: %s | Divider: %s | MarketAmount: %s',
                $this->marketOrder->id,
                $marketQty,
                $calc['notional'],
                $calc['divider'],
                $calc['marketAmount'],
            ),
            self::class,
            __FUNCTION__
        );

        // Place on the exchange.
        $this->marketOrder->apiPlace();

        // Return a compact API payload for the step UI/debuggers.
        return ['order' => format_model_attributes($this->marketOrder)];
    }

    /**
     * Double-check the order state on the exchange.
     *
     * - Ensures we have an exchange_order_id
     * - Syncs and requires status FILLED
     */
    public function doubleCheck(): bool
    {
        if (! $this->marketOrder) {
            $this->marketOrder = $this->position->marketOrder();
        }

        if (! $this->marketOrder || ! $this->marketOrder->exchange_order_id) {
            return false;
        }

        $this->marketOrder->apiSync();

        return $this->marketOrder->status === 'FILLED';
    }

    /**
     * Finalize the position with the executed market order data.
     *
     * - Saves reference_* on the order
     * - Updates position quantity/opening_price/opened_at
     * - Logs audit entries
     */
    public function complete()
    {
        if (! $this->marketOrder) {
            $this->marketOrder = $this->position->marketOrder();
        }

        // Persist order reference snapshot for later reconciliation.
        $this->marketOrder->updateSaving([
            'reference_price' => $this->marketOrder->price,
            'reference_quantity' => $this->marketOrder->quantity,
            'reference_status' => $this->marketOrder->status,
        ]);

        $this->position->logApplicationEvent(
            "[Completed] MARKET order [{$this->marketOrder->id}] placed (Price: {$this->marketOrder->price}, Qty: {$this->marketOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        $this->marketOrder->logApplicationEvent(
            "Order [{$this->marketOrder->id}] placed (Price: {$this->marketOrder->price}, Qty: {$this->marketOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        // Update position with the executed opening context.
        $this->position->updateSaving([
            'quantity' => $this->marketOrder->quantity,
            'opening_price' => $this->marketOrder->price,
            'opened_at' => now(),
        ]);
    }

    /**
     * Centralized exception reporting for this job.
     *
     * - Propagates error to Step
     * - Notifies admins with enough context to act
     */
    public function resolveException(Throwable $e)
    {
        $this->step->updateSaving(['error_message' => $e->getMessage()]);

        if ($this->marketOrder) {
            Throttler::using(NotificationService::class)
                ->withCanonical('place_market_order_2')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$this->marketOrder->id}] Order {$this->marketOrder->type} {$this->marketOrder->side} MARKET place error - {$e->getMessage()}",
                        title: '['.class_basename(self::class).'] - Error',
                        deliveryGroup: 'exceptions'
                    );
                });
        } else {
            Throttler::using(NotificationService::class)
                ->withCanonical('place_market_order_3')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$this->position->id}] MARKET place error before order instance - {$e->getMessage()}",
                        title: '['.class_basename(self::class).'] - Error',
                        deliveryGroup: 'exceptions'
                    );
                });
        }
    }
}
