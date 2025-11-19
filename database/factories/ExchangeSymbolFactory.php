<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Symbol;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\ExchangeSymbol>
 */
final class ExchangeSymbolFactory extends Factory
{
    protected $model = ExchangeSymbol::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'symbol_id' => Symbol::factory(),
            'quote_id' => Quote::factory(),
            'api_system_id' => ApiSystem::factory(),
            'is_active' => false,
            'is_tradeable' => false,
            'is_eligible' => false,
            'ineligible_reason' => null,
            'direction' => null,
            'percentage_gap_long' => 8.50,
            'percentage_gap_short' => 9.50,
            'price_precision' => fake()->numberBetween(2, 8),
            'quantity_precision' => fake()->numberBetween(0, 4),
            'min_notional' => fake()->randomFloat(2, 5, 100),
            'delivery_ts_ms' => null,
            'delivery_at' => null,
            'tick_size' => fake()->randomFloat(8, 0.00000001, 0.01),
            'min_price' => fake()->randomFloat(2, 0.01, 1),
            'max_price' => fake()->randomFloat(2, 100000, 1000000),
            'symbol_information' => null,
            'total_limit_orders' => 4,
            'leverage_brackets' => null,
            'mark_price' => null,
            'indicators_values' => null,
            'limit_quantity_multipliers' => null,
            'disable_on_price_spike_percentage' => 15.00,
            'price_spike_cooldown_hours' => 72,
            'indicators_timeframe' => null,
            'indicators_synced_at' => null,
            'mark_price_synced_at' => null,
        ];
    }

    /**
     * State: Active and eligible for trading.
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => true,
                'is_tradeable' => true,
                'is_eligible' => true,
                'ineligible_reason' => null,
            ];
        });
    }

    /**
     * State: Inactive due to no TAAPI data.
     */
    public function noTaapiData(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
                'is_eligible' => false,
                'ineligible_reason' => 'No TAAPI data available',
            ];
        });
    }

    /**
     * State: Long direction.
     */
    public function long(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'direction' => 'LONG',
            ];
        });
    }

    /**
     * State: Short direction.
     */
    public function short(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'direction' => 'SHORT',
            ];
        });
    }
}
