<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

trait HasStatuses
{
    public function isTradeable()
    {
        return $this->is_tradeable === true &&
               ($this->tradeable_at === null || $this->tradeable_at->isFuture());
    }
}
