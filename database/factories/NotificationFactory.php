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
