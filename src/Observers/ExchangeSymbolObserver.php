<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsModelChanges;
use Martingalian\Core\Models\ExchangeSymbol;

final class ExchangeSymbolObserver
{
    use LogsModelChanges;

    public function creating(ExchangeSymbol $model): void {}

    public function updating(ExchangeSymbol $model): void {}

    public function created(ExchangeSymbol $model): void
    {
        // Log model creation
        $this->logModelCreation($model);
    }

    public function saved(ExchangeSymbol $model): void
    {
        // Delegate to model trait for delisting notification logic
        $model->sendDelistingNotificationIfNeeded();
    }

    public function updated(ExchangeSymbol $model): void
    {
        // Log model updates
        $this->logModelUpdate($model);
    }

    public function deleted(ExchangeSymbol $model): void {}

    public function forceDeleted(ExchangeSymbol $model): void {}
}
