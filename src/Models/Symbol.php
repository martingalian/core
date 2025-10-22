<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\Symbol\HasBaseAssetParsing;
use Martingalian\Core\Concerns\Symbol\HasScopes;
use Martingalian\Core\Concerns\Symbol\InteractsWithApis;

final class Symbol extends BaseModel
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
