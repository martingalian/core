<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\ApiSystem\HasScopes;
use Martingalian\Core\Concerns\ApiSystem\InteractsWithApis;

/**
 * @property int $id
 * @property bool $is_exchange
 * @property string $name
 * @property int $recvwindow_margin
 * @property string $canonical
 * @property string|null $taapi_canonical
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property bool $should_restart_websocket
 * @property string|null $websocket_class
 */
final class ApiSystem extends BaseModel
{
    use HasFactory;
    use HasScopes;
    use InteractsWithApis;

    public function steps(): MorphMany
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function exchangeSymbols(): HasMany
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    public function positions(): HasManyThrough
    {
        return $this->hasManyThrough(Position::class, Account::class);
    }

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\ApiSystemFactory::new();
    }
}
