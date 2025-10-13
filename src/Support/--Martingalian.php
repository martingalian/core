<?php

namespace Martingalian\Core\Support;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\LeverageBracket;
use Martingalian\Core\Models\Position;

/**
 * Martingalian — Unbounded ladder model
 *
 * Notes:
 * - MARKET notional = margin × leverage; MARKET qty = notional ÷ basis price.
 * - LIMIT ladder is unbounded; rung qtys are chained from market qty via step ratios.
 * - Optional Gap% override (UI) supported in calculateLimitOrdersData().
 * - TP order now ALWAYS anchors on the provided reference price.
 */
class Martingalian
{
    /**
     * Global decimal scale used across money/size math.
     * Keep aligned with Math::DEFAULT_SCALE.
     */
public const SCALE = 16;

    /**
     * Extra safety applied to the unit-leverage worst-case constant K
     * when deriving feasible leverage intervals from brackets.
     *
     * Example: '0.003' == 0.3%
     */
public const BRACKET_HEADROOM_PCT = '0.003';

    /**
     * Accumulative PnL helper.
     * Computes the new weighted-average price after adding a fill, and the
     * UNREALIZED PnL at the moment of that fill using the fill price as mark.
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

    // mark == fill price
    $pnlRaw = ($dir === 'LONG')
        ? Math::mul(Math::sub($P1, $avg, $scale), $cumQty, $scale)
        : Math::mul(Math::sub($avg, $P1, $scale), $cumQty, $scale);

    $out = [
        'cum_qty'   => $cumQty,
        'avg_price' => $avg,
        'pnl'       => $pnlRaw,
    ];
    if ($exchangeSymbol) {
        $out['cum_qty']   = api_format_quantity($cumQty, $exchangeSymbol);
        $out['avg_price'] = api_format_price($avg, $exchangeSymbol);
        $out['pnl']       = api_format_price($pnlRaw, $exchangeSymbol);
    }

    return $out;
}

    /* -----------------------------------------------------------------
     |  Core helpers (compatible with v1)
     | ----------------------------------------------------------------- */

public static function canOpenShorts(Account $account): bool
{
    $openShorts = $account->positions()->opened()->onlyShorts()->get();

    foreach ($openShorts as $position) {
        if ($position->allLimitOrdersFilled()) {
            return false;
        }
    }

    return true;
}

public static function canOpenLongs(Account $account): bool
{
    $openLongs = $account->positions()->opened()->onlyLongs()->get();

    foreach ($openLongs as $position) {
        if ($position->allLimitOrdersFilled()) {
            return false;
        }
    }

    return true;
}

    /**
     * Compute notional = margin × leverage (string math).
     */
public static function notional($margin, $leverage): string
{
    return Math::mul($margin, $leverage, self::SCALE);
}

