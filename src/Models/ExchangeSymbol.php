<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\ExchangeSymbol\HasAccessors;
use Martingalian\Core\Concerns\ExchangeSymbol\HasScopes;
use Martingalian\Core\Concerns\ExchangeSymbol\HasStatuses;
use Martingalian\Core\Concerns\ExchangeSymbol\HasTradingComputations;
use Martingalian\Core\Concerns\ExchangeSymbol\InteractsWithApis;

/**
 * @property int $id
 * @property int $symbol_id
 * @property int $quote_id
 * @property int $api_system_id
 * @property bool $is_active
 * @property string|null $direction
 * @property float $percentage_gap_long
 * @property float $percentage_gap_short
 * @property int $price_precision
 * @property int $quantity_precision
 * @property float|null $min_notional
 * @property float $tick_size
 * @property array|null $symbol_information
 * @property array|null $leverage_brackets
 * @property float|null $mark_price
 * @property mixed $indicators_values
 * @property string|null $indicators_timeframe
 * @property \Illuminate\Support\Carbon|null $indicators_synced_at
 * @property \Illuminate\Support\Carbon|null $mark_price_synced_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Symbol $symbol
 * @property-read Quote $quote
 * @property-read ApiSystem $apiSystem
 * @property-read string|null $parsed_trading_pair
 * @property-read string|null $parsed_trading_pair_extended
 */
final class ExchangeSymbol extends BaseModel
{
    use HasAccessors;
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

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function steps(): MorphMany
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }

    public function leverageBrackets(): HasMany
    {
        return $this->hasMany(LeverageBracket::class);
    }

    public function apiRequestLogs(): MorphMany
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }
}
