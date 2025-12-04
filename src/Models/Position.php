<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\Position\HasAccessors;
use Martingalian\Core\Concerns\Position\HasGetters;
use Martingalian\Core\Concerns\Position\HasScopes;
use Martingalian\Core\Concerns\Position\HasStatuses;
use Martingalian\Core\Concerns\Position\HasTradingActions;
use Martingalian\Core\Concerns\Position\InteractsWithApis;

/**
 * @property Account $account
 * @property ExchangeSymbol $exchangeSymbol
 */
final class Position extends BaseModel
{
    use HasAccessors;
    use HasGetters;
    use HasScopes;
    use HasStatuses;
    use HasTradingActions;
    use InteractsWithApis;

    protected $casts = [
        'was_fast_traded' => 'boolean',
        'was_waped' => 'boolean',

        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'waped_at' => 'datetime',
        'watched_since' => 'datetime',

        'indicators_values' => 'array',

        'quantity' => 'string',
    ];

    public function logMutators(): array
    {
        return [
            'exchange_symbol_id' => function ($model, $old, $new, $type) {
                $model->refresh();

                return $model->parsed_trading_pair;
            },

            'account_id' => function ($model, $old, $new, $type) {
                $model->refresh();

                return $model->account->user->name;
            },
        ];
    }

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    /**
     * @return BelongsTo<ExchangeSymbol, $this>
     */
    public function exchangeSymbol(): BelongsTo
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }

    public function apiSnapshots(): MorphMany
    {
        return $this->morphMany(ApiSnapshot::class, 'responsable');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function tradeConfiguration()
    {
        return $this->belongsTo(TradeConfiguration::class);
    }
}
