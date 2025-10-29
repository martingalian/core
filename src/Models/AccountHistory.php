<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class AccountHistory extends BaseModel
{
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
