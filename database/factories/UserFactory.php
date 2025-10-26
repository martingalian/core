<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Martingalian\Core\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'is_admin' => false,
            'can_trade' => false,
            'pushover_key' => null,
        ];
    }

    public function unverified(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'email_verified_at' => null,
            ];
        });
    }

    public function admin(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'is_admin' => true,
            ];
        });
    }

    public function active(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'is_active' => true,
            ];
        });
    }

    public function inactive(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'is_active' => false,
            ];
        });
    }
}
