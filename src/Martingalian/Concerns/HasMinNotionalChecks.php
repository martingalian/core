<?php

declare(strict_types=1);

namespace Martingalian\Core\Martingalian\Concerns;

use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\Math;

/**
 * Trait HasMinNotionalChecks
 *
 * Provides static methods for checking minimum order requirements across exchanges.
 *
 * Exchange-specific calculations:
 * - Binance/Bybit/BitGet: Direct min_notional field
 * - Kraken: kraken_min_order_size * current_price (contractSize=1 means $1/contract)
 * - KuCoin: kucoin_lot_size * kucoin_multiplier * current_price
 */
trait HasMinNotionalChecks
{
    /**
     * Check if an amount meets the minimum notional requirement for a symbol.
     *
     * @param  ExchangeSymbol  $symbol  The exchange symbol to check against
     * @param  string|float  $amount  The amount to verify
     * @return bool True if amount meets minimum, false otherwise
     */
    public static function meetsMinNotional(ExchangeSymbol $symbol, string|float $amount): bool
    {
        $minNotional = self::getEffectiveMinNotional($symbol);

        if ($minNotional === null) {
            return false;
        }

        return Math::gte($amount, $minNotional);
    }

    /**
     * Check if a symbol has all required data to calculate minimum order requirements.
     *
     * @param  ExchangeSymbol  $symbol  The exchange symbol to check
     * @return bool True if symbol has complete min order data
     */
    public static function hasMinOrderRequirements(ExchangeSymbol $symbol): bool
    {
        return self::getEffectiveMinNotional($symbol) !== null;
    }

    /**
     * Calculate the effective minimum notional for a symbol based on exchange type.
     *
     * @param  ExchangeSymbol  $symbol  The exchange symbol
     * @return float|null The minimum notional value, or null if cannot be calculated
     */
    public static function getEffectiveMinNotional(ExchangeSymbol $symbol): ?float
    {
        // Priority 1: Direct min_notional (Binance, Bybit, BitGet)
        if (filled($symbol->min_notional)) {
            return (float) $symbol->min_notional;
        }

        // Get current price for dynamic calculations
        $currentPrice = $symbol->current_price;

        // Priority 2: Kraken (kraken_min_order_size * price)
        if (filled($symbol->kraken_min_order_size)) {
            if (! filled($currentPrice)) {
                return null;
            }

            return (float) Math::mul($symbol->kraken_min_order_size, $currentPrice);
        }

        // Priority 3: KuCoin (lot_size * multiplier * price)
        if (filled($symbol->kucoin_lot_size) && filled($symbol->kucoin_multiplier)) {
            if (! filled($currentPrice)) {
                return null;
            }

            $contractValue = Math::mul($symbol->kucoin_lot_size, $symbol->kucoin_multiplier);

            return (float) Math::mul($contractValue, $currentPrice);
        }

        return null;
    }
}
