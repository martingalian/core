<?php

namespace Martingalian\Core\Observers;

use Illuminate\Support\Str;
use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Exceptions\NonNotifiableException;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\User;

class OrderObserver
{
    use LogsAttributeChanges;

    public function creating(Order $model): void
    {
        $model->cacheChangesForCreate();

        if (empty($model->uuid)) {
            $model->uuid = Str::uuid()->toString();
        }

        if (empty($model->client_order_id)) {
            $model->client_order_id = Str::uuid()->toString();
        }

        $direction = $model->position->direction;

        if ($model->position_side == $direction) {
            $existingStop = $model->position->stopMarketOrder();
            $existingMarket = $model->position->marketOrder();
            $existingProfit = $model->position->profitOrder();
            $existingLimits = $model->position->limitOrders();

            // STOP-MARKET
            if ($model->type === 'STOP-MARKET'
            && $existingStop
            && ! in_array($existingStop->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                $model->position->logApplicationEvent('The STOP-MARKET order cannot be created, because another is already active');

                /*
                User::notifyAdminsViaPushover(
                    "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$existingStop->id}",
                    'Order creation blocked - STOP-MARKET',
                    'nidavellir_warnings'
                );
                */

                throw new NonNotifiableException('STOP-MARKET order creation blocked because it exceed its threshold');
            }

            // MARKET
            if ($model->type === 'MARKET'
            && $existingMarket
            && ! in_array($existingMarket->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                $model->position->logApplicationEvent('The MARKET order cannot be created, because another is already active');

                /*
                User::notifyAdminsViaPushover(
                    "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$existingMarket->id}",
                    'Order creation blocked - MARKET',
                    'nidavellir_warnings'
                );
                */

                throw new NonNotifiableException('MARKET order creation blocked because it exceed its threshold');
            }

            // PROFIT (LIMIT or MARKET)
            if (in_array($model->type, ['PROFIT-LIMIT', 'PROFIT-MARKET'], true)
            && $existingProfit
            && ! in_array($existingProfit->status, ['CANCELLED', 'EXPIRED'], true)
            ) {
                $model->position->logApplicationEvent('The PROFIT order cannot be created, because another is already active');

                /*
                User::notifyAdminsViaPushover(
                    "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$existingProfit->id}",
                    'Order creation blocked - PROFIT',
                    'nidavellir_warnings'
                );
                */

                throw new NonNotifiableException('PROFIT-LIMIT order creation blocked because it exceed its threshold');
            }

            // LIMIT: cap at total_limit_orders (use >= to hard-stop overflow)
            if ($model->type === 'LIMIT'
            && $existingLimits->count() >= (int) $model->position->total_limit_orders
            ) {
                $model->position->logApplicationEvent('The LIMIT order cannot be created, because all limit orders are already created');

                $ids = $existingLimits->pluck('id')->join(', ');

                /*
                User::notifyAdminsViaPushover(
                    "Type: {$model->type}\nPosition ID: {$model->position_id}\nBlocking order IDs: {$ids}",
                    'Order creation blocked - LIMIT',
                    'nidavellir_warnings'
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

        $model->cacheChangesForUpdate();
    }

    public function created(Order $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function saved(Order $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(Order $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(Order $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(Order $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
