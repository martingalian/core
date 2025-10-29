<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\BaseAssetMapper;

final class BaseAssetMapperObserver
{

    public function creating(BaseAssetMapper $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(BaseAssetMapper $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(BaseAssetMapper $model): void
    {
    }

    public function updated(BaseAssetMapper $model): void
    {
    }

    public function deleted(BaseAssetMapper $model): void
    {
    }

    public function forceDeleted(BaseAssetMapper $model): void
    {
    }
}
