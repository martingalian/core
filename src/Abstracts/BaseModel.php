<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Martingalian\Core\Concerns\BaseModel\HasConditionalUpdates;
use Martingalian\Core\Concerns\BaseModel\LogsApplicationEvents;
use Martingalian\Core\Concerns\HasModelCache;

abstract class BaseModel extends Model
{
    use HasModelCache;
    use HasConditionalUpdates;
    use LogsApplicationEvents;
}
