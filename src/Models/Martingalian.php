<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\Martingalian\HasAccessors;
use Martingalian\Core\Concerns\Martingalian\HasGetters;
use Throwable;

/**
 * @property int $id
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
    use HasGetters;

    protected $table = 'martingalian';

    protected $casts = [
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

    /**
     * Get the current server's public IP address.
     * Fetches from a public API and caches for 1 hour.
     * Falls back to gethostbyname if API fails.
     */
    public static function ip(): string
    {
        return Cache::remember('martingalian:public_ip', 3600, function () {
            try {
                // Try multiple services in case one is down
                $services = [
                    'https://api.ipify.org',
                    'https://icanhazip.com',
                    'https://ifconfig.me/ip',
                ];

                foreach ($services as $service) {
                    try {
                        $response = Http::timeout(3)->get($service);
                        if ($response->successful()) {
                            $ip = mb_trim($response->body());
                            // Validate it's a valid IP
                            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                                return $ip;
                            }
                        }
                    } catch (Throwable $e) {
                        // Try next service
                        continue;
                    }
                }

                // Fallback to gethostbyname if all services fail
                return gethostbyname(gethostname());
            } catch (Throwable $e) {
                // Ultimate fallback
                return gethostbyname(gethostname());
            }
        });
    }
}
