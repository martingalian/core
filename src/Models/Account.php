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
use Martingalian\Core\Concerns\Account\HasGetters;
use Martingalian\Core\Concerns\Account\HasScopes;
use Martingalian\Core\Concerns\Account\HasStatuses;
use Martingalian\Core\Concerns\Account\HasTokenDiscovery;
use Martingalian\Core\Concerns\Account\InteractsWithApis;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $api_system_id
 * @property string $name
 * @property int $trade_configuration_id
 * @property int|null $portfolio_quote_id
 * @property int|null $trading_quote_id
 * @property float|null $margin
 * @property bool $can_trade
 * @property int|null $last_notified_account_balance_history_id
 * @property array|null $credentials
 * @property array|null $credentials_testing
 * @property string|null $binance_api_key
 * @property string|null $binance_api_secret
 * @property string|null $bybit_api_key
 * @property string|null $bybit_api_secret
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read ApiSystem $apiSystem
 */
final class Account extends BaseModel
{
    use HasAccessors;
    use HasCollections;
    use HasFactory;
    use HasGetters;
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
     * Create a temporary (non-persisted) Account instance with provided credentials.
     * This is the base method for creating in-memory accounts for testing or admin operations.
     *
     * @param  string  $apiSystemCanonical  API system canonical (e.g., 'binance', 'bybit')
     * @param  array  $credentials  Credentials array (e.g., ['binance_api_key' => '...', 'binance_api_secret' => '...'])
     * @return self Non-persisted Account instance
     */
    public static function temporary(string $apiSystemCanonical, array $credentials): self
    {
        $apiSystem = ApiSystem::where('canonical', $apiSystemCanonical)->firstOrFail();

        return tap(new self, function (self $account) use ($credentials, $apiSystem) {
            // Fills encrypted columns via the mutator
            $account->all_credentials = $credentials;

            // Link to API system
            $account->api_system_id = $apiSystem->id;

            // Mark as non-persisted
            $account->exists = false;
        });
    }

    /**
     * Create temporary Account with admin credentials from martingalian table.
     * Convenience wrapper around temporary() that fetches system credentials.
     *
     * @param  string  $apiSystemCanonical  API system canonical (e.g., 'binance', 'bybit')
     * @return self Non-persisted Account instance with admin credentials
     */
    public static function admin(string $apiSystemCanonical): self
    {
        $source = Martingalian::findOrFail(1);

        return self::temporary($apiSystemCanonical, $source->all_credentials);
    }

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function notificationLogs(): MorphMany
    {
        return $this->morphMany(NotificationLog::class, 'relatable');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ApiSystem, $this>
     */
    public function apiSystem(): BelongsTo
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
