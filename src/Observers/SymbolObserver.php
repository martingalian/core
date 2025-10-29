<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\Symbol;

final class SymbolObserver
{

    public function creating(Symbol $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function created(Symbol $model): void
    {
    }

    public function updating(Symbol $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function updated(Symbol $model): void
    {
    }

    public function deleted(Symbol $model): void
    {
    }

    public function forceDeleted(Symbol $model): void
    {
    }
}
