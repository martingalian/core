<?php

namespace Martingalian\Core\Support;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\LeverageBracket;
use Martingalian\Core\Models\Position;

/**
 * Martingalian — Unbounded ladder model (production)
 *
 * Description:
 * - MARKET leg uses the entire (marketMargin × leverage) as quote notional (no divider).
 * - LIMIT ladder is unbounded: quantities are chained from the MARKET quantity using step ratios.
 * - Feasible leverage selection uses a conservative unit-leverage worst-case K with configurable headroom.
 * - Rungs that round to zero quantity after formatting are dropped.
 * - Rung prices are clamped to symbol min/max and warnings are recorded.
 */
class Martingalian
{
    /**
     * Global decimal scale used across money/size math.
     * Keep aligned with Math::DEFAULT_SCALE.
     */
    public const SCALE = 16;

    /**
     * Extra headroom applied to the unit-leverage worst-case constant K when deriving feasible leverage.
     * You can override via config('martingalian.bracket_headroom_pct', ...).
     *
     * Example: '0.003' == 0.3%
     */
    public const BRACKET_HEADROOM_PCT = '0.003';

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
        $scale = self::SCALE;
        $dir = strtoupper(trim($direction));
        if (! in_array($dir, ['LONG', 'SHORT'], true)) {
            throw new \InvalidArgumentException('Direction must be LONG or SHORT.');
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

    /* -----------------------------------------------------------------
     |  Core helpers kept compatible with v1 for consistency
     | ----------------------------------------------------------------- */

    /**
     * Directional guard for opening more SHORT positions.
     * Policy: if any OPEN SHORT already has all its limit orders filled, block new SHORTs.
     */
    public static function canOpenShorts(Account $account): bool
    {
        $openShorts = $account->positions()
            ->opened()
            ->onlyShorts()
            ->get();

        foreach ($openShorts as $position) {
            if ($position->allLimitOrdersFilled()) {
                return false; // early exit
            }
        }

        return true;
    }

    /**
     * Directional guard for opening more LONG positions.
     * Policy: if any OPEN LONG already has all its limit orders filled, block new LONGs.
     */
    public static function canOpenLongs(Account $account): bool
    {
        $openLongs = $account->positions()
            ->opened()
            ->onlyLongs()
            ->get();

        foreach ($openLongs as $position) {
            if ($position->allLimitOrdersFilled()) {
                return false; // early exit
            }
        }

        return true;
    }

    /**
     * Computes notional = margin × leverage (string math).
     *
     * @param  string|int|float  $margin
     * @param  string|int|float  $leverage
     */
    public static function notional($margin, $leverage): string
    {
        return Math::mul($margin, $leverage, self::SCALE);
    }

    /**
     * Computes MARKET leg from the market margin and leverage.
     *
     * v2 semantics:
     *  - market_notional = market_margin × leverage
     *  - basis_price     = $referencePrice when provided; else symbol->mark_price; else symbol->last_price
     *  - market_qty      = market_notional ÷ basis_price
     *
     * @param  string|int|float  $marketMargin  Quote currency margin for the MARKET leg
     * @param  int|string  $leverage  Leverage to apply on the MARKET leg
     * @param  string|int|float|null  $referencePrice  Optional basis price to compute qty from
     * @return array{
     *   price:string,            // formatted basis price used for sizing
     *   quantity:string,         // formatted base quantity for the MARKET order
     *   amount:string,           // formatted quote notional (== market_margin × leverage)
     *   margin:string,           // formatted market margin (quote)
     *   notional:string          // alias of amount (formatted), kept for UI compatibility
     * }
     */
    public static function calculateMarketOrderData(
        $marketMargin,
        $leverage,
        ExchangeSymbol $exchangeSymbol,
        $referencePrice = null
    ): array {
        $scale = self::SCALE;

        $margin = (string) $marketMargin;
        if (! is_numeric($margin) || Math::lte($margin, '0', $scale)) {
            throw new \InvalidArgumentException("Market margin must be numeric and > 0 (got: {$marketMargin}).");
        }

        if (! is_numeric((string) $leverage) || Math::lt((string) $leverage, '1', 0)) {
            throw new \InvalidArgumentException("Leverage must be >= 1 (got: {$leverage}).");
        }
        $L = (int) $leverage;

        // Market notional = margin × L
        $marketNotional = self::notional($margin, $L); // raw decimal string

        // Basis price selection (reference > mark > last)
        $basisRaw = null;
        if ($referencePrice !== null && is_numeric((string) $referencePrice) && Math::gt((string) $referencePrice, '0', $scale)) {
            $basisRaw = (string) $referencePrice;
        } elseif (isset($exchangeSymbol->mark_price) && is_numeric((string) $exchangeSymbol->mark_price) && Math::gt((string) $exchangeSymbol->mark_price, '0', $scale)) {
            $basisRaw = (string) $exchangeSymbol->mark_price;
        } elseif (isset($exchangeSymbol->last_price) && is_numeric((string) $exchangeSymbol->last_price) && Math::gt((string) $exchangeSymbol->last_price, '0', $scale)) {
            $basisRaw = (string) $exchangeSymbol->last_price;
        } else {
            throw new \RuntimeException('No valid basis price available for market sizing (reference/mark/last are missing or <= 0).');
        }

        // Qty = notional / basis
        $qtyRaw = Math::div($marketNotional, $basisRaw, $scale);
        if ($qtyRaw === null) {
            throw new \RuntimeException("Division failed computing market qty (notional={$marketNotional}, basis={$basisRaw}).");
        }

        // Apply symbol precision AFTER numeric sizing
        $qtyFormatted = api_format_quantity($qtyRaw, $exchangeSymbol);
        if (Math::lte($qtyFormatted, '0', $scale)) {
            throw new \RuntimeException('Formatted market quantity rounded to zero at current lot size. Increase margin or leverage.');
        }

        $amountFormatted = api_format_price($marketNotional, $exchangeSymbol);
        $priceFormatted = api_format_price($basisRaw, $exchangeSymbol);

        // Margin derived back from notional/L (should equal input margin)
        $marginRaw = Math::div($marketNotional, (string) max(1, $L), $scale);
        $marginFormatted = api_format_price($marginRaw, $exchangeSymbol);

        return [
            'price' => $priceFormatted,
            'quantity' => $qtyFormatted,
            'amount' => $amountFormatted, // equals formatted notional
            'margin' => $marginFormatted,
            'notional' => $amountFormatted, // alias for UI compatibility
        ];
    }

    /**
     * Builds the LIMIT ladder (unbounded).
     * - No budget scaling.
     * - Prices built from referencePrice and side-specific gap% (overrideable via $gapPercent).
     * - Quantities chained from marketOrderQty using step ratios (N-1 provided; last repeats).
     * - Prices clamped to symbol min/max; warnings collected.
     * - Rungs that format to quantity <= 0 are dropped.
     *
     * @param  int|string  $totalLimitOrders  Number of rungs
     * @param  'LONG'|'SHORT'  $direction
     * @param  string|int|float  $referencePrice  Anchor for rung prices
     * @param  string|int|float  $marketOrderQty  Base qty to start chaining from
     * @param  ?array  $limitQuantityMultipliers  Step ratios (N-1 is fine; last repeats)
     * @param  string|int|float|null  $gapPercent  Optional override for gap %, e.g. 8.5 means 8.5%
     * @param  bool  $withMeta  When true, returns ['ladder'=>..., '__meta'=>...]
     * @return array<int,array{price:string,quantity:string,amount:string}>|array{
     *   ladder:array<int,array{price:string,quantity:string,amount:string}>,
     *   __meta:array{activeMultipliers:array<int,string>,warnings:array<int,array<string,mixed>>}
     * }
     */
    public static function calculateLimitOrdersData(
        $totalLimitOrders,
        $direction,
        $referencePrice,
        $marketOrderQty,
        ExchangeSymbol $exchangeSymbol,
        ?array $limitQuantityMultipliers = null,
        $gapPercent = null,
        bool $withMeta = false
    ): array {
        $scale = self::SCALE;

        $direction = strtoupper((string) $direction);
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new \InvalidArgumentException('Invalid position direction. Must be LONG or SHORT.');
        }

        $N = (int) $totalLimitOrders;
        if ($N < 1) {
            return $withMeta ? ['ladder' => [], '__meta' => ['activeMultipliers' => [], 'warnings' => []]] : [];
        }

        $ref = (string) $referencePrice;
        if (! is_numeric($ref) || Math::lte($ref, '0', $scale)) {
            throw new \InvalidArgumentException("referencePrice must be > 0 (got: {$referencePrice}).");
        }

        $marketQ = (string) $marketOrderQty;
        if (! is_numeric($marketQ) || Math::lt($marketQ, '0', $scale)) {
            throw new \InvalidArgumentException("marketOrderQty must be >= 0 (got: {$marketOrderQty}).");
        }

        // Gap% resolution: parameter override takes precedence; else fallback by side.
        $effectiveGapPercent = null;
        if ($gapPercent !== null) {
            if (! is_numeric((string) $gapPercent) || (float) $gapPercent < 0) {
                throw new \RuntimeException('gapPercent override must be a non-negative number when provided.');
            }
            $effectiveGapPercent = (string) $gapPercent; // e.g. "8.5" meaning 8.5%
        } else {
            $sideGap = $direction === 'LONG'
            ? $exchangeSymbol->percentage_gap_long
            : $exchangeSymbol->percentage_gap_short;

            if (! is_numeric((string) $sideGap) || (float) $sideGap < 0) {
                throw new \RuntimeException('percentage_gap_(long|short) must be a non-negative number on the symbol.');
            }
            $effectiveGapPercent = (string) $sideGap;
        }

        // Convert percent → decimal (e.g., 8.5 → 0.085)
        $gapDecimal = Math::div($effectiveGapPercent, '100', $scale);

        // Multipliers precedence: param > symbol default > [2,2,2,2]
        $mArray = $limitQuantityMultipliers
        ?? ($exchangeSymbol->limit_quantity_multipliers ?? [2, 2, 2, 2]);

        if (! is_array($mArray) || empty($mArray)) {
            throw new \RuntimeException('limit_quantity_multipliers must be a non-empty array.');
        }

        $warnings = [];
        $usedMultipliers = [];
        $rawPrices = [];
        $fmtPrices = [];
        $rungs = [];

        // Build rung prices first (with clamp to min/max) and keep raw for math.
        for ($i = 1; $i <= $N; $i++) {
            $factor = Math::mul($gapDecimal, (string) $i, $scale);

            $raw = ($direction === 'LONG')
            ? Math::mul($ref, Math::sub('1', $factor, $scale), $scale)
            : Math::mul($ref, Math::add('1', $factor, $scale), $scale);

            $clamped = false;
            $origRaw = $raw;

            if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
                if (Math::lt($raw, (string) $exchangeSymbol->min_price, $scale)) {
                    $raw = (string) $exchangeSymbol->min_price;
                    $clamped = true;
                }
            }
            if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
                if (Math::gt($raw, (string) $exchangeSymbol->max_price, $scale)) {
                    $raw = (string) $exchangeSymbol->max_price;
                    $clamped = true;
                }
            }

