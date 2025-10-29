<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class ForbiddenHostname extends BaseModel
{

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
