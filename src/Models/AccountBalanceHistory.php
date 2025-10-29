<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class AccountBalanceHistory extends BaseModel
{

    protected $table = 'account_balance_history';

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