    /**
     * Compute MARKET leg from the market margin and leverage.
     *
     * - market_notional = market_margin × leverage
     * - market_qty      = market_notional ÷ basis_price
     * - basis_price     = $referencePrice when provided; else symbol->mark_price; else symbol->last_price
     *
     * @param  string|int|float       $marketMargin  Quote margin for MARKET leg
     * @param  int|string             $leverage
     * @param  ExchangeSymbol         $exchangeSymbol
     * @param  string|int|float|null  $referencePrice
     * @return array{
     *   price:string,
     *   quantity:string,
     *   amount:string,
     *   margin:string,
     *   notional:string
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

    $marketNotional = self::notional($margin, $L);

    // basis price
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

    $qtyRaw = Math::div($marketNotional, $basisRaw, $scale);
    if ($qtyRaw === null) {
        throw new \RuntimeException("Division failed computing market qty (notional={$marketNotional}, basis={$basisRaw}).");
    }

    $qtyFormatted    = api_format_quantity($qtyRaw, $exchangeSymbol);
    $amountFormatted = api_format_price($marketNotional, $exchangeSymbol);
    $priceFormatted  = api_format_price($basisRaw, $exchangeSymbol);
    $marginFormatted = api_format_price($margin, $exchangeSymbol);

    return [
        'price'    => $priceFormatted,
        'quantity' => $qtyFormatted,
        'amount'   => $amountFormatted, // equals formatted notional
        'margin'   => $marginFormatted,
        'notional' => $amountFormatted,
    ];
}

    /**
     * LIMIT ladder (unbounded):
     * - Prices built from referencePrice and side-specific gap% (or override when provided).
     * - Quantities chained from marketOrderQty using step ratios (N−1 provided; last repeats).
     * - Prices clamped to symbol min/max; warnings collected.
     * - Rungs that format to zero quantity are dropped.
     *
     * @param  int|string              $totalLimitOrders  N rungs
     * @param  'LONG'|'SHORT'          $direction
     * @param  string|int|float        $referencePrice
     * @param  string|int|float        $marketOrderQty
     * @param  ExchangeSymbol          $exchangeSymbol
     * @param  ?array                  $limitQuantityMultipliers  step ratios (N−1 ok)
     * @param  string|int|float|null   $gapPercentOverride        optional Gap% from UI
     * @param  bool                    $withMeta
     * @return array<int,array{price:string,quantity:string,amount:string}>|array{ladder:array<int,array{price:string,quantity:string,amount:string}>,__meta:array{activeMultipliers:array<int,string>,warnings:array<int,array<string,mixed>>}}
     */
public static function calculateLimitOrdersData(
    $totalLimitOrders,
    $direction,
    $referencePrice,
    $marketOrderQty,
    ExchangeSymbol $exchangeSymbol,
    ?array $limitQuantityMultipliers = null,
    $gapPercentOverride = null,
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

    // Gap% precedence: override > symbol default
    $gapPercent = null;
    if ($gapPercentOverride !== null && is_numeric((string) $gapPercentOverride)) {
        $gapPercent = (float) $gapPercentOverride;
    } else {
        $gapPercent = $direction === 'LONG'
            ? $exchangeSymbol->percentage_gap_long
            : $exchangeSymbol->percentage_gap_short;
    }

    if (! is_numeric((string) $gapPercent) || (float) $gapPercent < 0) {
        throw new \RuntimeException('percentage_gap_(long|short) must be a non-negative number on the symbol.');
    }
    $gapDecimal = Math::div((string) $gapPercent, '100', $scale);

    // Multipliers precedence: param > symbol default > [2,2,2,2]
    $mArray = $limitQuantityMultipliers
        ?? ($exchangeSymbol->limit_quantity_multipliers ?? [2, 2, 2, 2]);

    if (! is_array($mArray) || empty($mArray)) {
        throw new \RuntimeException('limit_quantity_multipliers must be a non-empty array.');
    }

    $warnings        = [];
    $usedMultipliers = [];
    $prices          = [];
    $rungs           = [];

    // Build rung prices (with clamp/warnings)
    for ($i = 1; $i <= $N; $i++) {
        $factor = Math::mul($gapDecimal, (string) $i, $scale);

        $raw = ($direction === 'LONG')
            ? Math::mul($ref, Math::sub('1', $factor, $scale), $scale)
            : Math::mul($ref, Math::add('1', $factor, $scale), $scale);

        $clamped = false;
        $origRaw = $raw;

        if (isset($exchangeSymbol->min_price) && is_numeric($exchangeSymbol->min_price)) {
            if (Math::lt($raw, (string) $exchangeSymbol->min_price, $scale)) {
                $raw     = (string) $exchangeSymbol->min_price;
                $clamped = true;
            }
        }
        if (isset($exchangeSymbol->max_price) && is_numeric($exchangeSymbol->max_price)) {
            if (Math::gt($raw, (string) $exchangeSymbol->max_price, $scale)) {
                $raw     = (string) $exchangeSymbol->max_price;
                $clamped = true;
            }
        }

        if ($clamped) {
            $warnings[] = [
                'type'     => 'price_clamped',
                'rung'     => $i,
                'original' => $origRaw,
                'clamped'  => $raw,
            ];
        }

        $prices[] = api_format_price($raw, $exchangeSymbol);
    }

    // Quantities chained from market qty; last ratio repeats.
    $prev = $marketQ;
    for ($i = 0; $i < $N; $i++) {
        $mi = returnLadderedValue($mArray, $i);
        if (! is_numeric($mi) || (float) $mi <= 0) {
            throw new \RuntimeException('limit_quantity_multipliers must contain positive numeric values');
        }
        $usedMultipliers[] = (string) $mi;

        $prev   = Math::mul($prev, (string) $mi, $scale);
        $qtyRaw = $prev;

        $qtyFmt = api_format_quantity($qtyRaw, $exchangeSymbol);

        if (Math::lte($qtyFmt, '0', $scale)) {
            $warnings[] = ['type' => 'rung_dropped_zero_qty', 'rung' => $i + 1];
            continue;
        }

        $amountRaw = Math::mul((string) $prices[$i], $qtyFmt, $scale);

        $rungs[] = [
            'price'    => $prices[$i],
            'quantity' => $qtyFmt,
            'amount'   => api_format_price($amountRaw, $exchangeSymbol),
        ];
    }

    if ($withMeta) {
        return [
            'ladder' => $rungs,
            '__meta' => [
                'activeMultipliers' => $usedMultipliers,
                'warnings'          => $warnings,
            ],
        ];
    }

    return $rungs;
}

