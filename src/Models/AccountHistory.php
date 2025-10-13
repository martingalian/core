<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class AccountHistory extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    protected $table = 'account_history';

    protected $casts = [
        'balances' => 'array',
        'positions' => 'array',
        'raw' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
