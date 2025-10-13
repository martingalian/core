<?php

namespace Martingalian\Core\Concerns\ExchangeSymbol;

trait HasTradingComputations
{
    public function getQuantityForAmount($amount, bool $respectMinNotional = true): string
    {
        $scale = 18;

        $price = api_format_price((string) $this->mark_price, $this);
        if (bccomp($price, '0', $scale) <= 0) {
            throw new \InvalidArgumentException("Invalid or missing mark price for {$this->symbol}.");
        }

        $rawQty = bcdiv((string) $amount, $price, $scale);
        $qty = api_format_quantity($rawQty, $this);

        if ($respectMinNotional) {
            $notional = bcmul($qty, $price, $scale);
            if (bccomp($notional, (string) $this->min_notional, $scale) < 0) {
                return '0';
            }
        }

        return $qty === '' ? '0' : $qty;
    }

    public function getAmountForQuantity(float $quantity): string
    {
        $scale = 18;

        $price = api_format_price((string) $this->mark_price, $this);
        if (bccomp($price, '0', $scale) <= 0) {
            throw new \InvalidArgumentException("Invalid or missing mark price for {$this->symbol}.");
        }

        $qty = api_format_quantity((string) $quantity, $this);
        $amount = bcmul($qty, $price, $scale);

        return remove_trailing_zeros($amount);
    }

    public function isQuantityBelowMinNotional(float $quantity): bool
    {
        $scale = 18;

        $price = api_format_price((string) $this->mark_price, $this);
        if (bccomp($price, '0', $scale) <= 0) {
            throw new \InvalidArgumentException("Invalid or missing mark price for {$this->symbol}.");
        }

        $qty = api_format_quantity((string) $quantity, $this);
        $notional = bcmul($qty, $price, $scale);

        return bccomp($notional, (string) $this->min_notional, $scale) < 0;
    }
}
