<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\TradeConfiguration\HasGetters;
use Martingalian\Core\Concerns\TradeConfiguration\HasScopes;

final class TradeConfiguration extends BaseModel
{
    use HasFactory;
    use HasGetters;
    use HasScopes;

    protected $table = 'trade_configuration';

    protected $casts = [
        'is_default' => 'boolean',
        'disable_exchange_symbol_from_negative_pnl_position' => 'boolean',

        'indicator_timeframes' => 'array',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\TradeConfigurationFactory::new();
    }
}
