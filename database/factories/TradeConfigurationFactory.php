<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Models\TradeConfiguration;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\TradeConfiguration>
 */
final class TradeConfigurationFactory extends Factory
{
    protected $model = TradeConfiguration::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'is_default' => false,
            'canonical' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'least_timeframe_index_to_change_indicator' => 3,
            'fast_trade_position_duration_seconds' => 3600,
            'fast_trade_position_closed_age_seconds' => 1800,
            'disable_exchange_symbol_from_negative_pnl_position' => false,
            'indicator_timeframes' => ['1m', '5m', '15m', '1h'],
        ];
    }

    /**
     * Indicate that this is the default trade configuration.
     */
    public function default(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_default' => true,
            ];
        });
    }
}
