<?php

declare(strict_types=1);

namespace Martingalian\Core\Repeaters;

use Martingalian\Core\Abstracts\BaseRepeater;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Server;
use Martingalian\Core\Support\NotificationService;
use Throwable;

/**
 * ServerIpNotWhitelistedRepeater
 *
 * Periodically tests if server IP has been whitelisted on exchange by querying account balance.
 * On success: Sends "Server IP Whitelisted" notification to admin.
 * On failure: Silently retries with exponential backoff.
 * On max attempts: Sends notification requiring manual intervention.
 */
final class ServerIpNotWhitelistedRepeater extends BaseRepeater
{
    public function __construct(
        public Account $account,
        public Server $server
    ) {}

    /**
     * Test if server IP is whitelisted by querying account balance
     *
     * @return bool True if IP is whitelisted (query succeeds), false otherwise
     */
    public function __invoke(): bool
    {
        try {
            // Test if IP is whitelisted by making a signed API request
            $this->account->apiQueryBalance();

            return true;

        } catch (Throwable $e) {
            // IP still not whitelisted or other error
            return false;
        }
    }

    /**
     * Hook: Called when server IP is successfully whitelisted
     * Sends success notification to admin
     */
    public function onPassed(): void
    {
        $exchange = $this->account->apiSystem->canonical;
        /** @var \Martingalian\Core\Models\User|null $user */
        $user = $this->account->user;
        $accountName = $user ? $user->name : 'Account';
        $hostname = $this->server->hostname;
        $ipAddress = $this->server->ip_address;

        NotificationService::send(
            user: Martingalian::admin(),
            message: "✅ Server Reconnected\n\nGood news! Server {$hostname} (IP: {$ipAddress}) has been successfully whitelisted on {$exchange}.\n\nAccount: {$accountName} (ID: {$this->account->id})\n\nThe server can now execute API requests and trading operations normally.",
            title: 'Server IP Whitelisted',
            deliveryGroup: 'default',
            exchange: $exchange,
            serverIp: $ipAddress
        );
    }

    /**
     * Hook: Called when server IP whitelist test fails
     * Silently retries - no notification needed
     */
    public function onFailed(): void
    {
        // Silent retry - no action needed
    }

    /**
     * Hook: Called when max retry attempts reached
     * Sends critical notification requiring manual intervention
     */
    public function onMaxAttemptsReached(): void
    {
        $exchange = $this->account->apiSystem->canonical;
        /** @var \Martingalian\Core\Models\User|null $user */
        $user = $this->account->user;
        $accountName = $user ? $user->name : 'Account';
        $hostname = $this->server->hostname;
        $ipAddress = $this->server->ip_address;
        $maxAttempts = $this->repeater->max_attempts;

        NotificationService::send(
            user: Martingalian::admin(),
            message: "⚠️ Manual Intervention Required\n\nServer {$hostname} (IP: {$ipAddress}) has not been whitelisted on {$exchange} after {$maxAttempts} automatic checks over {$this->getElapsedTime()}.\n\nAccount: {$accountName} (ID: {$this->account->id})\n\nPlease manually whitelist this IP address in your {$exchange} API settings to restore full server access.",
            title: 'Server IP Still Not Whitelisted',
            deliveryGroup: 'exceptions',
            exchange: $exchange,
            serverIp: $ipAddress
        );
    }

    /**
     * Get human-readable elapsed time since first attempt
     */
    public function getElapsedTime(): string
    {
        $createdAt = $this->repeater->created_at;
        $now = now();

        $diffInMinutes = $createdAt->diffInMinutes($now);

        if ($diffInMinutes < 60) {
            return "{$diffInMinutes} minutes";
        }

        $diffInHours = $createdAt->diffInHours($now);

        if ($diffInHours < 24) {
            return "{$diffInHours} hours";
        }

        $diffInDays = $createdAt->diffInDays($now);

        return "{$diffInDays} days";
    }
}
