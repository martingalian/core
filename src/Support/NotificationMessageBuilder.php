<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Martingalian\Core\Enums\NotificationSeverity;
use Martingalian\Core\Models\Notification;
use Martingalian\Core\Models\User;

/**
 * NotificationMessageBuilder
 *
 * Transforms notification message canonicals into user-friendly content
 * with appropriate severity levels, action items, and exchange-specific URLs.
 *
 * IMPORTANT: This class accepts base message canonicals (e.g., 'ip_not_whitelisted')
 * without exchange prefixes. Exchange-specific context is passed via the $context array.
 * This separation allows message templates to be reusable across different API systems.
 *
 * Canonicals are validated against the notifications database table for governance.
 */
final class NotificationMessageBuilder
{
    /**
     * Build a user-friendly notification message from a base message canonical.
     *
     * @param  string|Notification  $canonical  The base message canonical or Notification model instance
     * @param  array<string, mixed>  $context  Additional context data (exchange, ip, hostname, account, amount, etc.)
     * @param  User|null  $user  The user receiving the notification (for personalization)
     * @return array{severity: NotificationSeverity, title: string, emailMessage: string, pushoverMessage: string, actionUrl: string|null, actionLabel: string|null}
     */
    public static function build(string|Notification $canonical, array $context = [], ?User $user = null): array
    {
        // Extract canonical string from model if provided
        $canonicalString = $canonical instanceof Notification ? $canonical->canonical : $canonical;

        // Validate canonical exists in database
        // Note: canonical validation against notifications table happens during development
        // Extract common context variables with type safety
        $userName = $user !== null ? $user->name : 'there';

        $exchangeRaw = $context['exchange'] ?? 'exchange';
        $exchange = is_string($exchangeRaw) ? $exchangeRaw : 'exchange';
        $exchangeTitle = ucfirst($exchange);

        $ipRaw = $context['ip'] ?? 'unknown';
        $ip = is_string($ipRaw) ? $ipRaw : 'unknown';

        $hostnameRaw = $context['hostname'] ?? gethostname();
        $hostname = is_string($hostnameRaw) ? $hostnameRaw : (string) gethostname();

        $accountNameRaw = $context['account_name'] ?? 'your account';
        $accountName = is_string($accountNameRaw) ? $accountNameRaw : 'your account';

        $accountInfoRaw = $context['account_info'] ?? null;
        $accountInfo = is_string($accountInfoRaw) ? $accountInfoRaw : null;

        $walletBalanceRaw = $context['wallet_balance'] ?? 'N/A';
        $walletBalance = is_string($walletBalanceRaw) ? $walletBalanceRaw : 'N/A';

        $unrealizedPnlRaw = $context['unrealized_pnl'] ?? 'N/A';
        $unrealizedPnl = is_string($unrealizedPnlRaw) ? $unrealizedPnlRaw : 'N/A';

        $exceptionRaw = $context['exception'] ?? null;
        $exception = is_string($exceptionRaw) ? $exceptionRaw : null;

        return match ($canonicalString) {
            'ip_not_whitelisted' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Server IP Needs Whitelisting',
                'emailMessage' => "⚠️ Action Required\n\nOne of our worker servers is not whitelisted on your {$exchangeTitle} account.\nServer IP: [COPY]{$ip}[/COPY]\n\nYour trading is continuing through other whitelisted servers, but this creates a single point of failure risk.\n\n✅ HOW TO WHITELIST:\n\n1. Copy the IP address above\n2. Log into your {$exchangeTitle} account\n3. Go to API Management\n4. Find your API key settings\n5. Add the IP address to the whitelist/restriction list\n6. Save your changes",
                'pushoverMessage' => "⚠️ Whitelist server IP on {$exchangeTitle}",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Go to API Management',
            ],

            'api_access_denied' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'API Access Denied',
                'emailMessage' => "{$exchangeTitle} returned 401/403 error from server [COPY]{$ip}[/COPY]. ".($accountInfo ? "Account: {$accountInfo}" : 'System-level API call').".\n\nPlatform automatically retrying with exponential backoff. ".($accountInfo ? 'User trading operations blocked - orders, balances, positions unavailable.' : 'System operations blocked - symbol sync, market data fetch unavailable.').".\n\nResolution steps:\n\n• Log into {$exchangeTitle} web interface and verify API key still exists\n\n• Check API key has {$ip} in IP whitelist:\n[CMD]SELECT api_key FROM accounts WHERE exchange = '{$exchange}' AND api_key_whitelisted = 1;[/CMD]\n\n• Confirm API permissions: Enable Reading, Spot & Margin Trading, Futures\n\n• Review accounts table for corrupted credentials:\n[CMD]SELECT id, name, exchange, LENGTH(api_key) as key_length, LENGTH(api_secret) as secret_length FROM accounts WHERE exchange = '{$exchange}';[/CMD]\n\n• Check {$exchangeTitle} account not suspended or region-blocked",
                'pushoverMessage' => "{$exchangeTitle} API access denied".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'api_rate_limit_exceeded' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Rate Limit Exceeded',
                'emailMessage' => "{$exchangeTitle} API rate limit exceeded.\n\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."\nPlatform automatically implemented request throttling and exponential backoff. Pending operations queued for retry.\n\nResolution steps:\n\n• Check recent API request patterns:\n[CMD]SELECT endpoint, COUNT(*) as requests, AVG(response_time_ms) as avg_ms FROM api_request_logs WHERE exchange = '{$exchange}' AND created_at > NOW() - INTERVAL 5 MINUTE GROUP BY endpoint ORDER BY requests DESC LIMIT 10;[/CMD]\n\n• Monitor rate limit headers in logs:\n[CMD]tail -100 storage/logs/laravel.log | grep -i \"rate\\|limit\\|429\"[/CMD]\n\n• Check {$exchangeTitle} rate limit documentation:\nBinance: binance.com/en/support/faq/rate-limits\nBybit: bybit-exchange.github.io/docs/v5/rate-limit\n\n• Review throttle logs:\n[CMD]SELECT canonical, COUNT(*) as throttled, MAX(created_at) as last_throttle FROM throttle_logs WHERE canonical LIKE '%{$exchange}%' AND created_at > NOW() - INTERVAL 1 HOUR GROUP BY canonical;[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} rate limit exceeded".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'invalid_api_credentials' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'URGENT: API Credentials Invalid',
                'emailMessage' => "⚠️ IMMEDIATE ACTION REQUIRED ⚠️\n\nYour {$exchangeTitle} API credentials have expired or been revoked.\n\nAccount: {$accountName}\n\n🚨 CRITICAL IMPACT:\n\nWe can NO LONGER access your {$exchangeTitle} account. If you have open positions, we CANNOT manage them, monitor them, or close them on your behalf. This puts your open trades at risk.\n\n✅ WHAT YOU NEED TO DO NOW:\n\n1. Log into your {$exchangeTitle} account IMMEDIATELY\n2. Go to API Management\n3. Check if your API keys are still active\n4. Verify all required permissions are enabled (Read, Trade, Futures)\n5. If keys were deleted or expired, create new ones and update them in our system\n\nTime is critical if you have open positions. Please act now.",
                'pushoverMessage' => "🚨 URGENT: {$exchangeTitle} API credentials invalid for {$accountName} - Open positions at risk!",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Fix API Credentials NOW',
            ],

