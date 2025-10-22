<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\Symbol;

final class SymbolObserver
{
    use LogsAttributeChanges;

    public function creating(Symbol $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function created(Symbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updating(Symbol $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function updated(Symbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(Symbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(Symbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
