<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;

use Martingalian\Core\Models\Order;

final class OrderObserver
{
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
    }

    public function updating(Order $model): void
    {
        if ($model->status === 'FILLED') {
            $model->filled_at = now();
        }
    }
}
