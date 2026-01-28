<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;

use Martingalian\Core\Exceptions\NonNotifiableException;
use Martingalian\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Dispatched;
use Martingalian\Core\States\Pending;
use Martingalian\Core\States\Running;

final class OrderObserver
{
    private const array INACTIVE_STATUSES = ['CANCELLED', 'EXPIRED'];

    private const array CLOSE_TRIGGER_TYPES = ['PROFIT-LIMIT', 'PROFIT-MARKET', 'STOP-MARKET'];

    public function creating(Order $model): void
    {
        if (empty($model->uuid)) {
            $model->uuid = Str::uuid()->toString();
        }

        if (empty($model->client_order_id)) {
            $model->client_order_id = Str::uuid()->toString();
        }

        // Flag conditional orders for exchange-specific routing
        // Each exchange has different endpoints for stop orders:
        // - Binance: Algo Order API (/fapi/v1/algoOrder)
        // - KuCoin: Stop Orders API (/api/v1/stopOrders)
        // - Bitget: Plan Order API (/api/v2/mix/order/place-plan-order)
        // - Bybit: Same endpoint with triggerPrice parameter (uses orderFilter for queries)
        if ($model->type === 'STOP-MARKET' && $model->position?->account?->apiSystem !== null) {
            $canonical = $model->position->account->apiSystem->canonical;
            if (in_array($canonical, ['binance', 'kucoin', 'bitget', 'bybit'], strict: true)) {
                $model->is_algo = true;
            }
        }

        $this->enforceOrderLimits($model);
    }

    public function updating(Order $model): void
    {
        if ($model->status === 'FILLED') {
            $model->filled_at = now();
        }
    }

    /**
     * After an order is updated, check if status changed and react accordingly.
     *
     * PROFIT/STOP orders:
     * - FILLED → ClosePositionJob (orderly close)
     * - EXPIRED/CANCELLED → PreparePositionReplacementJob
     *
     * LIMIT orders (DCA):
     * - EXPIRED/CANCELLED → PreparePositionReplacementJob
     *   (queries exchange to verify position still exists before recreating)
     *
     * Uses reference_status to detect changes idempotently — prevents
     * double-dispatch when multiple syncs process the same order.
     */
    public function updated(Order $model): void
    {
        if ($model->status === $model->reference_status) {
            return;
        }

        $position = $model->position;

        if ($position === null) {
            return;
        }

        // Guard against dispatch if position is already closing/closed/cancelled
        if (! in_array($position->status, $position->activeStatuses(), true)) {
            return;
        }

        // PROFIT/STOP orders: FILLED triggers close, CANCELLED/EXPIRED triggers replacement
        if (in_array($model->type, self::CLOSE_TRIGGER_TYPES, true)) {
            match ($model->status) {
                'FILLED' => $this->dispatchClosePosition($model, $position),
                'EXPIRED', 'CANCELLED' => $this->dispatchPositionReplacement($model, $position),
                default => null,
            };

            return;
        }

        // LIMIT orders: CANCELLED/EXPIRED triggers replacement (recreate missing DCA orders)
        // Position existence is verified by PreparePositionReplacementJob before recreating
        if ($model->type === 'LIMIT' && in_array($model->status, ['EXPIRED', 'CANCELLED'], true)) {
            $this->dispatchPositionReplacement($model, $position);
        }
    }

    private function dispatchClosePosition(Order $model, mixed $position): void
    {
        $model->updateSaving(['reference_status' => 'FILLED']);

        // Set status to 'closing' immediately so SyncPositionOrdersJob
        // doesn't override it to 'active' before ClosePositionJob runs
        $position->updateToClosing();

        Step::create([
            'class' => ClosePositionJob::class,
            'arguments' => [
                'positionId' => $position->id,
                'message' => "{$model->type} order #{$model->id} filled — closing position",
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);
    }

    private function dispatchPositionReplacement(Order $model, mixed $position): void
    {
        // NOTE: Do NOT update reference_status here.
        // SmartReplaceOrdersJob needs to find orders where reference_status != status
        // to know which orders need recreation. RecreateCancelledOrderJob::complete()
        // updates reference_status after successful recreation.

        // Deduplicate: skip if PreparePositionReplacementJob already pending for this position.
        // Prevents multiple dispatches when several orders are cancelled at once.
        $alreadyPending = Step::query()
            ->where('class', PreparePositionReplacementJob::class)
            ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
            ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
            ->exists();

        if ($alreadyPending) {
            return;
        }

        $action = $model->status === 'EXPIRED' ? 'expired' : 'cancelled';

        Step::create([
            'class' => PreparePositionReplacementJob::class,
            'arguments' => [
                'positionId' => $position->id,
                'triggerStatus' => $model->status,
                'message' => "{$model->type} order #{$model->id} {$action} — preparing replacement",
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);
    }

    /**
     * Enforce maximum active order limits per type on a position.
     * Prevents duplicate STOP-MARKET, MARKET, PROFIT, or excess LIMIT orders.
     */
    private function enforceOrderLimits(Order $model): void
    {
        $position = $model->position;

        if ($position === null) {
            return;
        }

        $activeQuery = $position->orders()
            ->whereNotIn('status', self::INACTIVE_STATUSES);

        match ($model->type) {
            'STOP-MARKET' => $this->blockIfActiveExists($activeQuery, 'STOP-MARKET'),
            'MARKET' => $this->blockIfActiveExists($activeQuery, 'MARKET'),
            'MARKET-CANCEL' => $this->blockIfActiveExists($activeQuery, 'MARKET-CANCEL'),
            'PROFIT-LIMIT', 'PROFIT-MARKET' => $this->blockIfActiveProfitExists($activeQuery),
            'LIMIT' => $this->blockIfLimitExceeded($activeQuery, $position),
            default => null,
        };
    }

    private function blockIfActiveExists(mixed $query, string $type): void
    {
        if ((clone $query)->where('type', $type)->exists()) {
            throw new NonNotifiableException("{$type} order creation blocked: active order already exists");
        }
    }

    private function blockIfActiveProfitExists(mixed $query): void
    {
        if ((clone $query)->whereIn('type', ['PROFIT-LIMIT', 'PROFIT-MARKET'])->exists()) {
            throw new NonNotifiableException('PROFIT order creation blocked: active order already exists');
        }
    }

    private function blockIfLimitExceeded(mixed $query, mixed $position): void
    {
        $activeCount = (clone $query)->where('type', 'LIMIT')->count();

        if ($activeCount >= $position->total_limit_orders) {
            throw new NonNotifiableException("LIMIT order creation blocked: {$activeCount}/{$position->total_limit_orders} active");
        }
    }
}
