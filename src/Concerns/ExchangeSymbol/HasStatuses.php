<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

trait HasStatuses
{
    /**
     * Check if this exchange symbol is valid for trading.
     * Mirrors the scopeTradeable logic for instance-level checks.
     */
    public function isTradeable(): bool
    {
        // Must have TAAPI indicator data
        if (! ($this->api_statuses['has_taapi_data'] ?? false)) {
            return false;
        }

        // Must not be auto-disabled
        if ($this->auto_disabled) {
            return false;
        }

        // Must not be manually blocked (null or true is allowed, false blocks)
        if ($this->is_manually_enabled === false) {
            return false;
        }

        // Must have a concluded direction
        if ($this->direction === null) {
            return false;
        }

        // Must not be in cooldown period (tradeable_at null or in the past)
        if ($this->tradeable_at !== null && $this->tradeable_at->isFuture()) {
            return false;
        }

        // Must have a valid mark price
        if ($this->mark_price === null || $this->mark_price <= 0) {
            return false;
        }

        return true;
    }
}
