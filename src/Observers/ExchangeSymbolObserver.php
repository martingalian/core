<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ExchangeSymbol;

final class ExchangeSymbolObserver
{
    public function saved(ExchangeSymbol $model): void
    {
        // Model-specific business logic
        $model->sendDelistingNotificationIfNeeded();
    }
}
