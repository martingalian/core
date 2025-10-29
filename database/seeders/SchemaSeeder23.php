<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Martingalian;

final class SchemaSeeder23 extends Seeder
{
    /**
     * Add Bybit credentials and notification channels to martingalian table.
     * This enables admin notifications via both Pushover and Email.
     */
    public function run(): void
    {
        $martingalian = Martingalian::findOrFail(1);

        // Set Bybit credentials from environment
        $martingalian->bybit_api_key = env('BYBIT_API_KEY');
        $martingalian->bybit_api_secret = env('BYBIT_API_SECRET');

        // Set notification channels to both Pushover and Mail
        // Using simple string format: ['pushover', 'mail']
        $martingalian->notification_channels = [
            'pushover',
            'mail',
        ];

        $martingalian->save();

        if ($this->command) {
            $this->command->info('Added Bybit credentials and notification channels to martingalian table.');
        }
    }
}
