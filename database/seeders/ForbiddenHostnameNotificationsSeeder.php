<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Notification;

/**
 * Seeds the 4 forbidden hostname notification types.
 *
 * These notifications are sent when API access is blocked for various reasons:
 * - ip_not_whitelisted: User forgot to whitelist server IP (sent to USER)
 * - ip_rate_limited: Server hit rate limits temporarily (sent to ADMIN)
 * - ip_banned: Server permanently banned by exchange (sent to ADMIN)
 * - account_blocked: Account API key issue (sent to USER)
 */
final class ForbiddenHostnameNotificationsSeeder extends Seeder
{
    /**
     * Seed the forbidden hostname notification canonicals.
     */
    public function run(): void
    {
        // Case 1: IP not whitelisted by user (USER notification)
        Notification::updateOrCreate(
            ['canonical' => 'server_ip_not_whitelisted'],
            [
                'title' => 'Server IP Not Whitelisted',
                'description' => 'Your API key requires the server IP to be whitelisted. Please add the IP address to your exchange API key settings.',
                'detailed_description' => 'This notification is sent when the exchange API rejects requests because the server IP address is not in your API key\'s whitelist. '.
                    'To fix this, log into your exchange account, go to API settings, and add the IP address shown in this notification to your API key\'s allowed IP list.',
                'usage_reference' => 'Used in ApiExceptionHelpers::forbidIpNotWhitelisted() - triggered when exchange returns IP whitelist error',
                'verified' => false,
                'default_severity' => NotificationSeverity::High,
                'cache_duration' => 3600, // 1 hour - user needs time to fix
                'cache_key' => ['account_id', 'ip_address'],
            ]
        );

        // Case 2: IP temporarily rate-limited (ADMIN notification)
        Notification::updateOrCreate(
            ['canonical' => 'server_ip_rate_limited'],
            [
                'title' => 'Server IP Rate Limited',
                'description' => 'The server IP has been temporarily rate-limited by the exchange. Requests will automatically resume after the ban expires.',
                'detailed_description' => 'This notification is sent when the exchange temporarily blocks the server IP due to excessive requests. '.
                    'This is typically an automatic protection that expires after a few minutes. The system will automatically resume operations once the ban lifts.',
                'usage_reference' => 'Used in ApiExceptionHelpers::forbidIpRateLimited() - triggered when exchange returns temporary rate limit ban',
                'verified' => false,
                'default_severity' => NotificationSeverity::High,
                'cache_duration' => 300, // 5 minutes
                'cache_key' => ['api_system', 'ip_address'],
            ]
        );

        // Case 3: IP permanently banned (ADMIN notification)
        Notification::updateOrCreate(
            ['canonical' => 'server_ip_banned'],
            [
                'title' => 'Server IP Permanently Banned',
                'description' => 'The server IP has been permanently banned by the exchange. Manual intervention required.',
                'detailed_description' => 'This notification is sent when the exchange permanently bans the server IP address. '.
                    'This typically occurs after repeated violations of rate limits or terms of service. '.
                    'To resolve this, you may need to contact the exchange support team directly.',
                'usage_reference' => 'Used in ApiExceptionHelpers::forbidIpBanned() - triggered when exchange returns permanent IP ban',
                'verified' => false,
                'default_severity' => NotificationSeverity::Critical,
                'cache_duration' => 3600, // 1 hour
                'cache_key' => ['api_system', 'ip_address'],
            ]
        );

        // Case 4: Account blocked (USER notification)
        Notification::updateOrCreate(
            ['canonical' => 'server_account_blocked'],
            [
                'title' => 'Account API Access Blocked',
                'description' => 'Your exchange account API access has been blocked. Please check your API key settings or regenerate your API key.',
                'detailed_description' => 'This notification is sent when the exchange rejects your API key. '.
                    'Common causes include: API key revoked, API key disabled, insufficient permissions, payment required, or account restrictions. '.
                    'To fix this, log into your exchange account, check your API key status, and if needed, generate a new API key with the correct permissions.',
                'usage_reference' => 'Used in ApiExceptionHelpers::forbidAccountBlocked() - triggered when exchange returns authentication/authorization errors',
                'verified' => false,
                'default_severity' => NotificationSeverity::Critical,
                'cache_duration' => 3600, // 1 hour - user needs time to fix
                'cache_key' => ['account_id', 'api_system'],
            ]
        );
    }
}
