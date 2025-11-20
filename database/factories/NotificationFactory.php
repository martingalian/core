<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Notification;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\Notification>
 */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'canonical' => fake()->unique()->slug(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(10),
            'detailed_description' => fake()->paragraph(),
            'usage_reference' => null,
            'default_severity' => fake()->randomElement(NotificationSeverity::cases()),
            'verified' => true,
        ];
    }

    /**
     * Indicate that the notification is for server rate limit exceeded.
     */
    public function serverRateLimitExceeded(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_rate_limit_exceeded',
                'title' => 'Rate Limit Exceeded',
                'description' => 'Sent when exchange API returns 429 (rate limit)',
                'default_severity' => NotificationSeverity::High,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system', 'account'],
            ];
        });
    }

    /**
     * Indicate that the notification is for server IP forbidden (418 IP ban).
     */
    public function serverIpForbidden(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_ip_forbidden',
                'title' => 'Server IP Forbidden',
                'description' => 'Sent when server/IP is forbidden from accessing exchange API (HTTP 418 IP ban)',
                'default_severity' => NotificationSeverity::Critical,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['account', 'server'],
            ];
        });
    }

    /**
     * Indicate that the notification is for exchange symbol with no TAAPI data.
     */
    public function exchangeSymbolNoTaapiData(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'exchange_symbol_no_taapi_data',
                'title' => 'Exchange Symbol Deactivated - No TAAPI Data',
                'description' => 'Sent when an exchange symbol is automatically deactivated due to consistent TAAPI data failures',
                'default_severity' => NotificationSeverity::Medium,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['exchange_symbol', 'exchange'],
            ];
        });
    }

    /**
     * Indicate that the notification is for stale price detected.
     */
    public function stalePriceDetected(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'stale_price_detected',
                'title' => 'Stale Price Detected',
                'description' => 'Sent when exchange symbol prices have not been updated within expected timeframe',
                'default_severity' => NotificationSeverity::High,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system'],
            ];
        });
    }

    /**
     * Indicate that the notification is for update prices restart.
     */
    public function updatePricesRestart(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'update_prices_restart',
                'title' => 'Price Stream Restart',
                'description' => 'Sent when price monitoring restarts due to symbol changes',
                'default_severity' => NotificationSeverity::Info,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system'],
            ];
        });
    }

    /**
     * Indicate that the notification is for websocket error.
     */
    public function websocketError(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'websocket_error',
                'title' => 'WebSocket Error',
                'description' => 'Sent when WebSocket connection encounters errors',
                'default_severity' => NotificationSeverity::Critical,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system'],
            ];
        });
    }

    /**
     * Indicate that the notification is for websocket invalid JSON.
     */
    public function websocketInvalidJson(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'websocket_invalid_json',
                'title' => 'WebSocket: Invalid JSON Response',
                'description' => 'Sent when exchange WebSocket returns invalid JSON',
                'default_severity' => NotificationSeverity::Medium,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system'],
            ];
        });
    }

    /**
     * Indicate that the notification is for websocket prices update error.
     */
    public function websocketPricesUpdateError(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'websocket_prices_update_error',
                'title' => 'WebSocket Prices: Database Update Error',
                'description' => 'Sent when database update fails for WebSocket price data',
                'default_severity' => NotificationSeverity::Critical,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system'],
            ];
        });
    }

    /**
     * Indicate that the notification is unverified.
     */
    public function unverified(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'verified' => false,
            ];
        });
    }
}
