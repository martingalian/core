<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\TradingMappers;

use Martingalian\Core\Models\ExchangeSymbol;

/**
 * KrakenTradingMapper
 *
 * Exchange-specific trading logic for Kraken.
 */
final class KrakenTradingMapper
{
    /**
     * Determine if an exchange symbol is now being delisted.
     *
     * Kraken logic:
     * - Perpetuals (PF_/PI_) have no lastTradingTime by default
     * - When lastTradingTime appears (null → value), the perpetual is being delisted
     * - Also notify on delivery date changes (rare but possible)
     */
    public function isNowDelisted(ExchangeSymbol $exchangeSymbol): bool
    {
        if (! $exchangeSymbol->wasChanged('delivery_ts_ms')) {
            return false;
        }

        $oldValue = $exchangeSymbol->getOriginal('delivery_ts_ms');
        $newValue = $exchangeSymbol->delivery_ts_ms;

        // Notify when: null → value OR value → different value
        return ($oldValue === null && $newValue !== null)
            || ($oldValue !== null && $newValue !== null && $oldValue !== $newValue);
    }
}
