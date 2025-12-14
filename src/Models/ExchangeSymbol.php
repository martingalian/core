<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\ExchangeSymbol\HasAccessors;
use Martingalian\Core\Concerns\ExchangeSymbol\HasScopes;
use Martingalian\Core\Concerns\ExchangeSymbol\HasStatuses;
use Martingalian\Core\Concerns\ExchangeSymbol\HasTradingComputations;
use Martingalian\Core\Concerns\ExchangeSymbol\InteractsWithApis;
use Martingalian\Core\Concerns\ExchangeSymbol\SendsNotifications;
use Martingalian\Core\Database\Factories\ExchangeSymbolFactory;

/**
 * @property int $id
 * @property string $token
 * @property string $quote
 * @property string|null $asset
 * @property int|null $symbol_id
 * @property array{cmc_api_called?: bool, taapi_verified?: bool, has_taapi_data?: bool} $api_statuses
 * @property int $api_system_id
 * @property bool|null $is_manually_enabled
 * @property bool $auto_disabled
 * @property string|null $auto_disabled_reason
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
 * @property array<string, float>|null $btc_correlation_pearson
 * @property array<string, float>|null $btc_correlation_spearman
 * @property array<string, float>|null $btc_correlation_rolling
 * @property array<string, float>|null $btc_elasticity_long
 * @property array<string, float>|null $btc_elasticity_short
 * @property \Illuminate\Support\Carbon|null $mark_price_synced_at
 * @property string $websocket_group
 * @property bool|null $overlaps_with_binance
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Symbol|null $symbol
 * @property-read ApiSystem $apiSystem
 * @property-read string|null $parsed_trading_pair
 * @property-read string|null $parsed_trading_pair_extended
 */
final class ExchangeSymbol extends BaseModel
{
    use HasAccessors;
    use HasFactory;
    use HasScopes;
    use HasStatuses;
    use HasTradingComputations;
    use InteractsWithApis;
    use SendsNotifications;

    protected $casts = [
        'is_manually_enabled' => 'boolean',
        'auto_disabled' => 'boolean',
        'overlaps_with_binance' => 'boolean',

        'api_statuses' => 'array',
        'symbol_information' => 'array',
        'leverage_brackets' => 'array',
        'indicators' => 'array',
        'indicators_values' => 'array',
        'limit_quantity_multipliers' => 'array',

        'btc_correlation_pearson' => 'array',
        'btc_correlation_spearman' => 'array',
        'btc_correlation_rolling' => 'array',
        'btc_elasticity_long' => 'array',
        'btc_elasticity_short' => 'array',

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

    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }

    protected static function newFactory(): ExchangeSymbolFactory
    {
        return ExchangeSymbolFactory::new();
    }
}
