<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ExchangeSymbol;

final class ExchangeSymbolObserver
{
    public function creating(ExchangeSymbol $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(ExchangeSymbol $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(ExchangeSymbol $model): void {}

    public function saved(ExchangeSymbol $model): void
    {
        // Delegate to model trait for delisting notification logic
        $model->sendDelistingNotificationIfNeeded();
    }

    public function updated(ExchangeSymbol $model): void {}

    public function deleted(ExchangeSymbol $model): void {}

    public function forceDeleted(ExchangeSymbol $model): void {}
}
