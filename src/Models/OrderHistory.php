<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

final class OrderHistory extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    protected $table = 'order_history';

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
