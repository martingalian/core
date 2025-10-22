<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

final class BaseAssetMapper extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    public function apiSystem()
    {
        return $this->belongsTo(ApiSystem::class);
    }

    public function scopeForSystem($query, int $apiSystemId)
    {
        return $query->where('api_system_id', $apiSystemId);
    }
}
