<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Models\Quote;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\Quote>
 */
final class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'canonical' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(2, true),
        ];
    }

    /**
     * State: USDT quote.
     */
    public function usdt(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'USDT',
                'name' => 'Tether USD',
            ];
        });
    }

    /**
     * State: USDC quote.
     */
    public function usdc(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'USDC',
                'name' => 'USD Coin',
            ];
        });
    }
}
