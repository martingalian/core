<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\Account\HasAccessors;
use Martingalian\Core\Concerns\Account\HasCollections;
use Martingalian\Core\Concerns\Account\HasScopes;
use Martingalian\Core\Concerns\Account\HasStatuses;
use Martingalian\Core\Concerns\Account\HasTokenDiscovery;
use Martingalian\Core\Concerns\Account\InteractsWithApis;

final class Account extends BaseModel
{
    use HasAccessors;
    use HasCollections;
    use HasFactory;
    use HasScopes;
    use HasStatuses;
    use HasTokenDiscovery;
    use InteractsWithApis;
    use SoftDeletes;

    protected $casts = [
        'can_trade' => 'boolean',
        'is_active' => 'boolean',
        'credentials' => 'array',
        'credentials_testing' => 'array',

        'binance_api_key' => 'encrypted',
        'binance_api_secret' => 'encrypted',
        'bybit_api_key' => 'encrypted',
        'bybit_api_secret' => 'encrypted',
        // Note: The following casts support Account::admin() in-memory instances
        // These columns don't exist in the accounts table (admin-only, stored in martingalian table)
        'coinmarketcap_api_key' => 'encrypted',
        'taapi_secret' => 'encrypted',
    ];

    /**
     * Build an in-memory Account carrying the "Martingalian admin" credentials
     * for the requested API system. This does not persist anything.
     */
    public static function admin(string $apiSystemCanonical): self
    {
        $source = Martingalian::findOrFail(1);

        $apiSystem = ApiSystem::where('canonical', $apiSystemCanonical)->firstOrFail();

        return tap(new self, function (self $account) use ($source, $apiSystem) {
            // Fills encrypted columns via the mutator.
            $account->all_credentials = $source->all_credentials;

            // Non-null and valid (prevents withApi() null-rel issues).
            $account->api_system_id = $apiSystem->id;
        });
    }

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function throttleLogs(): MorphMany
    {
        return $this->morphMany(\App\Models\ThrottleLog::class, 'contextable');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiSystem()
    {
        return $this->belongsTo(ApiSystem::class);
    }

    public function portfolioQuote()
    {
        return $this->belongsTo(Quote::class, 'portfolio_quote_id');
    }

    public function tradingQuote()
    {
        return $this->belongsTo(Quote::class, 'trading_quote_id');
    }

    public function apiSnapshots(): MorphMany
    {
        return $this->morphMany(ApiSnapshot::class, 'responsable');
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function forbiddenHostnames()
    {
        return $this->hasMany(ForbiddenHostname::class);
    }

    public function balanceHistory()
    {
        return $this->hasMany(AccountBalanceHistory::class);
    }

    public function tradeConfiguration(): BelongsTo
    {
        return $this->belongsTo(TradeConfiguration::class);
    }

    protected static function newFactory()
    {
        return \Martingalian\Core\Database\Factories\AccountFactory::new();
    }
}
