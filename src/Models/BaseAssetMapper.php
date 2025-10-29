<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class BaseAssetMapper extends BaseModel
{
    public function apiSystem()
    {
        return $this->belongsTo(ApiSystem::class);
    }

    public function scopeForSystem($query, int $apiSystemId)
    {
        return $query->where('api_system_id', $apiSystemId);
    }
}
