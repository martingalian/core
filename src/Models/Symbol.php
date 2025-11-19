<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\Symbol\HasBaseAssetParsing;
use Martingalian\Core\Concerns\Symbol\InteractsWithApis;
use Martingalian\Core\Database\Factories\SymbolFactory;

/**
 * @property int $id
 * @property string $token
 * @property string $name
 * @property string|null $description
 * @property int|null $cmc_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Symbol extends BaseModel
{
    use HasBaseAssetParsing;
    use HasFactory;
    use InteractsWithApis;

    protected static function newFactory(): SymbolFactory
    {
        return SymbolFactory::new();
    }

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
