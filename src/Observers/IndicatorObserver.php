<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\Indicator;

final class IndicatorObserver
{
    use LogsAttributeChanges;

    public function creating(Indicator $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(Indicator $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(Indicator $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(Indicator $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(Indicator $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(Indicator $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
