<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

final class AccountBalanceHistory extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    protected $table = 'account_balance_history';

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
