<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\User;

final class SchemaSeeder21 extends Seeder
{
    /**
     * Create a Bybit account for the default user.
     * This allows the system to fetch balance data from both Binance and Bybit accounts.
     */
    public function run(): void
    {
        $bybitApiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        if (! $bybitApiSystem) {
            if ($this->command) {
                $this->command->warn('Bybit API system not found. Skipping seeder.');
            }

            return;
        }

        $user = User::first();

        if (! $user) {
            if ($this->command) {
                $this->command->warn('No user found. Skipping seeder.');
            }

            return;
        }

        $usdt = Quote::firstWhere('canonical', 'USDT');

        if (! $usdt) {
            if ($this->command) {
                $this->command->warn('USDT quote not found. Skipping seeder.');
            }

            return;
        }

        // Check if a Bybit account already exists for this user
        $existingBybitAccount = Account::where('user_id', $user->id)
            ->where('api_system_id', $bybitApiSystem->id)
            ->first();

        if ($existingBybitAccount) {
            if ($this->command) {
                $this->command->info('Bybit account already exists for user. Skipping creation.');
            }

            return;
        }

        $account = Account::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'api_system_id' => $bybitApiSystem->id,
            'portfolio_quote_id' => $usdt->id,
            'trading_quote_id' => $usdt->id,
            'trade_configuration_id' => 1,

            'bybit_api_key' => env('BYBIT_API_KEY'),
            'bybit_api_secret' => env('BYBIT_API_SECRET'),
        ]);

        if ($this->command) {
            $this->command->info("Created Bybit account (ID: {$account->id}) for user {$user->name}.");
        }
    }
}
