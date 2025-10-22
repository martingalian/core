<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Position;

use Martingalian\Core\Models\ApiSnapshot;
use RuntimeException;

trait HasGetters
{
    /**
     * Return non-cancelled/expired LIMIT orders on the same side.
     * Safe: always returns a Collection (possibly empty).
     */
    public function limitOrders()
    {
        return $this->orders()
            ->where('type', 'LIMIT')
            ->where('position_side', $this->direction)
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED'])
            ->get();
    }

    /**
     * Filled LIMIT and MARKET orders for WAP.
     * Safe: always returns a Collection (possibly empty).
     */
    public function filledOrdersForWAP()
    {
        return $this->orders()
            ->whereIn('type', ['LIMIT', 'MARKET'])
            ->where('position_side', $this->direction)
            ->where('status', 'FILLED')
            ->get();
    }

    /**
     * LIMIT and MARKET (non-cancelled/expired) for WAP.
     * Safe: always returns a Collection (possibly empty).
     */
    public function ordersForWAP()
    {
        return $this->orders()
            ->whereIn('type', ['LIMIT', 'MARKET'])
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED'])
            ->where('position_side', $this->direction)
            ->get();
    }

    /**
     * LONG <-> SHORT. Throws only if direction is invalid (this indicates data corruption).
     */
    public function oppositeDirection(): string
    {
        return match ($this->direction) {
            'LONG' => 'SHORT',
            'SHORT' => 'LONG',
            default => throw new RuntimeException('oppositeDirection() without a valid direction!'),
        };
    }

    // If total limit orders are filled for this position.
    public function allLimitOrdersFilled(): bool
    {
        return $this->totalLimitOrdersFilled() === $this->total_limit_orders;
    }

    /**
     * Total filled (not partially filled!) LIMIT orders on the same side.
     * Safe: returns integer count (>= 0).
     */
    public function totalLimitOrdersFilled()
    {
        return $this->orders()
            ->where('orders.type', 'LIMIT')
            ->where('orders.position_side', $this->direction)
            ->where('orders.status', 'FILLED')
            ->count();
    }

