<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\Quote;

final class QuoteObserver
{
    use LogsAttributeChanges;

    public function creating(Quote $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function created(Quote $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updating(Quote $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function updated(Quote $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(Quote $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(Quote $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
