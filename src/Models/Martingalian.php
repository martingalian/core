<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\Martingalian\HasAccessors;

/**
 * @property int $id
 * @property bool $should_kill_order_events
 * @property bool $allow_opening_positions
 * @property string|null $binance_api_key
 * @property string|null $binance_api_secret
 * @property string|null $bybit_api_key
 * @property string|null $bybit_api_secret
 * @property string|null $coinmarketcap_api_key
 * @property string|null $taapi_secret
 * @property string|null $admin_pushover_user_key
 * @property string|null $admin_pushover_application_key
 * @property string|null $admin_user_email
 * @property array<int, string> $notification_channels
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Martingalian extends BaseModel
{
    use HasAccessors;

    protected $table = 'martingalian';

    protected $casts = [
        'should_kill_order_events' => 'boolean',
        'allow_opening_positions' => 'boolean',

        'binance_api_key' => 'encrypted',
        'binance_api_secret' => 'encrypted',
        'bybit_api_key' => 'encrypted',
        'bybit_api_secret' => 'encrypted',
        'coinmarketcap_api_key' => 'encrypted',
        'taapi_secret' => 'encrypted',
        'admin_pushover_user_key' => 'encrypted',
        'admin_pushover_application_key' => 'encrypted',

        'notification_channels' => 'array',
    ];
}
