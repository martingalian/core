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
        $notifications = [
            [
                'canonical' => 'ip_not_whitelisted',
                'title' => 'IP Whitelist Required',
                'description' => 'Sent when the worker server IP address is not whitelisted on an exchange API',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'api_access_denied',
                'title' => 'API Access Denied',
                'description' => 'Sent when API access is denied (invalid credentials, IP not whitelisted, or insufficient permissions)',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'api_rate_limit_exceeded',
                'title' => 'Rate Limit Reached',
                'description' => 'Sent when API rate limits are exceeded on an exchange',
                'default_severity' => 'High',
                'user_types' => json_encode(['admin']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'invalid_api_credentials',
                'title' => 'API Credentials Issue',
                'description' => 'Sent when API credentials are invalid, expired, or revoked',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'exchange_maintenance',
                'title' => 'Exchange Maintenance',
                'description' => 'Sent when an exchange is under maintenance or experiencing service disruptions',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'api_connection_failed',
                'title' => 'Connection Issue',
                'description' => 'Sent when unable to connect to an exchange API (network/timeout issues)',
                'default_severity' => 'High',
                'user_types' => json_encode(['admin']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'pnl_alert',
                'title' => 'Profit & Loss Alert',
                'description' => 'Sent when unrealized P&L exceeds 10% of wallet balance',
                'default_severity' => 'Info',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'api_key_expired',
                'title' => 'API Key Expired',
                'description' => 'Sent when API key has expired and needs renewal',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'account_in_liquidation',
                'title' => 'Account in Liquidation',
                'description' => 'Sent when account is undergoing liquidation process',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'account_reduce_only_mode',
                'title' => 'Account in Reduce-Only Mode',
                'description' => 'Sent when account is restricted to reduce-only operations',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'account_trading_banned',
                'title' => 'Trading Banned on Account',
                'description' => 'Sent when account is banned from placing new orders',
                'default_severity' => 'Critical',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'insufficient_balance_margin',
                'title' => 'Insufficient Balance/Margin',
                'description' => 'Sent when wallet balance or margin is insufficient for operations',
                'default_severity' => 'High',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'kyc_verification_required',
                'title' => 'KYC Verification Required',
                'description' => 'Sent when KYC verification is required for trading operations',
                'default_severity' => 'High',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'account_unauthorized',
                'title' => 'Unauthorized Operation',
                'description' => 'Sent when user lacks authority for requested operation',
                'default_severity' => 'High',
                'user_types' => json_encode(['user']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'api_system_error',
                'title' => 'API System Error',
                'description' => 'Sent when unknown errors or timeouts occur on exchange API',
                'default_severity' => 'High',
                'user_types' => json_encode(['admin']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'canonical' => 'api_network_error',
                'title' => 'API Network Error',
                'description' => 'Sent when network connectivity issues prevent API communication',
                'default_severity' => 'High',
                'user_types' => json_encode(['admin']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($notifications as $notification) {
            DB::table('notifications')->insert($notification);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')->whereIn('canonical', [
            'ip_not_whitelisted',
            'api_access_denied',
            'api_rate_limit_exceeded',
            'invalid_api_credentials',
            'exchange_maintenance',
            'api_connection_failed',
            'pnl_alert',
            'api_key_expired',
            'account_in_liquidation',
            'account_reduce_only_mode',
            'account_trading_banned',
            'insufficient_balance_margin',
            'kyc_verification_required',
            'account_unauthorized',
            'api_system_error',
            'api_network_error',
        ])->delete();
    }
};