            if ($clamped) {
                $warnings[] = [
                    'type' => 'price_clamped',
                    'rung' => $i,
                    'original' => $origRaw,
                    'clamped' => $raw,
                ];
            }

            $rawPrices[] = $raw;
            $fmtPrices[] = api_format_price($raw, $exchangeSymbol);
        }

        // Quantities: chained from market qty using step ratios; last ratio repeats.
        $prev = $marketQ;
        for ($i = 0; $i < $N; $i++) {
            $mi = self::returnLadderedValue($mArray, $i); // clamps to last available ratio
            if (! is_numeric($mi) || (float) $mi <= 0) {
                throw new \RuntimeException('limit_quantity_multipliers must contain positive numeric values');
            }
            $usedMultipliers[] = (string) $mi;

            $prev = Math::mul($prev, (string) $mi, $scale);
            $qtyRaw = $prev;

            // Apply symbol lot/precision formatting
            $qtyFmt = api_format_quantity($qtyRaw, $exchangeSymbol);

            // Drop rung if formatted qty is zero or <= 0
            if (Math::lte($qtyFmt, '0', $scale)) {
                $warnings[] = [
                    'type' => 'rung_dropped_zero_qty',
                    'rung' => $i + 1,
                ];

                continue;
            }

            // Use RAW price × formatted quantity to avoid compounding rounding
            $amountRaw = Math::mul($rawPrices[$i], $qtyFmt, $scale);

            $rungs[] = [
                'price' => $fmtPrices[$i],
                'quantity' => $qtyFmt,
                'amount' => api_format_price($amountRaw, $exchangeSymbol),
            ];
        }

        if ($withMeta) {
            return [
                'ladder' => $rungs,
                '__meta' => [
                    'activeMultipliers' => $usedMultipliers,
                    'warnings' => $warnings,
                ],
            ];
        }

        return $rungs;
    }

    /**
     * Plans an unbounded position:
     * - Computes feasible leverage (<= $leverageCap) via unit-leverage worst-case K with configurable headroom.
     * - Computes the MARKET leg (using the chosen leverage).
     * - Builds the unbounded LIMIT ladder (drop-zero, clamp prices with warnings).
     *
     * Returns a structured payload suitable for the simulator.
     *
     * @param  'LONG'|'SHORT'  $direction
     * @param  string|int|float  $referencePrice
     * @param  string|int|float  $marketMargin
     * @return array{
     *   leverage: array{requestedCap:int, chosen:int, reason:string, bracket:array<string,mixed>|null},
     *   market_order: array{price:string,quantity:string,amount:string,margin:string},
     *   limit_ladder: array<int,array{price:string,quantity:string,amount:string}>,
     *   totals: array{market_notional:string,limits_notional:string,total_notional:string,required_margin:string,blow_up_factor:string},
     *   diagnostics?: array{K_unit:string, headroom_pct:string, intervals_considered:array<int,array<string,mixed>>, active_ratios:array<int,string>, warnings:array<int,array<string,mixed>>}
     * }
     */
    public static function planUnboundedPosition(
        ExchangeSymbol $exchangeSymbol,
        string $direction,
        $referencePrice,
        $marketMargin,
        int $leverageCap,
        int $totalLimitOrders,
        ?array $limitQuantityMultipliers = null,
        bool $withDiagnostics = true
    ): array {
        $scale = self::SCALE;

        $direction = strtoupper(trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new \InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $ref = (string) $referencePrice;
        if (! is_numeric($ref) || Math::lte($ref, '0', $scale)) {
            throw new \InvalidArgumentException("referencePrice must be > 0 (got: {$referencePrice}).");
        }

        $M0 = (string) $marketMargin;
        if (! is_numeric($M0) || Math::lte($M0, '0', $scale)) {
            throw new \InvalidArgumentException("marketMargin must be > 0 (got: {$marketMargin}).");
        }

        if ($leverageCap < 1) {
            throw new \InvalidArgumentException('leverageCap must be >= 1.');
        }

        $N = (int) $totalLimitOrders;
        if ($N < 1) {
            throw new \InvalidArgumentException('totalLimitOrders must be >= 1.');
        }

        // Step ratios precedence
        $ratios = $limitQuantityMultipliers
            ?? ($exchangeSymbol->limit_quantity_multipliers ?? [2, 2, 2, 2]);

        if (! is_array($ratios) || empty($ratios)) {
            throw new \RuntimeException('limit_quantity_multipliers must be a non-empty array.');
        }

        /* -------------------------------
         * 1) Unit-leverage worst-case K
         * ------------------------------- */
        // Unit-L market qty: Q0' = M0 / P0
        $Q0_unit = Math::div($M0, $ref, $scale);

        // Prices for K computation (with the same clamp/warnings policy)
        $gapPercent = $direction === 'LONG'
            ? $exchangeSymbol->percentage_gap_long
            : $exchangeSymbol->percentage_gap_short;

        $gapDecimal = Math::div((string) $gapPercent, '100', $scale);

        $pricesK = [];
        $warningsK = [];
        for ($i = 1; $i <= $N; $i++) {
            $factor = Math::mul($gapDecimal, (string) $i, $scale);
            $raw = ($direction === 'LONG')
                ? Math::mul($ref, Math::sub('1', $factor, $scale), $scale)
                : Math::mul($ref, Math::add('1', $factor, $scale), $scale);

            $clamped = false;
            $orig = $raw;

            if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
                if (Math::lt($raw, (string) $exchangeSymbol->min_price, $scale)) {
                    $raw = (string) $exchangeSymbol->min_price;
                    $clamped = true;
                }
            }
            if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
                if (Math::gt($raw, (string) $exchangeSymbol->max_price, $scale)) {
                    $raw = (string) $exchangeSymbol->max_price;
                    $clamped = true;
                }
            }

            if ($clamped) {
                $warningsK[] = [
                    'type' => 'price_clamped',
                    'rung' => $i,
                    'original' => $orig,
                    'clamped' => $raw,
                ];
            }

            $pricesK[] = $raw; // raw (string) for K math
        }

        // Quantities (unit-L) chained from Q0'
        $rawQtysUnit = [];
        $prev = $Q0_unit;
        $activeRatios = [];
        for ($i = 0; $i < $N; $i++) {
            $mi = self::returnLadderedValue($ratios, $i);
            if (! is_numeric($mi) || (float) $mi <= 0) {
                throw new \RuntimeException('limit_quantity_multipliers must contain positive numeric values');
            }
            $activeRatios[] = (string) $mi;
            $prev = Math::mul($prev, (string) $mi, $scale);
            $rawQtysUnit[$i] = $prev;
        }

        // Limits notional at L=1 (raw, conservative)
        $A_lim_unit = '0';
        for ($i = 0; $i < $N; $i++) {
            $A_lim_unit = Math::add($A_lim_unit, Math::mul($pricesK[$i], $rawQtysUnit[$i], $scale), $scale);
        }

        // K_raw = M0 + A_lim_unit ; apply headroom
        $K_raw = Math::add($M0, $A_lim_unit, $scale);
        $h = (string) (config('martingalian.bracket_headroom_pct', self::BRACKET_HEADROOM_PCT));
        $K = Math::mul($K_raw, Math::add('1', $h, $scale), $scale);

        // Determine feasible leverage via bracket intervals
        $intervals = [];
        $brackets = LeverageBracket::query()
            ->where('exchange_symbol_id', (int) $exchangeSymbol->id)
            ->orderBy('notional_floor')
            ->get([
                'bracket', 'initial_leverage', 'notional_floor', 'notional_cap', 'maint_margin_ratio',
            ]);

        $bestL = 0;
        $bestBkt = null;
        foreach ($brackets as $b) {
            $floor = (string) $b->notional_floor;
            $cap = (string) $b->notional_cap;
            $initL = (int) $b->initial_leverage;

            // Lmin = ceil(floor / K), LmaxFromCap = floor(cap / K)
            $Lmin = self::ceilPosDiv($floor, $K);
            $LmaxFromCap = self::floorPosDiv($cap, $K);
            $Lmax = min($LmaxFromCap, $initL, $leverageCap);

            $intervals[] = [
                'bracket' => $b->toArray(),
                'Lmin' => $Lmin,
                'Lmax' => $Lmax,
            ];

            if ($Lmin <= $Lmax && $Lmax > $bestL) {
                $bestL = $Lmax;
                $bestBkt = $b;
            }
        }

        if ($bestL < 1) {
            $chosenLev = 1;
            $levInfo = [
                'requestedCap' => (int) $leverageCap,
                'chosen' => $chosenLev,
                'reason' => 'no_feasible',
                'bracket' => null,
            ];
        } else {
            $chosenLev = (int) $bestL;
            $levInfo = [
                'requestedCap' => (int) $leverageCap,
                'chosen' => $chosenLev,
                'reason' => ($chosenLev === $leverageCap ? 'target_ok_or_top_cap' : 'clamped_by_bracket'),
                'bracket' => $bestBkt ? $bestBkt->toArray() : null,
            ];
        }

        /* -----------------------------
         * 2) MARKET leg at chosen L
         * ----------------------------- */
        $market = self::calculateMarketOrderData($M0, $chosenLev, $exchangeSymbol, $referencePrice);

        /* -----------------------------
         * 3) Unbounded ladder (no cap)
         * ----------------------------- */
        $ladderPayload = self::calculateLimitOrdersData(
            $N,
            $direction,
            $ref,
            (string) $market['quantity'],
            $exchangeSymbol,
            $ratios,
            true // meta to collect warnings/ratios
        );

        $ladder = $ladderPayload['ladder'] ?? [];
        $warns = $ladderPayload['__meta']['warnings'] ?? [];
        $active = $ladderPayload['__meta']['activeMultipliers'] ?? [];

        // Compute limits-only notional from formatted rungs (sum rawPrice × qtyFmt already formatted in amount)
        $A_lim = '0';
        foreach ($ladder as $row) {
            // Recompute raw to avoid loss: amount is already formatted, so prefer raw recompute
            $A_lim = Math::add($A_lim, Math::mul((string) $row['price'], (string) $row['quantity'], $scale), $scale);
        }

        // Totals at chosen leverage
        $A_mkt = Math::mul($M0, (string) $chosenLev, $scale);
        $A_tot = Math::add($A_mkt, $A_lim, $scale);
        $reqMargin = Math::div($A_tot, (string) max(1, $chosenLev), $scale);
        $blowUp = Math::equal($A_mkt, '0', $scale) ? '0' : Math::div($A_tot, $A_mkt, $scale);

        $out = [
            'leverage' => $levInfo,
            'market_order' => [
                'price' => $market['price'],
                'quantity' => $market['quantity'],
                'amount' => $market['amount'],
                'margin' => $market['margin'],
            ],
            'limit_ladder' => $ladder,
            'totals' => [
                'market_notional' => api_format_price($A_mkt, $exchangeSymbol),
                'limits_notional' => api_format_price($A_lim, $exchangeSymbol),
                'total_notional' => api_format_price($A_tot, $exchangeSymbol),
                'required_margin' => api_format_price($reqMargin, $exchangeSymbol),
                'blow_up_factor' => $blowUp,
            ],
        ];

        if ($withDiagnostics) {
            $out['diagnostics'] = [
                'K_unit' => $K ?? '0',
                'headroom_pct' => (string) $h,
                'intervals_considered' => $intervals ?? [],
                'active_ratios' => $active,
                'warnings' => array_merge($warningsK, $warns),
            ];
        }

        return $out;
    }

    /**
     * Calculates PROFIT (TP) order price and quantity from a reference price.
     * - Uses the provided reference price and profit percent.
     * - Clamps to symbol min/max.
     * - Optionally re-anchors to mark price if the computed TP would be on the wrong side of mark
     *   (i.e., LONG: TP <= mark; SHORT: TP >= mark), controlled by $recalculateOnLowerThanMarkPrice.
     */
    public static function calculateProfitOrder(
        string $direction,
        $referencePrice,
        $profitPercent,
        $currentQty,
        ExchangeSymbol $exchangeSymbol,
        bool $recalculateOnLowerThanMarkPrice = false
    ): array {
        $scale = self::SCALE;

        $direction = strtoupper(trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new \InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $ref = (string) $referencePrice;
        if (! is_numeric($ref) || Math::lte($ref, '0', $scale)) {
            throw new \InvalidArgumentException("Reference price must be > 0 (got: {$referencePrice}).");
        }

        $qty = (string) $currentQty;
        if (! is_numeric($qty) || Math::lt($qty, '0', $scale)) {
            throw new \InvalidArgumentException("Quantity must be >= 0 (got: {$currentQty}).");
        }

        // Percent → decimal (>= 0)
        $pctDecimal = self::pctToDecimal((string) $profitPercent, 'Profit percent');

        // Initial TP calc from ref
        $profitDelta = Math::mul($ref, $pctDecimal, $scale);
        $rawPrice = ($direction === 'LONG')
            ? Math::add($ref, $profitDelta, $scale)
            : Math::sub($ref, $profitDelta, $scale);

        // Optional re-anchor: if TP is on the wrong side of mark, recompute from mark
        if ($recalculateOnLowerThanMarkPrice && isset($exchangeSymbol->mark_price) && is_numeric((string) $exchangeSymbol->mark_price)) {
            $mark = (string) $exchangeSymbol->mark_price;
            $shouldRecalc = ($direction === 'LONG')
                ? Math::lte($rawPrice, $mark, $scale)  // TP <= mark
                : Math::gte($rawPrice, $mark, $scale); // TP >= mark

            if ($shouldRecalc) {
                $ref = $mark;
                $profitDelta = Math::mul($ref, $pctDecimal, $scale);
                $rawPrice = ($direction === 'LONG')
                    ? Math::add($ref, $profitDelta, $scale)
                    : Math::sub($ref, $profitDelta, $scale);
            }
        }

        if (Math::lte($rawPrice, '0', $scale)) {
            throw new \RuntimeException("Computed profit price <= 0 (ref={$ref}, pct={$profitPercent}).");
        }

        // Clamp to symbol bounds
        if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
            if (Math::lt($rawPrice, (string) $exchangeSymbol->min_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->min_price;
            }
        }
        if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
            if (Math::gt($rawPrice, (string) $exchangeSymbol->max_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->max_price;
            }
        }

        $price = api_format_price($rawPrice, $exchangeSymbol);
        $quantity = api_format_quantity($qty, $exchangeSymbol);

        // Use RAW price × formatted quantity
        $amountRaw = Math::mul($rawPrice, $quantity, $scale);
        $amount = api_format_price($amountRaw, $exchangeSymbol);

        return [
            'price' => $price,
            'quantity' => $quantity,
            'amount' => $amount,
        ];
    }

    /**
     * Calculates STOP-MARKET trigger price and quantity from an anchor price and stop percent.
     * - LONG: anchor * (1 - pct)
     * - SHORT: anchor * (1 + pct)
     * - Clamps to symbol min/max.
     */
    public static function calculateStopLossOrder(
        string $direction,
        $anchorPrice,
        $stopPercent,
        $currentQty,
        ExchangeSymbol $exchangeSymbol
    ): array {
        $scale = self::SCALE;

        $direction = strtoupper(trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new \InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $anchor = (string) $anchorPrice;
        if (! is_numeric($anchor) || Math::lte($anchor, '0', $scale)) {
            throw new \InvalidArgumentException("Anchor price must be > 0 (got: {$anchorPrice}).");
        }

        $qty = (string) $currentQty;
        if (! is_numeric($qty) || Math::lt($qty, '0', $scale)) {
            throw new \InvalidArgumentException("Quantity must be >= 0 (got: {$currentQty}).");
        }

        // Percent → decimal (>= 0)
        $pctDecimal = self::pctToDecimal((string) $stopPercent, 'Stop percent');

        // LONG: anchor * (1 - pct)  |  SHORT: anchor * (1 + pct)
        $mult = ($direction === 'SHORT')
            ? Math::add('1', $pctDecimal, $scale)
            : Math::sub('1', $pctDecimal, $scale);

        $rawPrice = Math::mul($anchor, $mult, $scale);

        // Clamp to symbol bounds
        if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
            if (Math::lt($rawPrice, (string) $exchangeSymbol->min_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->min_price;
            }
        }
        if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
            if (Math::gt($rawPrice, (string) $exchangeSymbol->max_price, $scale)) {
                $rawPrice = (string) $exchangeSymbol->max_price;
            }
        }

        if (Math::lte($rawPrice, '0', $scale)) {
            throw new \RuntimeException("Computed stop price <= 0 (anchor={$anchor}, pct={$stopPercent}).");
        }

        $price = api_format_price($rawPrice, $exchangeSymbol);
        $quantity = api_format_quantity($qty, $exchangeSymbol);

        // Use RAW price × formatted quantity
        $amountRaw = Math::mul($rawPrice, $quantity, $scale);
        $amount = api_format_price($amountRaw, $exchangeSymbol);

        return [
            'price' => $price,
            'quantity' => $quantity,
            'amount' => $amount,
        ];
    }

    /**
     * Returns whether we are in testing mode (e.g., use binance testnet).
     */
    public static function testingMode(): bool
    {
        return (bool) config('martingalian.testing.enabled');
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
        $scale = self::SCALE;

        $direction = strtoupper(trim($direction));
        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            throw new \InvalidArgumentException('Direction must be LONG or SHORT.');
        }

        $useProfit = $profitPercent !== null && $profitPercent !== '';
        $p = '0';
        if ($useProfit) {
            $p = self::pctToDecimal((string) $profitPercent, 'profitPercent');
        }

        $out = [];
        $cumQty = '0';
        $cumNotional = '0';

        foreach ($limits as $idx => $row) {
            $price = isset($row['price']) ? (string) $row['price'] : null;
            $qty = isset($row['quantity']) ? (string) $row['quantity'] : null;

            if ($price === null || $qty === null || ! is_numeric($price) || ! is_numeric($qty)) {
                throw new \InvalidArgumentException('Each row must have numeric "price" and "quantity".');
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

    /**
     * Business policy to decide whether we can open new positions for the given account.
     * Rules:
     * - User must be active and allowed to trade.
     * - Account must be allowed to trade.
     * - If no positions are open, allow.
     * - If more than half of LONGS/SHORTS are past halfway of their ladders, block.
     */
    public static function canOpenNewPositions(Account $account): bool
    {
        $opened = $account->positions()->opened();

        // User/account gates
        if (! $account->user->is_active) {
            return false;
        }
        if (! $account->user->can_trade) {
            return false;
        }
        if (! $account->can_trade) {
            return false;
        }

        // No positions opened?
        if ($opened->count() === 0) {
            return true;
        }

        // Shorts threshold rule
        $shorts = $opened->onlyShorts()->get(['id', 'direction', 'total_limit_orders']);
        $totalShorts = $shorts->count();
        $thresholdShort = intdiv($totalShorts, 2);

        if ($totalShorts > 0) {
            $tooDeepShorts = 0;

            $shorts->each(function (Position $position) use (&$tooDeepShorts) {
                if ($position->totalLimitOrdersFilled() > $position->total_limit_orders / 2) {
                    $tooDeepShorts++;
                }
            });

            if ($tooDeepShorts > $thresholdShort) {
                return false;
            }
        }

        // Longs threshold rule
        $longs = $opened->onlyLongs()->get(['id', 'direction', 'total_limit_orders']);
        $totalLongs = $longs->count();
        $thresholdLong = intdiv($totalLongs, 2);

        if ($totalLongs > 0) {
            $tooDeepLongs = 0;

            $longs->each(function (Position $position) use (&$tooDeepLongs) {
                if ($position->totalLimitOrdersFilled() > $position->total_limit_orders / 2) {
                    $tooDeepLongs++;
                }
            });

            if ($tooDeepLongs > $thresholdLong) {
                return false;
            }
        }

        return true;
    }

    /* -----------------------------------------------------------------
     |  Private helpers (integer div for positive decimals)
     | ----------------------------------------------------------------- */

    /**
     * floor(a / b) for positive decimals (returns int).
     */
    private static function floorPosDiv(string $a, string $b): int
    {
        // bcdiv with scale 0 truncates towards zero; with positive inputs it's floor.
        $q = (int) Math::div($a, $b, 0);

        return max(0, $q);
    }

    /**
     * ceil(a / b) for positive decimals (returns int).
     */
    private static function ceilPosDiv(string $a, string $b): int
    {
        $q = (int) Math::div($a, $b, 0);               // floor
        $prod = Math::mul((string) $q, $b, self::SCALE);
        if (Math::lt($prod, $a, self::SCALE)) {
            return $q + 1;
        }

        return $q;
    }

    /**
     * Step-ratio accessor that clamps the index to the last available value.
     * Accepts negative indices by clamping to 0.
     *
     * @param  array<int,mixed>  $values
     */
    private static function returnLadderedValue(array $values, int $index)
    {
        if (empty($values)) {
            throw new \InvalidArgumentException('Multipliers array must not be empty.');
        }

        $i = $index < 0 ? 0 : $index;

        return $values[min($i, count($values) - 1)];
    }

    /**
     * Converts a percentage value (e.g. "0.36" for 0.36%) into a decimal string with SCALE precision.
     * Enforces numeric input and p >= 0.
     */
    private static function pctToDecimal(string $pct, string $label): string
    {
        if (! is_numeric($pct)) {
            throw new \InvalidArgumentException("{$label} must be numeric.");
        }
        $p = Math::div($pct, '100', self::SCALE);
        if (Math::lt($p, '0', self::SCALE)) {
            throw new \InvalidArgumentException("{$label} must be >= 0.");
        }

        return $p;
    }
}