    /**
     * Orchestrator for the unbounded model.
     * (kept as before; updated to match new calculateLimitOrdersData signature)
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

    $ratios = $limitQuantityMultipliers
        ?? ($exchangeSymbol->limit_quantity_multipliers ?? [2, 2, 2, 2]);

    if (! is_array($ratios) || empty($ratios)) {
        throw new \RuntimeException('limit_quantity_multipliers must be a non-empty array.');
    }

    // --- K computation (unchanged) ---
    $Q0_unit = Math::div($M0, $ref, $scale);

    $gapPercent = $direction === 'LONG'
        ? $exchangeSymbol->percentage_gap_long
        : $exchangeSymbol->percentage_gap_short;
    $gapDecimal = Math::div((string) $gapPercent, '100', $scale);

    $pricesK  = [];
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
            $warningsK[] = ['type' => 'price_clamped', 'rung' => $i, 'original' => $orig, 'clamped' => $raw];
        }

        $pricesK[] = $raw;
    }

    $rawQtysUnit = [];
    $prev = $Q0_unit;
    $activeRatios = [];
    for ($i = 0; $i < $N; $i++) {
        $mi = returnLadderedValue($ratios, $i);
        if (! is_numeric($mi) || (float) $mi <= 0) {
            throw new \RuntimeException('limit_quantity_multipliers must contain positive numeric values');
        }
        $activeRatios[] = (string) $mi;
        $prev = Math::mul($prev, (string) $mi, $scale);
        $rawQtysUnit[$i] = $prev;
    }

    $A_lim_unit = '0';
    for ($i = 0; $i < $N; $i++) {
        $A_lim_unit = Math::add($A_lim_unit, Math::mul($pricesK[$i], $rawQtysUnit[$i], $scale), $scale);
    }

    $K_raw = Math::add($M0, $A_lim_unit, $scale);
    $h     = (string) self::BRACKET_HEADROOM_PCT;
    $K     = Math::mul($K_raw, Math::add('1', $h, $scale), $scale);

    if (Math::lte($K, '0', $scale)) {
        $chosenLev = 1;
        $levInfo = ['requestedCap' => (int) $leverageCap, 'chosen' => $chosenLev, 'reason' => 'no_feasible', 'bracket' => null];
    } else {
        $intervals = [];
        $brackets = LeverageBracket::query()
            ->where('exchange_symbol_id', (int) $exchangeSymbol->id)
            ->orderBy('notional_floor')
            ->get(['bracket','initial_leverage','notional_floor','notional_cap','maint_margin_ratio']);

        $bestL = 0;
        $bestBkt = null;
        foreach ($brackets as $b) {
            $floor = (string) $b->notional_floor;
            $cap   = (string) $b->notional_cap;
            $initL = (int) $b->initial_leverage;

            $Lmin = self::ceilPosDiv($floor, $K);
            $LmaxFromCap = self::floorPosDiv($cap, $K);
            $Lmax = min($LmaxFromCap, $initL, $leverageCap);

            $intervals[] = ['bracket' => $b->toArray(), 'Lmin' => $Lmin, 'Lmax' => $Lmax];

            if ($Lmin <= $Lmax && $Lmax > $bestL) {
                $bestL = $Lmax;
                $bestBkt = $b;
            }
        }

        if ($bestL < 1) {
            $chosenLev = 1;
            $levInfo = ['requestedCap' => (int) $leverageCap, 'chosen' => $chosenLev, 'reason' => 'no_feasible', 'bracket' => null];
        } else {
            $chosenLev = (int) $bestL;
            $levInfo = [
                'requestedCap' => (int) $leverageCap,
                'chosen'       => $chosenLev,
                'reason'       => ($chosenLev === $leverageCap ? 'target_ok_or_top_cap' : 'clamped_by_bracket'),
                'bracket'      => $bestBkt ? $bestBkt->toArray() : null,
            ];
        }
    }

    // Market leg at chosen L
    $market = self::calculateMarketOrderData($M0, $chosenLev, $exchangeSymbol, $referencePrice);

    $marketQty  = (string) $market['quantity'];
    $marketAmt  = (string) $market['amount'];
    $marketPrice = (string) $ref;
    $marketMarg = Math::div($marketAmt, (string) max(1, $chosenLev), $scale);

    // Ladder (no cap)
    $ladderPayload = self::calculateLimitOrdersData(
        $N,
        $direction,
        $ref,
        $marketQty,
        $exchangeSymbol,
        $ratios,
        null,
        true
    );

    $ladder = $ladderPayload['ladder'] ?? [];
    $warns  = $ladderPayload['__meta']['warnings'] ?? [];
    $active = $ladderPayload['__meta']['activeMultipliers'] ?? [];

    $A_lim = '0';
    foreach ($ladder as $row) {
        $A_lim = Math::add($A_lim, Math::mul((string) $row['price'], (string) $row['quantity'], $scale), $scale);
    }

    $A_mkt   = Math::mul($M0, (string) $chosenLev, $scale);
    $A_tot   = Math::add($A_mkt, $A_lim, $scale);
    $reqMarg = Math::div($A_tot, (string) max(1, $chosenLev), $scale);
    $blowUp  = Math::equal($A_mkt, '0', $scale) ? '0' : Math::div($A_tot, $A_mkt, $scale);

    $out = [
        'leverage' => $levInfo,
        'market_order' => [
            'price'    => api_format_price($marketPrice, $exchangeSymbol),
            'quantity' => api_format_quantity($marketQty, $exchangeSymbol),
            'amount'   => api_format_price($marketAmt, $exchangeSymbol),
            'margin'   => api_format_price($marketMarg, $exchangeSymbol),
        ],
        'limit_ladder' => $ladder,
        'totals' => [
            'market_notional'   => api_format_price($A_mkt, $exchangeSymbol),
            'limits_notional'   => api_format_price($A_lim, $exchangeSymbol),
            'total_notional'    => api_format_price($A_tot, $exchangeSymbol),
            'required_margin'   => api_format_price($reqMarg, $exchangeSymbol),
            'blow_up_factor'    => $blowUp,
        ],
    ];

    if ($withDiagnostics) {
        $out['diagnostics'] = [
            'K_unit'         => $K ?? '0',
            'headroom_pct'   => (string) self::BRACKET_HEADROOM_PCT,
            'intervals_considered' => $intervals ?? [],
            'active_ratios'  => $active,
            'warnings'       => array_merge($warningsK, $warns),
        ];
    }

    return $out;
}

    /* -----------------------------------------------------------------
     |  Orders
     | ----------------------------------------------------------------- */

