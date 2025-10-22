<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Concerns\LogsAttributeChanges;
use Martingalian\Core\Models\ExchangeSymbol;

final class ExchangeSymbolObserver
{
    use LogsAttributeChanges;

    public function creating(ExchangeSymbol $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(ExchangeSymbol $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(ExchangeSymbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function updated(ExchangeSymbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function deleted(ExchangeSymbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }

    public function forceDeleted(ExchangeSymbol $model): void
    {
        $this->logChanges($model, self::class, __FUNCTION__);
    }
}
