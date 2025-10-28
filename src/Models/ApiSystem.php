<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\ApiSystem\HasScopes;
use Martingalian\Core\Concerns\ApiSystem\InteractsWithApis;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

/**
 * @property bool $should_restart_websocket
 * @property string|null $websocket_class
 */
final class ApiSystem extends BaseModel
{
    use HasDebuggable;
    use HasFactory;
    use HasLoggable;
    use HasScopes;
    use InteractsWithApis;

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    public function positions()
    {
        return $this->hasManyThrough(Position::class, Account::class);
    }

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\ApiSystemFactory::new();
    }
}
