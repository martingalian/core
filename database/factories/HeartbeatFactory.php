<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Models\Heartbeat;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\Heartbeat>
 */
final class HeartbeatFactory extends Factory
{
    protected $model = Heartbeat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'canonical' => fake()->unique()->slug(),
            'api_system_id' => null,
            'account_id' => null,
            'group' => null,
            'last_beat_at' => now(),
            'beat_count' => fake()->numberBetween(1, 1000),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the heartbeat is for a price stream.
     * Each exchange has its own canonical: binance_price_stream, bybit_price_stream, etc.
     *
     * @param  string  $exchange  The exchange name (binance, bybit, bitget, kraken, kucoin)
     */
    public function priceStream(string $exchange = 'binance'): static
    {
        return $this->state(function (array $attributes) use ($exchange) {
            return [
                'canonical' => "{$exchange}_price_stream",
            ];
        });
    }

    /**
     * Indicate that the heartbeat is for a user stream.
     */
    public function userStream(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'user_stream',
            ];
        });
    }

    /**
     * Set the API system for this heartbeat.
     */
    public function forApiSystem(int $apiSystemId): static
    {
        return $this->state(function (array $attributes) use ($apiSystemId) {
            return [
                'api_system_id' => $apiSystemId,
            ];
        });
    }

    /**
     * Set the group for this heartbeat.
     */
    public function forGroup(string $group): static
    {
        return $this->state(function (array $attributes) use ($group) {
            return [
                'group' => $group,
            ];
        });
    }
}