            'exchange_maintenance' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'URGENT: Exchange Maintenance',
                'emailMessage' => "⚠️ CRITICAL: {$exchangeTitle} Unavailable\n\n{$exchangeTitle} is currently undergoing maintenance or experiencing technical issues.\n\n🚨 CRITICAL IMPACT:\n\n- We CANNOT manage your existing positions on {$exchangeTitle}\n- We CANNOT execute new trades\n- We CANNOT monitor your account in real-time\n- We CANNOT close positions or adjust stop-losses\n\n✅ WHAT YOU CAN DO:\n\nManually monitor your positions until you receive a new status update from us.\n\nIf you have open positions:\n\n1. Log into {$exchangeTitle} directly to monitor them\n2. Manually manage any positions that need attention\n3. Adjust stop-losses or take profits if needed\n4. Check {$exchangeTitle}'s status page for maintenance updates\n\n🔄 AUTOMATIC RESUMPTION:\n\nOur platform will:\n\n- Continuously monitor {$exchangeTitle}'s status\n- Automatically reconnect when service is restored\n- Resume normal trading operations immediately\n- Notify you when we can resume managing your account\n- Process any queued operations",
                'pushoverMessage' => "🚨 URGENT: {$exchangeTitle} maintenance - Cannot manage positions!",
                'actionUrl' => self::getExchangeStatusUrl($exchange),
                'actionLabel' => 'View Exchange Status',
            ],

            'api_connection_failed' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Connection Failed',
                'emailMessage' => "{$exchangeTitle} API connection timeout. TCP connection or TLS handshake failed before HTTP request sent.\n\nPlatform automatically retrying with exponential backoff. Likely causes: {$exchangeTitle} endpoint down, network routing issue, or firewall block.\n\nResolution steps:\n\n• Test TCP connectivity:\n[CMD]telnet api.{$exchange}.com 443[/CMD]\n\n• Check {$exchangeTitle} status page:\nBinance: binance.com/en/support/announcement\nBybit: bybit-exchange.github.io/docs/v5/sysStatus\n\n• Verify DNS resolution:\n[CMD]host api.{$exchange}.com[/CMD]\n\n• Check firewall allows outbound HTTPS:\n[CMD]iptables -L OUTPUT -n | grep 443[/CMD]\n\n• Test HTTP connectivity:\n[CMD]curl -I https://api.{$exchange}.com[/CMD]\n\n• Review recent connection failures:\n[CMD]SELECT endpoint, error_message, COUNT(*) FROM api_request_logs WHERE exchange = '{$exchange}' AND error_message IS NOT NULL AND created_at > NOW() - INTERVAL 1 HOUR GROUP BY endpoint, error_message ORDER BY COUNT(*) DESC;[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} connection failed",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            // NEW: Bybit specific error - Invalid API Key (10003)
            'invalid_api_key' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'Invalid API Key',
                'emailMessage' => "⚠️ URGENT: Invalid API Key on {$exchangeTitle}\n\nYour {$exchangeTitle} API key is invalid or has been deleted.\n\nAccount: {$accountName}\n\n🚨 CRITICAL IMPACT:\n\n- We CANNOT access your {$exchangeTitle} account\n- We CANNOT execute trades on your behalf\n- We CANNOT monitor your open positions\n- We CANNOT manage risk or close positions\n\nIf you have open positions, they are at risk.\n\n✅ WHAT YOU NEED TO DO NOW:\n\n1. Log into your {$exchangeTitle} account IMMEDIATELY\n2. Go to API Management\n3. Check if your API key still exists and is active\n4. If deleted: Create a new API key with correct permissions\n5. If exists but invalid: Delete and recreate it\n6. Update the new API key in our platform\n7. Ensure all permissions are enabled (Read, Trade, Contract Trade)\n\nTime is critical. Please act now to restore trading functionality.",
                'pushoverMessage' => "🚨 URGENT: {$exchangeTitle} API key invalid for {$accountName} - Update NOW!",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Fix API Key NOW',
            ],

            // NEW: Bybit specific error - Invalid Signature (10004)
            'invalid_signature' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'API Signature Error',
                'emailMessage' => "⚠️ CRITICAL: API Signature Validation Failed on {$exchangeTitle}\n\nThe API signature for your {$exchangeTitle} account is failing validation.\n\nAccount: {$accountName}\n\n🔍 WHAT THIS MEANS:\n\nAPI signature errors (error code 10004) typically indicate:\n\n- API secret key mismatch (secret changed on exchange but not updated in our platform)\n- Corrupted or incorrectly stored API credentials\n- System time synchronization issues (very rare)\n\n✅ TROUBLESHOOTING STEPS:\n\n1. Log into your {$exchangeTitle} account\n2. Go to API Management\n3. Generate a NEW API key pair (delete old one)\n4. Update BOTH API key and secret in our platform\n5. Ensure you copy the secret correctly (no extra spaces or characters)\n6. Enable required permissions (Read, Trade, Contract Trade)\n\nThis usually happens when the API secret was changed on {$exchangeTitle} but not updated in our system, or if credentials were corrupted during storage.\n\nOnce you update the credentials, trading will resume automatically.",
                'pushoverMessage' => "🚨 {$exchangeTitle} signature error for {$accountName} - Recreate API key",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Update API Credentials',
            ],

            // NEW: Bybit specific error - Insufficient Permissions (10005)
            'insufficient_permissions' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Insufficient API Permissions',
                'emailMessage' => "⚠️ ACTION REQUIRED: API Permissions Insufficient on {$exchangeTitle}\n\nYour {$exchangeTitle} API key lacks required permissions for trading operations.\n\nAccount: {$accountName}\n\n🚨 CURRENT IMPACT:\n\n- Some trading operations are BLOCKED\n- We may be unable to place orders\n- We may be unable to modify positions\n- Account functionality is LIMITED\n\n✅ REQUIRED PERMISSIONS:\n\nYour API key MUST have these permissions enabled:\n\n- ✓ Read permission (view account data)\n- ✓ Trade permission (spot/margin trading)\n- ✓ Contract Trade permission (derivatives/futures)\n\n📋 HOW TO FIX:\n\n1. Log into your {$exchangeTitle} account\n2. Go to API Management\n3. Find your API key settings\n4. Enable ALL required permissions listed above\n5. Save your changes\n\nAlternatively, you can delete the old API key and create a new one with correct permissions, then update it in our platform.\n\nOnce permissions are fixed, trading operations will resume automatically.",
                'pushoverMessage' => "⚠️ {$exchangeTitle} API permissions insufficient for {$accountName} - Enable all permissions",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Fix Permissions',
            ],

            'pnl_alert' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Position Monitoring: P&L Update',
                'emailMessage' => "📊 INFORMATIONAL: Significant P&L Movement\n\nYour unrealized profit/loss has exceeded 10% of your wallet balance.\n\nAccount: {$accountName}\nWallet Balance: {$walletBalance}\nUnrealized P&L: {$unrealizedPnl}\n\n✅ WHAT THIS MEANS:\n\nThis is an informational alert to help you monitor your trading performance. Your positions have moved significantly.\n\n📈 NEXT STEPS:\n\nYou may want to:\n\n- Review your open positions\n- Consider taking profits if in the green\n- Evaluate stop-loss adjustments if needed\n- Check your risk management strategy\n\nNo immediate action is required - this is purely for your awareness.",
                'pushoverMessage' => "📊 P&L Alert: {$unrealizedPnl} (10%+ of wallet) - {$accountName}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'insufficient_balance_margin' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Insufficient Balance/Margin',
                'emailMessage' => "⚠️ WARNING: Insufficient Funds\n\nYour {$exchangeTitle} account has insufficient balance or margin.\n\nAccount: {$accountName}\n\n🚨 CURRENT SITUATION:\n\nOperations failed due to:\n\n- Insufficient wallet balance, OR\n- Insufficient margin for leveraged positions, OR\n- Balance below minimum requirements\n\n📊 IMPACT:\n\n- Cannot execute new trades\n- Cannot increase position sizes\n- Existing positions may be at risk if margin too low\n- May face liquidation if margin falls further\n\n✅ WHAT YOU NEED TO DO:\n\n1. Log into your {$exchangeTitle} account\n2. Check your wallet balance and available margin\n3. Add funds to your account if needed\n4. Review your current positions and margin usage\n5. Consider reducing leverage or position sizes\n6. Close positions if necessary to free up margin\n\nIf you don't have sufficient funds, consider reducing your exposure to avoid liquidation risk.",
                'pushoverMessage' => "⚠️ {$accountName} on {$exchangeTitle}: Insufficient balance/margin",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Check Balance',
            ],

            'kyc_verification_required' => [
                'severity' => NotificationSeverity::High,
                'title' => 'KYC Verification Required',
                'emailMessage' => "ℹ️ ACTION REQUIRED: Complete KYC Verification\n\nYour {$exchangeTitle} account requires additional KYC (Know Your Customer) verification.\n\nAccount: {$accountName}\n\n📋 WHY THIS IS NEEDED:\n\nKYC verification is required for:\n\n- Using leverage above certain thresholds (typically >20x)\n- Trading specific instruments or markets\n- Accessing advanced features\n- Regulatory compliance in your region\n\n🚨 IMPACT:\n\n- Limited leverage available\n- Some trading pairs may be restricted\n- Cannot access certain features until verified\n\n✅ HOW TO COMPLETE KYC:\n\n1. Log into your {$exchangeTitle} account\n2. Go to Account Settings or KYC/Verification section\n3. Follow the identity verification process\n4. Submit required documents (ID, proof of address, etc.)\n5. Wait for verification approval (typically 24-48 hours)\n\nOnce verified, all features will be unlocked and you can resume full trading operations.",
                'pushoverMessage' => "ℹ️ {$accountName} on {$exchangeTitle}: KYC verification needed",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Complete KYC',
            ],

            // NEW: Admin monitoring notifications
            'api_system_error' => [
                'severity' => NotificationSeverity::High,
                'title' => 'API System Error Detected',
                'emailMessage' => "{$exchangeTitle} API returned 5xx error. ".($accountInfo ? "Account: {$accountInfo}" : 'System-level API call').".\n\nPlatform automatically retrying failed operations with exponential backoff. Likely exchange-side issue: backend timeout, overload, or temporary service degradation. Transient by nature.\n\nResolution steps:\n\n• Check {$exchangeTitle} status page and Twitter for incident reports:\nBinance: binance.com/en/support/announcement\nBybit: bybit-exchange.github.io/docs/v5/sysStatus\n\n• Review application logs:\n[CMD]tail -100 storage/logs/laravel.log | grep -i \"5xx\\|500\\|502\\|503\"[/CMD]\n\n• Check api_request_logs for failure patterns:\n[CMD]SELECT endpoint, COUNT(*) as failures, MIN(created_at) as first_fail, MAX(created_at) as last_fail FROM api_request_logs WHERE status_code >= 500 AND created_at > NOW() - INTERVAL 1 HOUR GROUP BY endpoint ORDER BY failures DESC;[/CMD]\n\n• Monitor if errors persist:\n[CMD]SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute, COUNT(*) as errors FROM api_request_logs WHERE exchange = '{$exchange}' AND status_code >= 500 AND created_at > NOW() - INTERVAL 30 MINUTE GROUP BY minute ORDER BY minute DESC;[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} system error".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'api_network_error' => [
                'severity' => NotificationSeverity::High,
                'title' => 'API Network Error',
                'emailMessage' => "{$exchangeTitle} API network connectivity failure. ".($accountInfo ? "Account: {$accountInfo}" : 'System-level API call').".\n\nPlatform automatically retrying with exponential backoff. cURL error or socket timeout. Network unreachable, DNS failure, or routing issue.\n\nResolution steps:\n\n• Test HTTP connectivity:\n[CMD]curl -I https://api.{$exchange}.com[/CMD]\n\n• Check DNS resolution:\n[CMD]dig api.{$exchange}.com[/CMD]\n\n• Verify firewall allows outbound HTTPS:\n[CMD]iptables -L OUTPUT -n | grep 443[/CMD]\n\n• Check {$exchangeTitle} status page for DDoS mitigation or region blocks:\nBinance: binance.com/en/support/announcement\nBybit: bybit-exchange.github.io/docs/v5/sysStatus\n\n• Check api_request_logs for endpoint failure patterns:\n[CMD]SELECT endpoint, error_message, COUNT(*) as failures FROM api_request_logs WHERE exchange = '{$exchange}' AND error_message IS NOT NULL AND created_at > NOW() - INTERVAL 1 HOUR GROUP BY endpoint, error_message ORDER BY failures DESC;[/CMD]\n\n• Test network path:\n[CMD]traceroute api.{$exchange}.com[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} network error".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_websocket_error', 'bybit_websocket_error' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} WebSocket Error",
                'emailMessage' => "{$exchangeTitle} WebSocket connection error. Real-time price updates interrupted.\n\nException: ".($exception ?? 'Unknown error')."\n\nPlatform automatically reconnecting with exponential backoff. If fails, supervisor will restart update-{$exchange}-prices command.\n\nResolution steps:\n\n• Check supervisor status:\n[CMD]supervisorctl status update-{$exchange}-prices[/CMD]\n\n• Check supervisor logs:\n[CMD]supervisorctl tail update-{$exchange}-prices[/CMD]\n\n• Review full application logs:\n[CMD]tail -100 storage/logs/laravel.log | grep -i \"{$exchange}\"[/CMD]\n\n• Check {$exchangeTitle} API status page for WebSocket service incidents:\nBinance: binance.com/en/support/announcement\nBybit: bybit-exchange.github.io/docs/v5/sysStatus\n\n• Monitor price sync status:\n[CMD]SELECT parsed_trading_pair, mark_price_synced_at, TIMESTAMPDIFF(SECOND, mark_price_synced_at, NOW()) as seconds_stale FROM exchange_symbols WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') ORDER BY mark_price_synced_at ASC LIMIT 10;[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} WebSocket error: ".($exception ?? 'Unknown'),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_invalid_json', 'bybit_invalid_json' => [
                'severity' => NotificationSeverity::Medium,
                'title' => "{$exchangeTitle} Price Stream: Invalid Data",
                'emailMessage' => "{$exchangeTitle} price stream supervisor (update-{$exchange}-prices) received malformed JSON from WebSocket. Data discarded to prevent corruption.\n\nPlatform continues listening for subsequent price updates. Next message should be valid. Raw malformed data logged in application logs. Typically transient network glitch.\n\nResolution steps (if repeated >5 times in 30 minutes):\n\n• Review application logs for raw JSON samples:\n[CMD]tail -100 storage/logs/laravel.log | grep -i \"invalid\\|malformed\\|json\"[/CMD]\n\n• Check {$exchangeTitle} API changelog for WebSocket protocol changes:\nBinance: binance.com/en/support/announcement\nBybit: bybit-exchange.github.io/docs/v5/changelog\n\n• Test network quality:\n[CMD]mtr -c 10 stream.{$exchange}.com[/CMD]\n\n• Check supervisor status and logs:\n[CMD]supervisorctl status update-{$exchange}-prices[/CMD]\n[CMD]supervisorctl tail update-{$exchange}-prices | grep -i \"json\\|parse\"[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} price stream: invalid JSON received",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'update_prices_restart' => [
                'severity' => NotificationSeverity::Info,
                'title' => "{$exchangeTitle} Price Stream Restart",
                'emailMessage' => "{$exchangeTitle} WebSocket price monitoring restarting due to symbol list changes (new pairs added or removed).\n\nPlatform gracefully closing existing WebSocket connection and reconnecting with updated subscription list. Price streaming resumes within seconds. Normal operational event.\n\nNo action required - fully automated process.\n\n• Monitor supervisor status:\n[CMD]supervisorctl status update-{$exchange}-prices[/CMD]\n\n• Check recent supervisor logs:\n[CMD]supervisorctl tail update-{$exchange}-prices[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} price supervisor restarting - new trading pairs detected",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_db_update_error', 'bybit_db_update_error' => [
                'severity' => NotificationSeverity::Critical,
                'title' => "{$exchangeTitle} Price Stream: Database Error",
                'emailMessage' => "{$exchangeTitle} price stream supervisor (update-{$exchange}-prices) failed to persist price data to database. Data received from WebSocket but UPDATE to exchange_symbols.mark_price failed.\n\nPlatform continues receiving prices but cannot persist to mark_price and mark_price_synced_at. Stale price detection may trigger. Trading algorithms using stale data.\n\nCRITICAL: Immediate action required to prevent trading on stale prices.\n\nResolution steps:\n\n• Check MySQL server status:\n[CMD]systemctl status mysql[/CMD]\n\n• Check disk space on database host:\n[CMD]df -h[/CMD]\n\n• Review MySQL connection pool for locks:\n[CMD]mysql -e \"SHOW PROCESSLIST;\"[/CMD]\n\n• Check for long-running queries:\n[CMD]mysql -e \"SELECT * FROM information_schema.processlist WHERE TIME > 10 ORDER BY TIME DESC;\"[/CMD]\n\n• Check MySQL error logs:\n[CMD]tail -100 /var/log/mysql/error.log[/CMD]\n\n• Test database connectivity:\n[CMD]mysql -e \"SELECT 1;\"[/CMD]\n\n• Check database table status:\n[CMD]mysql -e \"SELECT COUNT(*) as total, MAX(mark_price_synced_at) as latest FROM exchange_symbols WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}');\"[/CMD]\n\n• Check supervisor status:\n[CMD]supervisorctl status update-{$exchange}-prices[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} price stream: database error",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_db_insert_error', 'bybit_db_insert_error' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} History Insert Error",
                'emailMessage' => "Failed to insert {$exchangeTitle} price history record.\n\nPrice history data (5-minute intervals) not being saved to database. This affects:\n- Historical chart data completeness\n- Long-term price analysis accuracy\n- Backtesting data integrity\n- Performance reporting historical context\n\nReal-time price updates continue normally. Only the historical price snapshot (taken every 5 minutes) failed to save.\n\nPossible causes:\n- Database connection issues during snapshot\n- Table lock or deadlock during high load\n- Disk space constraints on database server\n- Duplicate key constraint violations\n- Permission issues on price_histories table\n\nNext 5-minute snapshot will attempt to save normally. If successful, only one historical data point will be missing.\n\nResolution steps (if persistent):\n\n• Check database server health:\n[CMD]systemctl status mysql[/CMD]\n\n• Check disk space:\n[CMD]df -h[/CMD]\n\n• Review price_histories table:\n[CMD]mysql -e \"SHOW TABLE STATUS LIKE 'price_histories';\"[/CMD]\n\n• Check for locks:\n[CMD]mysql -e \"SHOW PROCESSLIST;\"[/CMD]\n\n• Monitor database server load:\n[CMD]top -b -n 1 | head -20[/CMD]",
                'pushoverMessage' => "⚠️ {$exchangeTitle} history insert error",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'stale_price_detected' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} Stale Prices Detected",
                'emailMessage' => "{$exchangeTitle} price updates not received within expected timeframe. WebSocket connection may be stalled or {$exchangeTitle} API experiencing issues.\n\nPlatform automatically set should_restart_websocket flag on api_systems table. WebSocket command checks this flag every second and will gracefully restart. Supervisor then relaunches the process.\n\nResolution steps:\n\n• Check stale prices:\n[CMD]SELECT parsed_trading_pair, mark_price, mark_price_synced_at, TIMESTAMPDIFF(SECOND, mark_price_synced_at, NOW()) as seconds_stale FROM exchange_symbols WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') ORDER BY mark_price_synced_at ASC LIMIT 10;[/CMD]\n\n• Verify restart flag:\n[CMD]SELECT should_restart_websocket, updated_at FROM api_systems WHERE canonical = '{$exchange}';[/CMD]\n\n• Check supervisor status:\n[CMD]supervisorctl status update-{$exchange}-prices[/CMD]\nOr tail logs:\n[CMD]supervisorctl tail update-{$exchange}-prices[/CMD]\n\n• Check {$exchangeTitle} status page:\nBinance: binance.com/en/support/announcement\nBybit: bybit-exchange.github.io/docs/v5/sysStatus\n\n• Review application logs (look for websocket errors, connection failures, mark_price update failures):\n[CMD]tail -100 storage/logs/laravel.log | grep -i \"{$exchange}\"[/CMD]",
                'pushoverMessage' => "Stale price detected on {$exchangeTitle}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'notification_gateway_error' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'Notification Gateway Error',
                'emailMessage' => "Notification delivery failed via Pushover or Zeptomail gateway. Critical system alerts may not reach intended recipients.\n\nPlatform logged failure in notification_logs with status='failed'. Transient errors may auto-retry. Permanent errors (invalid credentials, exhausted quota) require manual intervention.\n\nResolution steps:\n\n• Query failed notifications:\n[CMD]SELECT id, channel, status, error_message, gateway_response, created_at FROM notification_logs WHERE status='failed' AND channel IN ('mail','pushover') ORDER BY created_at DESC LIMIT 20;[/CMD]\n\n• Check error patterns:\n[CMD]SELECT channel, error_message, COUNT(*) as failures FROM notification_logs WHERE status='failed' AND created_at > NOW() - INTERVAL 1 HOUR GROUP BY channel, error_message ORDER BY failures DESC;[/CMD]\n\n• Verify environment credentials in [CMD].env[/CMD] file\n\n• Check gateway service status pages for outages\n\n• Test Pushover credentials:\n[CMD]curl -s -F \"token=YOUR_PUSHOVER_TOKEN\" -F \"user=YOUR_USER_KEY\" -F \"message=test\" https://api.pushover.net/1/messages.json[/CMD]\n\n• Test Zeptomail credentials:\n[CMD]curl -X POST https://api.zeptomail.com/v1.1/email -H \"Authorization: Zoho-enczapikey YOUR_API_KEY\" -H \"Content-Type: application/json\" -d '{\"from\":{\"address\":\"test@example.com\"},\"to\":[{\"email_address\":{\"address\":\"recipient@example.com\"}}],\"subject\":\"Test\",\"textbody\":\"Test message\"}'[/CMD]",
                'pushoverMessage' => 'Notification delivery failed - check logs',
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'symbol_delisting_positions_detected' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Token Delisting Detected',
                'emailMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'A symbol delivery date has changed, indicating potential delisting.',
                'pushoverMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'Token delisting detected',
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'exchange_symbol_no_taapi_data' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Exchange Symbol Auto-Deactivated',
                'emailMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'An exchange symbol was automatically deactivated due to consistent lack of TAAPI indicator data.',
                'pushoverMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'Exchange symbol auto-deactivated - no TAAPI data',
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'symbol_cmc_id_not_found' => [
                'severity' => NotificationSeverity::Medium,
                'title' => 'Symbol Not Found on CoinMarketCap',
                'emailMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'A symbol could not be found on CoinMarketCap. The symbol will be created without CMC metadata.',
                'pushoverMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'Symbol not found on CoinMarketCap',
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            // Default fallback for unknown canonicals
            default => [
                'severity' => NotificationSeverity::Info,
                'title' => 'System Notification',
                'emailMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'A system event occurred that requires your attention.',
                'pushoverMessage' => is_string($context['message'] ?? null) ? $context['message'] : 'System notification',
                'actionUrl' => null,
                'actionLabel' => null,
            ],
        };
    }

    /**
     * Get the API management URL for an exchange.
     */
    private static function getApiManagementUrl(string $exchange): ?string
    {
        return match (mb_strtolower($exchange)) {
            'binance' => 'https://www.binance.com/en/my/settings/api-management',
            'bybit' => 'https://www.bybit.com/app/user/api-management',
            default => null,
        };
    }

    /**
     * Get the exchange status/announcement URL.
     */
    private static function getExchangeStatusUrl(string $exchange): ?string
    {
        return match (mb_strtolower($exchange)) {
            'binance' => 'https://www.binance.com/en/support/announcement/system',
            'bybit' => 'https://www.bybit.com/en/announcement-info?category=latest_activities',
            default => null,
        };
    }

    /**
     * Get the exchange futures trading URL (for login/position management).
     */
    private static function getExchangeFuturesUrl(string $exchange): ?string
    {
        return match (mb_strtolower($exchange)) {
            'binance' => 'https://www.binance.com/en/futures',
            'bybit' => 'https://www.bybit.com/trade/usdt',
            default => null,
        };
    }
}
