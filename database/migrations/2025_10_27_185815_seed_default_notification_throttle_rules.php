<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rules = [
            [
                'message_canonical' => 'ip_not_whitelisted',
                'throttle_seconds' => 900, // 15 minutes
                'description' => 'Notification when worker IP is not whitelisted on exchange',
                'is_active' => true,
            ],
            [
                'message_canonical' => 'api_rate_limit_exceeded',
                'throttle_seconds' => 1800, // 30 minutes
                'description' => 'Notification when API rate limit is exceeded',
                'is_active' => true,
            ],
            [
                'message_canonical' => 'api_connection_failed',
                'throttle_seconds' => 900, // 15 minutes
                'description' => 'Notification when API connection fails repeatedly',
                'is_active' => true,
            ],
            [
                'message_canonical' => 'exchange_maintenance',
                'throttle_seconds' => 3600, // 1 hour
                'description' => 'Notification when exchange is under maintenance',
                'is_active' => true,
            ],
            [
                'message_canonical' => 'invalid_api_credentials',
                'throttle_seconds' => 1800, // 30 minutes
                'description' => 'Notification when API credentials are invalid or expired',
                'is_active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            $rule['created_at'] = now();
            $rule['updated_at'] = now();
            DB::table('notification_throttle_rules')->insert($rule);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notification_throttle_rules')->whereIn('message_canonical', [
            'ip_not_whitelisted',
            'api_rate_limit_exceeded',
            'api_connection_failed',
            'exchange_maintenance',
            'invalid_api_credentials',
        ])->delete();
    }
};
