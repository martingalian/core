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
                /*
                Martingalian::notifyAdmins(
                    message: "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$existingStop->id}",
                    title: 'Order creation blocked - STOP-MARKET',
                    deliveryGroup: 'exceptions'
                );
                */

                throw new NonNotifiableException('STOP-MARKET order creation blocked because it exceed its threshold');
            }

            // MARKET
            if ($model->type === 'MARKET'
            && $existingMarket
            && ! in_array($existingMarket->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                /*
                Martingalian::notifyAdmins(
                    message: "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$existingMarket->id}",
                    title: 'Order creation blocked - MARKET',
                    deliveryGroup: 'exceptions'
                );
                */

                throw new NonNotifiableException('MARKET order creation blocked because it exceed its threshold');
            }

            // PROFIT (LIMIT or MARKET)
            if (in_array($model->type, ['PROFIT-LIMIT', 'PROFIT-MARKET'], true)
            && $existingProfit
            && ! in_array($existingProfit->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                /*
                Martingalian::notifyAdmins(
                    message: "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$existingProfit->id}",
                    title: 'Order creation blocked - PROFIT',
                    deliveryGroup: 'exceptions'
                );
                */

                throw new NonNotifiableException('PROFIT-LIMIT order creation blocked because it exceed its threshold');
            }

            // LIMIT: cap at total_limit_orders (use >= to hard-stop overflow)
            if ($model->type === 'LIMIT'
            && $existingLimits->count() >= (int) $model->position->total_limit_orders
            ) {
                $ids = $existingLimits->pluck('id')->join(', ');

                /*
                Martingalian::notifyAdmins(
                    message: "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$ids}",
                    title: 'Order creation blocked - LIMIT',
                    deliveryGroup: 'exceptions'
                );
                */

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

    public function created(Order $model): void {}

    public function saved(Order $model): void {}

    public function updated(Order $model): void {}

    public function deleted(Order $model): void {}

    public function forceDeleted(Order $model): void {}
}
