<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use InvalidArgumentException;
use Martingalian\Core\Martingalian\Martingalian;
use Martingalian\Core\Support\Math;

trait HasTradingComputations
{
    public function getQuantityForAmount($amount, bool $respectMinNotional = true): string
    {
        $price = api_format_price((string) $this->mark_price, $this);

        if (Math::lte($price, '0')) {
            throw new InvalidArgumentException("Invalid or missing mark_price for {$this->parsed_trading_pair}.");
        }

        $rawQty = Math::div((string) $amount, $price);
        $qty = api_format_quantity($rawQty, $this);

        if ($respectMinNotional) {
            $notional = Math::mul($qty, $price);

            if (! Martingalian::meetsMinNotional($this, $notional)) {
                return '0';
            }
        }

        return $qty === '' ? '0' : $qty;
    }

    public function getAmountForQuantity(string|float $quantity): string
    {
        $price = api_format_price((string) $this->mark_price, $this);

        if (Math::lte($price, '0')) {
            throw new InvalidArgumentException("Invalid or missing mark_price for {$this->parsed_trading_pair}.");
        }

        $qty = api_format_quantity((string) $quantity, $this);
        $amount = Math::mul($qty, $price);

        return remove_trailing_zeros($amount);
    }

    public function isQuantityBelowMinNotional(string|float $quantity): bool
    {
        $price = api_format_price((string) $this->mark_price, $this);

        if (Math::lte($price, '0')) {
            throw new InvalidArgumentException("Invalid or missing mark_price for {$this->parsed_trading_pair}.");
        }

        $qty = api_format_quantity((string) $quantity, $this);
        $notional = Math::mul($qty, $price);

        return ! Martingalian::meetsMinNotional($this, $notional);
    }
}
