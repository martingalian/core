<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationContextables;

use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\ExchangeSymbol;

/**
 * Throttle per exchange symbol - each symbol gets independent throttle window.
 * Used for symbol-specific issues (delistings, TAAPI data issues, price spikes).
 */
final class PerExchangeSymbolContextable
{
    public function __invoke(ApiRequestLog $log): ?string
    {
        $symbol = $log->relatable instanceof ExchangeSymbol ? $log->relatable : null;

        return $symbol ? "exchange_symbol:{$symbol->id}" : null;
    }
}