    /**
     * Last LIMIT order (by quantity desc) on the same side that still has a known exchange id and is active/filled.
     * Safe: may return null (callers MUST null-check or use null-safe operator).
     */
    public function lastLimitOrder()
    {
        return $this->orders()
            ->where('orders.type', 'LIMIT')
            ->whereNotNull('orders.exchange_order_id')
            ->where('orders.position_side', $this->direction)
            ->whereIn('orders.status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
            ->orderByDesc('orders.quantity')
            ->first();
    }

    /**
     * Latest MARKET order on the same side.
     * Safe: may return null.
     */
    public function marketOrder()
    {
        return $this->orders()
            ->where('orders.type', 'MARKET')
            ->where('orders.position_side', $this->direction)
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Latest STOP-MARKET order on the same side.
     * Safe: may return null.
     */
    public function stopMarketOrder()
    {
        return $this->orders()
            ->where('orders.type', 'STOP-MARKET')
            ->where('orders.position_side', $this->direction)
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Latest MARKET-CANCEL order on the same side.
     * Safe: may return null.
     */
    public function marketCancelOrder()
    {
        return $this->orders()
            ->where('orders.type', 'MARKET-CANCEL')
            ->where('orders.position_side', $this->direction)
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * Latest PROFIT order (LIMIT or MARKET) on the same side.
     * Safe: may return null.
     */
    public function profitOrder()
    {
        return $this->orders()
            ->whereIn('orders.type', ['PROFIT-LIMIT', 'PROFIT-MARKET'])
            ->where('orders.position_side', $this->direction)
            ->orderByDesc('orders.id')
            ->first();
    }

    /**
     * PnL using current mark vs entry (profit order price) * quantity on this side.
     * Safe: returns a STRING "0" if any datum is missing or quantity is zero.
     */
    public function pnl(): ?string
    {
        // Guard: if quantity is null/zero, return "0"
        if ($this->quantity === null || bccomp((string) $this->quantity, '0', 8) === 0) {
            return '0';
        }

        // Use profit order price as entry; if missing, return "0"
        $entryPrice = (string) ($this->profitOrder()?->price ?? '');
        if ($entryPrice === '') {
            return '0';
        }

        // Guard: mark price and exchangeSymbol presence
        $markPrice = $this->exchangeSymbol?->mark_price;
        if ($markPrice === null || $markPrice === '') {
            return '0';
        }

        $markPrice = (string) $markPrice;
        $quantity = (string) $this->quantity;
        $side = $this->direction;

        $diff = $side === 'LONG'
            ? bcsub($markPrice, $entryPrice, 16)
            : bcsub($entryPrice, $markPrice, 16);

        $pnl = bcmul($diff, $quantity, 16);

        // api_format_price returns a numeric string; keep it as string
        return (string) api_format_price($pnl, $this->exchangeSymbol);
    }

    /**
     * 0..10 bucket of progress from first profit price to last limit.
     * Safe: returns 0 if anything is missing.
     */
    public function alphaPath(): ?int
    {
        $fraction = $this->alphaPathFraction(8);
        if ($fraction === null) {
            return 0;
        }

        $percent = (float) bcmul($fraction, '100', 2);
        $bucket = (int) ceil($percent / 10.0);

        if ($bucket < 0) {
            $bucket = 0;
        }
        if ($bucket > 10) {
            $bucket = 10;
        }

        return $bucket;
    }

    /**
     * "xx.x" percentage of alpha path (string).
     * Safe: returns "0.0" if anything is missing.
     */
    public function alphaPathPercent(): ?string
    {
        $fraction = $this->alphaPathFraction(6);
        if ($fraction === null) {
            return '0.0';
        }

        return number_format((float) bcmul($fraction, '100', 2), 1, '.', '');
    }

    /**
     * Percentage from entry to next pending LIMIT.
     * Safe: returns 0.0 if anything is missing or degenerate.
     */
    public function percentageToReachLimitOrder(): ?float
    {
        $start = $this->profitOrder()?->price;
        $target = $this->nextPendingLimitOrderPrice();
        $curr = $this->exchangeSymbol?->mark_price;

        if ($start === null || $target === null || $curr === null) {
            return 0.0;
        }

        $start = (string) $start;
        $target = (string) $target;
        $curr = (string) $curr;

        if (bccomp($target, $start, 16) > 0) {
            $den = bcsub($target, $start, 16);
            $num = bcsub($curr, $start, 16);
        } else {
            $den = bcsub($start, $target, 16);
            $num = bcsub($start, $curr, 16);
        }

        if (bccomp($den, '0', 16) === 0) {
            return 0.0;
        }

        $fraction = bcdiv($num, $den, 8);

        if (bccomp($fraction, '0', 8) < 0) {
            $fraction = '0';
        } elseif (bccomp($fraction, '1', 8) > 0) {
            $fraction = '1';
        }

        return (float) bcmul($fraction, '100', 2);
    }

    /**
     * Closeness % of current price between entry and next LIMIT.
     * Safe: returns 0.0 if anything is missing or degenerate.
     */
    public function closenessToNextLimitPercent(): ?float
    {
        $start = $this->profitOrder()?->price;
        $end = $this->nextPendingLimitOrderPrice();
        $curr = $this->exchangeSymbol?->mark_price;

        if ($start === null || $end === null || $curr === null) {
            return 0.0;
        }

        $start = (string) $start;
        $end = (string) $end;
        $curr = (string) $curr;

        if (bccomp($end, $start, 16) > 0) {
            if (bccomp($curr, $start, 16) <= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, 16) >= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($curr, $start, 16);
                $den = bcsub($end, $start, 16);
                if (bccomp($den, '0', 16) === 0) {
                    return 0.0;
                }
                $fraction = bcdiv($num, $den, 8);
            }
        } else {
            if (bccomp($curr, $start, 16) >= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, 16) <= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($start, $curr, 16);
                $den = bcsub($start, $end, 16);
                if (bccomp($den, '0', 16) === 0) {
                    return 0.0;
                }
                $fraction = bcdiv($num, $den, 8);
            }
        }

        return (float) bcmul($fraction, '100', 2);
    }

    /**
     * Price of the next pending LIMIT after the last filled id.
     * Safe: may return null; callers must guard (we do above).
     */
    public function nextPendingLimitOrderPrice(): ?string
    {
        $lastFilled = $this->orders()
            ->where('position_side', $this->direction)
            ->where('status', 'FILLED')
            ->orderByDesc('id')
            ->first();

        $lastId = $lastFilled?->id ?? 0;

        $next = $this->orders()
            ->where('position_side', $this->direction)
            ->where('type', 'LIMIT')
            ->whereNotIn('status', ['FILLED', 'CANCELLED', 'EXPIRED'])
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->first();

        return $next?->price ? (string) $next->price : null;
    }

    /**
     * Notional size (qty * mark). Returns a rounded string.
     * Safe: returns "0" if any datum is missing.
     */
    public function size(): ?string
    {
        if ($this->quantity === null || $this->exchangeSymbol?->mark_price === null) {
            return '0';
        }

        $quantity = (string) $this->quantity;
        $markPrice = (string) $this->exchangeSymbol->mark_price;

        $notional = bcmul($quantity, $markPrice, 16);

        $openPositions = ApiSnapshot::getFrom($this->account, 'account-positions');

        if (is_array($openPositions) && array_key_exists($this->parsed_trading_pair, $openPositions)) {
            return $openPositions[$this->parsed_trading_pair]['notional'];
        }

        return 0;
    }

    /**
     * Core fraction [0..1] of (current - start) / (end - start) respecting direction.
     * Safe: returns "0" if any of start/end/current is missing or degenerate.
     */
    private function alphaPathFraction(int $scale): ?string
    {
        // Start is the first profit price on this position
        $start = $this->first_profit_price;

        // End is the last LIMIT order price
        $lastLimit = $this->lastLimitOrder();              // may be null (SAFE)
        $end = $lastLimit?->price;                   // null-safe

        // Current mark price
        $curr = $this->exchangeSymbol?->mark_price;

        // If any required piece is missing, return "0"
        if ($start === null || $end === null || $curr === null) {
            return '0';
        }

        $start = (string) $start;
        $end = (string) $end;
        $curr = (string) $curr;

        // Compute fraction depending on whether end < start or end >= start
        if (bccomp($end, $start, 16) < 0) {
            if (bccomp($curr, $start, 16) >= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, 16) <= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($start, $curr, 16);
                $den = bcsub($start, $end, 16);
                if (bccomp($den, '0', 16) === 0) {
                    return '0';
                }
                $fraction = bcdiv($num, $den, $scale);
            }
        } else {
            if (bccomp($curr, $start, 16) <= 0) {
                $fraction = '0';
            } elseif (bccomp($curr, $end, 16) >= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($curr, $start, 16);
                $den = bcsub($end, $start, 16);
                if (bccomp($den, '0', 16) === 0) {
                    return '0';
                }
                $fraction = bcdiv($num, $den, $scale);
            }
        }

        // Clamp to [0,1]
        if (bccomp($fraction, '0', $scale) < 0) {
            $fraction = '0';
        } elseif (bccomp($fraction, '1', $scale) > 0) {
            $fraction = '1';
        }

        return $fraction;
    }

    /**
     * Last filled LIMIT/MARKET price on this side; falls back to opening_price.
     * Safe: may return null (use only where optional).
     */
    private function lastFilledLimitOrMarketPrice(): ?string
    {
        $base = $this->orders()
            ->where('position_side', $this->direction)
            ->where('status', 'FILLED');

        $lastFilledLimit = (clone $base)
            ->where('type', 'LIMIT')
            ->orderByDesc('id')
            ->first();

        if ($lastFilledLimit) {
            return (string) ($lastFilledLimit->avg_price ?? $lastFilledLimit->price);
        }

        $lastFilledMarket = (clone $base)
            ->where('type', 'MARKET')
            ->orderByDesc('id')
            ->first();

        if ($lastFilledMarket) {
            return (string) ($lastFilledMarket->avg_price ?? $lastFilledMarket->price);
        }

        return $this->opening_price !== null ? (string) $this->opening_price : null;
    }
}
