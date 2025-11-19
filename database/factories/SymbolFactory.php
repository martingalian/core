<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Models\Symbol;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\Symbol>
 */
final class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'token' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'site_url' => fake()->url(),
            'image_url' => fake()->imageUrl(),
            'cmc_id' => fake()->numberBetween(1, 999999),
        ];
    }

    /**
     * State: Bitcoin symbol.
     */
    public function bitcoin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'token' => 'BTC',
                'name' => 'Bitcoin',
                'description' => 'The first and largest cryptocurrency',
                'cmc_id' => 1,
            ];
        });
    }

    /**
     * State: Ethereum symbol.
     */
    public function ethereum(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'token' => 'ETH',
                'name' => 'Ethereum',
                'description' => 'Smart contract platform',
                'cmc_id' => 1027,
            ];
        });
    }
}
