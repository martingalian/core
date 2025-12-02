<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds columns to support 4 distinct IP/account blocking cases:
     * - ip_not_whitelisted: User forgot to whitelist server IP (user-fixable)
     * - ip_rate_limited: Temporary rate limit ban (auto-recovers)
     * - ip_banned: Permanent IP ban for ALL accounts (contact exchange)
     * - account_blocked: Account-specific API key issue (user regenerates key)
     */
    public function up(): void
    {
        Schema::table('forbidden_hostnames', function (Blueprint $table) {
            // Type of blocking - determines how to handle and who to notify
            $table->string('type', 32)
                ->default('ip_not_whitelisted')
                ->after('ip_address')
                ->comment('Type: ip_not_whitelisted, ip_rate_limited, ip_banned, account_blocked');

            // When the ban expires (null = permanent or until user fixes it)
            $table->timestamp('forbidden_until')
                ->nullable()
                ->after('type')
                ->comment('When temporary ban expires, null for permanent/user-fixable');

            // Store the original error code/message for debugging
            $table->string('error_code', 32)
                ->nullable()
                ->after('forbidden_until')
                ->comment('Original error code from exchange (e.g., -2015, 10010)');

            $table->string('error_message')
                ->nullable()
                ->after('error_code')
                ->comment('Original error message from exchange');

            // Index for efficient lookups of active bans
            $table->index(['api_system_id', 'ip_address', 'type'], 'fh_system_ip_type_idx');
            $table->index(['account_id', 'type'], 'fh_account_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forbidden_hostnames', function (Blueprint $table) {
            $table->dropIndex('fh_system_ip_type_idx');
            $table->dropIndex('fh_account_type_idx');
            $table->dropColumn(['type', 'forbidden_until', 'error_code', 'error_message']);
        });
    }
};
