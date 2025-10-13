<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\ExchangeSymbol\HasAccessors;
use Martingalian\Core\Concerns\ExchangeSymbol\HasScopes;
use Martingalian\Core\Concerns\ExchangeSymbol\HasStatuses;
use Martingalian\Core\Concerns\ExchangeSymbol\HasTradingComputations;
use Martingalian\Core\Concerns\ExchangeSymbol\InteractsWithApis;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class ExchangeSymbol extends BaseModel
{
    use HasAccessors;
    use HasDebuggable;
    use HasLoggable;
    use HasScopes;
    use HasStatuses;
    use HasTradingComputations;
    use InteractsWithApis;

    protected $casts = [
        'is_tradeable' => 'boolean',
        'is_active' => 'boolean',

        'symbol_information' => 'array',
        'leverage_brackets' => 'array',
        'indicators' => 'array',
        'limit_quantity_multipliers' => 'array',

        'mark_price_synced_at' => 'datetime',
        'indicators_synced_at' => 'datetime',
        'delivery_at' => 'datetime',
        'tradeable_at' => 'datetime',

        'delivery_ts_ms' => 'integer',
    ];

    public function priceHistories()
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function candles()
    {
        return $this->hasMany(Candle::class);
    }

    public function leverageBrackets()
    {
        return $this->hasMany(LeverageBracket::class);
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function symbol()
    {
        return $this->belongsTo(Symbol::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function apiSystem()
    {
        return $this->belongsTo(ApiSystem::class);
    }
}
