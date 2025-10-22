<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Martingalian\Core\Models\Account;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add Bybit api_system record
        DB::table('api_systems')->insert([
            'is_exchange' => 1,
            'name' => 'Bybit',
            'recvwindow_margin' => 5000,
            'canonical' => 'bybit',
            'taapi_canonical' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update Account #1 with Bybit credentials from environment
        $account = Account::query()->find(1);
        if ($account) {
            $account->bybit_api_key = env('BYBIT_API_KEY');
            $account->bybit_api_secret = env('BYBIT_API_SECRET');
            $account->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove Bybit api_system record
        DB::table('api_systems')->where('canonical', 'bybit')->delete();

        // Clear Bybit credentials from Account #1
        $account = Account::query()->find(1);
        if ($account) {
            $account->bybit_api_key = null;
            $account->bybit_api_secret = null;
            $account->save();
        }
    }
};
