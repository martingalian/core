<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

class BinanceListenKey extends BaseModel
{
    public $timestamps = false;

    public static function forAccount(Account $account): ?self
    {
        return static::where('account_id', $account->id)->first();
    }
}
