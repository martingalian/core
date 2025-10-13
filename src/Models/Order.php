<?php

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Order\HandlesChanges;
use Martingalian\Core\Concerns\Order\HasGetters;
use Martingalian\Core\Concerns\Order\HasScopes;
use Martingalian\Core\Concerns\Order\HasStatuses;
use Martingalian\Core\Concerns\Order\HasTradingActions;
use Martingalian\Core\Concerns\Order\InteractsWithApis;

/**
 * @property \Martingalian\Core\Models\Position $position
 *
 * @method \Martingalian\Core\Models\ExchangeSymbol exchangeSymbol()
 */
class Order extends BaseModel
{
    use HandlesChanges;
    use HasDebuggable;
    use HasGetters;
    use HasLoggable;
    use HasScopes;
    use HasStatuses;
    use HasTradingActions;
    use InteractsWithApis;

    protected $casts = [
        'opened_at' => 'datetime',
        'filled_at' => 'datetime',

        'price' => 'string',
        'quantity' => 'string',
        'reference_price' => 'string',
        'reference_quantity' => 'string',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function ordersHistory()
    {
        return $this->hasMany(Order::class, 'order_id');
    }

    public function apiSnapshots(): MorphMany
    {
        return $this->morphMany(ApiSnapshot::class, 'responsable');
    }

    public function getPriceAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros((float) $value);
    }

    public function getQuantityAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros((float) $value);
    }

    private function removeTrailingZeros(float $number): string
    {
        // Ensure the number is normalized as a string, no scientific notation.
        $normalized = number_format($number, 10, '.', '');  // Force a fixed decimal string

        return rtrim(rtrim($normalized, '0'), '.');  // Remove trailing zeros and the dot
    }
}