    /**
     * Calculate Take Profit order (always anchored on $referencePrice).
     *
     * @return array{price:string, quantity:string, amount:string}
     */
public static function calculateProfitOrder(
    string $direction,
    $referencePrice,
    $profitPercent,
    $currentQty,
    ExchangeSymbol $exchangeSymbol
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

    $pct = (string) $profitPercent;
    if (! is_numeric($pct) || Math::lte($pct, '0', $scale)) {
        throw new \InvalidArgumentException("Profit percent must be > 0 (got: {$profitPercent}).");
    }

    $qty = (string) $currentQty;
    if (! is_numeric($qty) || Math::lt($qty, '0', $scale)) {
        throw new \InvalidArgumentException("Quantity must be >= 0 (got: {$currentQty}).");
    }

    $pctDecimal = Math::div($pct, '100', $scale);
    $profitDelta = Math::mul($ref, $pctDecimal, $scale);

    // Always anchor TP on the given reference price.
    $rawPrice = ($direction === 'LONG')
        ? Math::add($ref, $profitDelta, $scale)
        : Math::sub($ref, $profitDelta, $scale);

    if (Math::lte($rawPrice, '0', $scale)) {
        throw new \RuntimeException("Computed profit price <= 0 (ref={$ref}, pct={$pct}).");
    }

    $price    = api_format_price($rawPrice, $exchangeSymbol);
    $quantity = api_format_quantity($qty, $exchangeSymbol);
    $amount   = api_format_price(Math::mul($price, $quantity, $scale), $exchangeSymbol);

    return ['price' => $price, 'quantity' => $quantity, 'amount' => $amount];
}

