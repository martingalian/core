<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\NotificationContextables;

use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\Symbol;

/**
 * Throttle per symbol - each symbol gets independent throttle window.
 * Used for symbol-specific issues (CMC lookup failures, metadata issues).
 */
final class PerSymbolContextable
{
    public function __invoke(ApiRequestLog $log): ?string
    {
        $symbol = $log->relatable instanceof Symbol ? $log->relatable : null;

        return $symbol ? "symbol:{$symbol->id}" : null;
    }
}
