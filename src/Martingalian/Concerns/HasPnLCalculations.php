<?php

declare(strict_types=1);

namespace Martingalian\Core\Martingalian\Concerns;

use InvalidArgumentException;
use Martingalian\Core\Martingalian\Martingalian;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Support\Math;

trait HasPnLCalculations
{
    /**
     * Accumulative PnL helper.
     * Computes the new weighted-average price after adding a fill, and the unrealized PnL at the fill moment
     * using the fill price as the mark.
     *
     * @return array{
     *   cum_qty:string,
     *   avg_price:string,
     *   pnl:string
     * }
     */
    public static function calculatePnL(
        string $direction,
        $originalQuantity,
        $originalPrice,
        $lastQuantity,
        $lastPrice,
        ?ExchangeSymbol $exchangeSymbol = null
    ): array {
        $scale = Martingalian::SCALE;
        $dir = mb_strtoupper(mb_trim($direction));

        if (! in_array($dir, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $Q0 = (string) $originalQuantity;
        $P0 = (string) $originalPrice;
        $Q1 = (string) $lastQuantity;
        $P1 = (string) $lastPrice;

        $cumQty = Math::add($Q0, $Q1, $scale);
        $cumAmount = Math::add(Math::mul($Q0, $P0, $scale), Math::mul($Q1, $P1, $scale), $scale);
        $avg = Math::gt($cumQty, '0', $scale) ? Math::div($cumAmount, $cumQty, $scale) : '0';

        // Mark = fill price at this instant
        $pnlRaw = ($dir === 'LONG')
            ? Math::mul(Math::sub($P1, $avg, $scale), $cumQty, $scale)
            : Math::mul(Math::sub($avg, $P1, $scale), $cumQty, $scale);

        $out = [
            'cum_qty' => $cumQty,
            'avg_price' => $avg,
            'pnl' => $pnlRaw,
        ];

        if ($exchangeSymbol) {
            $out['cum_qty'] = api_format_quantity($cumQty, $exchangeSymbol);
            $out['avg_price'] = api_format_price($avg, $exchangeSymbol);
            $out['pnl'] = api_format_price($pnlRaw, $exchangeSymbol);
        }

        return $out;
    }

    /**
     * Calculates cumulative WAP per row for the provided rows.
     * Note: pass the MARKET row first if you want to include it in the cumulative WAP;
     * otherwise, this will compute over the limits you provide.
     *
     * @param  array<int,array{price:string|int|float,quantity:string|int|float}>  $limits
     * @return array<int,array{rung:int, wap:?string}>
     */
    public static function calculateWAPData(array $limits, string $direction, $profitPercent = null): array
    {
        $scale = Martingalian::SCALE;

        $direction = mb_strtoupper(mb_trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $useProfit = $profitPercent !== null && $profitPercent !== '';
        $p = '0';
        if ($useProfit) {
            $p = Martingalian::pctToDecimal((string) $profitPercent, 'profitPercent');
        }

        $out = [];
        $cumQty = '0';
        $cumNotional = '0';

        foreach ($limits as $idx => $row) {
            $price = isset($row['price']) ? (string) $row['price'] : null;
            $qty = isset($row['quantity']) ? (string) $row['quantity'] : null;

            if ($price === null || $qty === null || ! is_numeric($price) || ! is_numeric($qty)) {
                throw new InvalidArgumentException('Each row must have numeric "price" and "quantity".');
            }

            $cumQty = Math::add($cumQty, $qty, $scale);
            $lineNotional = Math::mul($price, $qty, $scale);
            $cumNotional = Math::add($cumNotional, $lineNotional, $scale);

            $wap = null;
            if (Math::gt($cumQty, '0', $scale)) {
                $baseWap = Math::div($cumNotional, $cumQty, $scale);

                if ($useProfit) {
                    $factor = ($direction === 'LONG')
                        ? Math::add('1', $p, $scale)
                        : Math::sub('1', $p, $scale);

                    if (Math::lte($factor, '0', $scale)) {
                        $wap = null;
                    } else {
                        $wap = Math::mul($baseWap, $factor, $scale);
                    }
                } else {
                    $wap = $baseWap;
                }
            }

            $out[] = [
                'rung' => $idx + 1,
                'wap' => $wap,
            ];
        }

        return $out;
    }
}
