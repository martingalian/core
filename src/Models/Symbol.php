<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Symbol\HasBaseAssetParsing;
use Martingalian\Core\Concerns\Symbol\HasScopes;
use Martingalian\Core\Concerns\Symbol\InteractsWithApis;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Symbol extends BaseModel
{
    use HasBaseAssetParsing;
    use HasDebuggable;
    use HasFactory;
    use HasLoggable;
    use HasScopes;
    use InteractsWithApis;

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }
}
