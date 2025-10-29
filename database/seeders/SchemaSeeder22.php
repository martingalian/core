<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Models\Account;

final class SchemaSeeder22 extends Seeder
{
    /**
     * Remove Bybit credentials from account 1 (Binance account).
     * Bybit credentials should only exist on account 2.
     */
    public function run(): void
    {
        $binanceAccount = Account::find(1);

        if (! $binanceAccount) {
            if ($this->command) {
                $this->command->warn('Account 1 (Binance) not found. Skipping seeder.');
            }

            return;
        }

        // Remove Bybit credentials from the Binance account
        $binanceAccount->update([
            'bybit_api_key' => null,
            'bybit_api_secret' => null,
        ]);

        if ($this->command) {
            $this->command->info('Removed Bybit credentials from account 1 (Binance account).');
        }

        // Verify account 2 (Bybit) has the correct credentials
        $bybitAccount = Account::find(2);

        if ($bybitAccount) {
            $hasKey = ! empty($bybitAccount->bybit_api_key);
            $hasSecret = ! empty($bybitAccount->bybit_api_secret);

            if ($hasKey && $hasSecret) {
                if ($this->command) {
                    $this->command->info('Account 2 (Bybit) already has credentials configured.');
                }
            } else {
                // Set Bybit credentials on account 2 if missing
                $bybitAccount->update([
                    'bybit_api_key' => env('BYBIT_API_KEY'),
                    'bybit_api_secret' => env('BYBIT_API_SECRET'),
                ]);

                if ($this->command) {
                    $this->command->info('Updated Bybit credentials on account 2 (Bybit account).');
                }
            }
        }
    }
}
