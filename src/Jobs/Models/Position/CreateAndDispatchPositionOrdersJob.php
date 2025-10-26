<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Exception;
use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\Martingalian;
use Throwable;

final class CreateAndDispatchPositionOrdersJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function startOrFail()
    {
        $exists = Position::query()
            ->opened()
            ->where('exchange_symbol_id', $this->position->exchange_symbol_id)
            ->where('id', '<>', $this->position->id)
            ->exists();

        if ($exists) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        if (! $this->position->margin || ! $this->position->leverage) {
            throw new Exception('Position must have both margin and leverage filled, aborting.');
        }

        $exchangeSymbol = $this->position->exchangeSymbol;

        $markPriceResponse = $exchangeSymbol->apiQueryMarkPrice();
        $markPrice = (float) $markPriceResponse->result['mark_price'];

        if (! $markPrice || $markPrice <= 0) {
            throw new Exception('Invalid mark price received from exchange.');
        }

        $scale = 16;

        // ---- MARKET ORDER ----
        $notional = bcmul((string) $this->position->margin, (string) $this->position->leverage, $scale);
        $divider = get_market_order_amount_divider($this->position->total_limit_orders ?? 0);
        $marketAmount = bcdiv($notional, (string) $divider, $scale);
        $marketQty = $exchangeSymbol->getQuantityForAmount($marketAmount);

        $side = match ($this->position->direction) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            default => throw new Exception('Invalid position direction. Must be LONG or SHORT.'),
        };

        $marketOrder = $this->position->orders()->create([
            'type' => 'MARKET',
            'status' => 'NEW',
            'side' => $side,
            'client_order_id' => Str::uuid()->toString(),
            'position_side' => $this->position->direction,
            'quantity' => $marketQty,
            'price' => api_format_price((string) $markPrice, $exchangeSymbol),
        ]);

        $this->position->logApplicationEvent(
            "[Attempting] MARKET order [{$marketOrder->id}] (Price: {$marketOrder->price}, Qty: {$marketOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        $marketOrder->apiPlace();
        $marketOrder->apiSync();

        $marketOrder->updateSaving([
            'reference_price' => $marketOrder->price,
            'reference_quantity' => $marketOrder->quantity,
            'reference_status' => $marketOrder->status,
        ]);

        if ($marketOrder->status !== 'FILLED') {
            throw new Exception('Market order was not on status FILLED after being placed. Aborting position dispatch.');
        }

        $this->position->logApplicationEvent(
            "[Completed] MARKET order [{$marketOrder->id}] successfully placed (Price: {$marketOrder->price}, Qty: {$marketOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        $marketOrder->logApplicationEvent(
            "Order [{$marketOrder->id}] successfully placed (Price: {$marketOrder->price}, Qty: {$marketOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        // Track initial filled quantity on the position (you adjust later via WAP logic on fills).
        $this->position->updateSaving(['quantity' => $marketOrder->quantity]);

        // ---- LIMIT LADDER (NOTIONAL-BASED) ----
        $referencePrice = (string) $markPrice;

        $gapPercent = match ($this->position->direction) {
            'LONG' => $this->position->exchangeSymbol->percentage_gap_long,
            'SHORT' => $this->position->exchangeSymbol->percentage_gap_short,
            default => throw new Exception('Invalid position direction. Must be LONG or SHORT.'),
        };
        $gapDecimal = $gapPercent / 100;

        $marketNotional = bcmul((string) $marketOrder->quantity, (string) $marketOrder->price, $scale);
        $limitsBudget = bcsub($notional, $marketNotional, $scale);

        // Pre-compute rung prices with tick-size formatting.
        $prices = [];
        for ($i = 1; $i <= $this->position->total_limit_orders; $i++) {
            $factor = (string) ($gapDecimal * $i);
            $raw = $this->position->direction === 'LONG'
                ? bcmul($referencePrice, bcsub('1', $factor, $scale), $scale)
                : bcmul($referencePrice, bcadd('1', $factor, $scale), $scale);
            $prices[] = api_format_price($raw, $exchangeSymbol);
        }

        // Martingale notional weights: 1/2^N, 1/2^(N-1), ..., 1/2.
        $N = (int) $this->position->total_limit_orders;
        $weights = [];
        $w = bcdiv('1', bcpow('2', (string) $N, $scale), $scale); // 1 / 2^N
        for ($i = 1; $i <= $N; $i++) {
            $weights[] = $w;
            $w = bcmul($w, '2', $scale); // next rung doubles.
        }

        $sumW = '0';
        foreach ($weights as $wi) {
            $sumW = bcadd($sumW, $wi, $scale);
        }

        // Plan (price, qty) pairs.
        $planned = [];
        for ($i = 0; $i < count($prices); $i++) {
            $share = bcdiv($weights[$i], $sumW, $scale);
            $targetNotional = bcmul($limitsBudget, $share, $scale);
            $rawQty = bcdiv($targetNotional, $prices[$i], $scale);
            $limitQty = api_format_quantity($rawQty, $exchangeSymbol);
            $planned[] = [$prices[$i], $limitQty];
        }

        // Fit rounding remainder into last rung if needed.
        $spent = '0';
        foreach ($planned as [$p, $q]) {
            $spent = bcadd($spent, bcmul($q, $p, $scale), $scale);
        }
        $delta = bcsub($limitsBudget, $spent, $scale);
        if (bccomp($delta, '0', $scale) !== 0) {
            $last = count($planned) - 1;
            [$pLast, $qLast] = $planned[$last];
            $qAdj = bcadd($qLast, bcdiv($delta, $pLast, $scale), $scale);
            if (bccomp($qAdj, '0', $scale) === -1) {
                $qAdj = '0';
            }
            $planned[$last] = [$pLast, api_format_quantity($qAdj, $exchangeSymbol)];
        }

        // Place limits.
        foreach ($planned as $i => [$limitPrice, $limitQty]) {
            $limitOrder = $this->position->orders()->create([
                'type' => 'LIMIT',
                'status' => 'NEW',
                'side' => $side,
                'client_order_id' => Str::uuid()->toString(),
                'position_side' => $this->position->direction,
                'quantity' => $limitQty,
                'price' => $limitPrice,
            ]);

            $limitOrder->apiPlace();
            $limitOrder->apiSync();

            $limitOrder->updateSaving([
                'reference_price' => $limitOrder->price,
                'reference_quantity' => $limitOrder->quantity,
                'reference_status' => $limitOrder->status,
            ]);

            $this->position->logApplicationEvent(
                "LIMIT order [{$limitOrder->id}] successfully placed (Price: {$limitOrder->price}, Qty: {$limitOrder->quantity}).",
                self::class,
                __FUNCTION__
            );
            $limitOrder->logApplicationEvent(
                "Order [{$limitOrder->id}] successfully placed (Price: {$limitOrder->price}, Qty: {$limitOrder->quantity}).",
                self::class,
                __FUNCTION__
            );
        }

        // ---- PROFIT ORDER (initial; later adjusted by WAP logic on fills) ----
        $profitSide = $this->position->direction === 'LONG' ? 'SELL' : 'BUY';
        $profitChange = (float) $referencePrice * ($this->position->profit_percentage / 100);

        $profitPrice = $this->position->direction === 'LONG'
            ? (float) $referencePrice + $profitChange
            : (float) $referencePrice - $profitChange;

        $profitPriceFormatted = api_format_price((string) $profitPrice, $exchangeSymbol);
        // Use current position quantity (initially equals market fill).
        $profitQtyFormatted = api_format_quantity((string) $this->position->quantity, $exchangeSymbol);

        $profitOrder = $this->position->orders()->create([
            'type' => 'PROFIT-LIMIT',
            'status' => 'NEW',
            'side' => $profitSide,
            'client_order_id' => Str::uuid()->toString(),
            'position_side' => $this->position->direction,
            'quantity' => $profitQtyFormatted,
            'price' => $profitPriceFormatted,
        ]);

        $profitOrder->apiPlace();
        $profitOrder->apiSync();

        $profitOrder->updateSaving([
            'reference_price' => $profitOrder->price,
            'reference_quantity' => $profitOrder->quantity,
            'reference_status' => $profitOrder->status,
        ]);

        $this->position->logApplicationEvent(
            "PROFIT order [{$profitOrder->id}] successfully placed (Price: {$profitOrder->price}, Qty: {$profitOrder->quantity}).",
            self::class,
            __FUNCTION__
        );
        $profitOrder->logApplicationEvent(
            "PROFIT order [{$profitOrder->id}] successfully placed (Price: {$profitOrder->price}, Qty: {$profitOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        // ---- STOP-MARKET (initial anchor at last limit price) ----
        $stopSide = $profitOrder->side;

        $anchor = $this->position->lastLimitOrder()->price;

        $rawStopPrice = $this->calculateStopLossPrice(
            $anchor,
            $this->position->account->stop_market_initial_percentage,
            $this->position->direction
        );

        $stopPrice = api_format_price($rawStopPrice, $exchangeSymbol);
        $stopQuantity = $profitOrder->quantity;

        $stopLossOrder = $this->position->orders()->create([
            'type' => 'STOP-MARKET',
            'status' => 'NEW',
            'side' => $stopSide,
            'client_order_id' => Str::uuid()->toString(),
            'position_side' => $this->position->direction,
            'quantity' => (string) $stopQuantity,
            'price' => (string) $stopPrice,
        ]);

        $stopLossOrder->apiPlace();
        $stopLossOrder->apiSync();

        $stopLossOrder->updateSaving([
            'reference_price' => $stopLossOrder->price,
            'reference_quantity' => $stopLossOrder->quantity,
            'reference_status' => $stopLossOrder->status,
        ]);

        $this->position->logApplicationEvent(
            "STOP-MARKET order [{$stopLossOrder->id}] successfully placed (Price: {$stopLossOrder->price}, Qty: {$stopLossOrder->quantity}).",
            self::class,
            __FUNCTION__
        );
        $stopLossOrder->logApplicationEvent(
            "Order successfully placed (Price: {$stopLossOrder->price}, Qty: {$stopLossOrder->quantity}).",
            self::class,
            __FUNCTION__
        );

        // ---- FINALIZE POSITION ----
        $this->position->updateSaving([
            'opening_price' => $marketOrder->price,
            'opened_at' => now(),
        ]);

        $this->position->updateToActive();

        return [
            'message' => "Position orders created and placed for {$this->position->parsed_trading_pair}.",
            'attributes' => format_model_attributes($this->position),
        ];
    }

    public function calculateStopLossPrice($referencePrice, $percent, $direction): string
    {
        $scale = 16;
        $pct = bcdiv((string) $percent, '100', $scale);

        if (mb_strtoupper($direction) === 'SHORT') {
            $mult = bcadd('1', $pct, $scale);
        } else {
            $mult = bcsub('1', $pct, $scale);
        }

        return bcmul((string) $referencePrice, $mult, $scale);
    }

    public function resolveException(Throwable $e)
    {
        Martingalian::notifyAdmins(
            message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} creation/dispatch error - {$e->getMessage()}",
            title: '['.class_basename(self::class).'] - Error',
            deliveryGroup: 'exceptions'
        );
    }
}
