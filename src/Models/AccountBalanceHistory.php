<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class AccountBalanceHistory extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    protected $table = 'account_balance_history';

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
