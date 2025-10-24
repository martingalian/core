<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Martingalian\Core\Models\Step;
use Martingalian\Core\States\Pending;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Martingalian\Core\Models\Step>
 */
final class StepFactory extends Factory
{
    protected $model = Step::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'state' => Pending::class,
            'class' => 'App\Support\Tests\EchoJob',
            'queue' => 'sync',
            'block_uuid' => null,
            'child_block_uuid' => null,
            'index' => null,
            'group' => null,
            'arguments' => [],
            'response' => null,
            'type' => 'default',
            'double_check' => 0,
            'retries' => 0,
            'started_at' => null,
            'completed_at' => null,
            'dispatch_after' => null,
            'duration' => 0,
        ];
    }
}
