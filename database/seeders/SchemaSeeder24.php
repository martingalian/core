<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Martingalian;

final class SchemaSeeder24 extends Seeder
{
    /**
     * Move admin Pushover key from config to martingalian table.
     * Centralizes all credentials in the database (encrypted).
     */
    public function run(): void
    {
        $martingalian = Martingalian::findOrFail(1);

        // Get admin Pushover key from config and store it in the database
        $adminPushoverKey = config('martingalian.admin_user_pushover_key');

        if ($adminPushoverKey) {
            $martingalian->admin_pushover_key = $adminPushoverKey;
            $martingalian->save();

            if ($this->command) {
                $this->command->info('Moved admin Pushover key from config to martingalian table.');
            }
        } else {
            if ($this->command) {
                $this->command->warn('No admin Pushover key found in config. Skipping.');
            }
        }
    }
}
