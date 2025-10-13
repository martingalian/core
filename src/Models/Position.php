<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Position\HasAccessors;
use Martingalian\Core\Concerns\Position\HasGetters;
use Martingalian\Core\Concerns\Position\HasScopes;
use Martingalian\Core\Concerns\Position\HasStatuses;
use Martingalian\Core\Concerns\Position\HasTradingActions;
use Martingalian\Core\Concerns\Position\InteractsWithApis;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property \Martingalian\Core\Models\Account $account
 *
 * @method \Martingalian\Core\Models\ExchangeSymbol exchangeSymbol()
 */
class Position extends BaseModel
{
    use HasAccessors;
    use HasDebuggable;
    use HasGetters;
    use HasLoggable;
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

    public function exchangeSymbol()
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
