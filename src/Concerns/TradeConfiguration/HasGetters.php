<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\TradeConfiguration;

use Martingalian\Core\Models\TradeConfiguration;

trait HasGetters
{
    public static function getDefault()
    {
        return TradeConfiguration::default()->first();
    }
}