    /**
     * Calculate STOP-MARKET trigger price and quantity.
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

    $pct = (string) $stopPercent;
    if (! is_numeric($pct) || Math::lte($pct, '0', $scale)) {
        throw new \InvalidArgumentException("Stop percent must be > 0 (got: {$stopPercent}).");
    }

    $qty = (string) $currentQty;
    if (! is_numeric($qty) || Math::lt($qty, '0', $scale)) {
        throw new \InvalidArgumentException("Quantity must be >= 0 (got: {$currentQty}).");
    }

    $pctDecimal = Math::div($pct, '100', $scale);

    $mult = ($direction === 'SHORT')
        ? Math::add('1', $pctDecimal, $scale)
        : Math::sub('1', $pctDecimal, $scale);

    $rawPrice = Math::mul($anchor, $mult, $scale);

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
        throw new \RuntimeException("Computed stop price <= 0 (anchor={$anchor}, pct={$pct}).");
    }

    $price    = api_format_price($rawPrice, $exchangeSymbol);
    $quantity = api_format_quantity($qty, $exchangeSymbol);
    $amount   = api_format_price(Math::mul($price, $quantity, $scale), $exchangeSymbol);

    return ['price' => $price, 'quantity' => $quantity, 'amount' => $amount];
}

public static function testingMode(): bool
{
    return (bool) config('martingalian.testing.enabled');
}

    /**
     * Calculate cumulative WAP per rung for the provided limit ladder.
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
        if (! is_numeric((string) $profitPercent)) {
            throw new \InvalidArgumentException('profitPercent must be numeric when provided.');
        }
        $p = Math::div((string) $profitPercent, '100', $scale);
        if (Math::lt($p, '0', $scale)) {
            throw new \InvalidArgumentException('profitPercent must be >= 0.');
        }
    }

    $out = [];
    $cumQty = '0';
    $cumNotional = '0';

    foreach ($limits as $idx => $row) {
        $price = isset($row['price']) ? (string) $row['price'] : null;
        $qty   = isset($row['quantity']) ? (string) $row['quantity'] : null;

        if ($price === null || $qty === null || ! is_numeric($price) || ! is_numeric($qty)) {
            throw new \InvalidArgumentException('Each limit row must have numeric "price" and "quantity".');
        }

        $cumQty      = Math::add($cumQty, $qty, $scale);
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
            'wap'  => $wap,
        ];
    }

    return $out;
}

public static function canOpenNewPositions(Account $account): bool
    {
        $opened = $account->positions()->opened();

if (! $account->user->is_active) {
    return false;
}
if (! $account->user->can_trade) {
    return false;
}
if (! $account->can_trade) {
    return false;
}

if ($opened->count() === 0) {
    return true;
}

        $shorts = $opened->onlyShorts()->get(['id','direction','total_limit_orders']);
        $totalShorts = $shorts->count();
        $
