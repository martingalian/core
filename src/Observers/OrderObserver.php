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
    }

    public function updating(Order $model): void
    {
        if ($model->status === 'FILLED') {
            $model->filled_at = now();
        }
    }
}
