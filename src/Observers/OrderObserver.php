<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Exceptions\NonNotifiableException;
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

        $direction = $model->position->direction;

        if ($model->position_side === $direction) {
            $existingStop = $model->position->stopMarketOrder();
            $existingMarket = $model->position->marketOrder();
            $existingProfit = $model->position->profitOrder();
            $existingLimits = $model->position->limitOrders();

            // STOP-MARKET
            if ($model->type === 'STOP-MARKET'
            && $existingStop
            && ! in_array($existingStop->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                throw new NonNotifiableException('STOP-MARKET order creation blocked because it exceed its threshold');
            }

            // MARKET
            if ($model->type === 'MARKET'
            && $existingMarket
            && ! in_array($existingMarket->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                throw new NonNotifiableException('MARKET order creation blocked because it exceed its threshold');
            }

            // PROFIT (LIMIT or MARKET)
            if (in_array($model->type, ['PROFIT-LIMIT', 'PROFIT-MARKET'], true)
            && $existingProfit
            && ! in_array($existingProfit->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                throw new NonNotifiableException('PROFIT-LIMIT order creation blocked because it exceed its threshold');
            }

            // LIMIT: cap at total_limit_orders (use >= to hard-stop overflow)
            if ($model->type === 'LIMIT'
            && $existingLimits->count() >= (int) $model->position->total_limit_orders
            ) {
                throw new NonNotifiableException('LIMIT order creation blocked because it exceed its threshold');
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
