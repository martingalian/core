<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\BaseAssetMapper;

final class BaseAssetMapperObserver
{
    use LogsAttributeChanges;

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
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(BaseAssetMapper $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(BaseAssetMapper $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(BaseAssetMapper $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
