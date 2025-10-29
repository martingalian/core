<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class OrderHistory extends BaseModel
{
    protected $table = 'order_history';

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
