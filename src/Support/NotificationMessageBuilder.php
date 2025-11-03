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

        return match ($canonicalString) {
            'ip_not_whitelisted' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Server IP Needs Whitelisting',
                'emailMessage' => "⚠️ Action Required\n\nOne of our worker servers is not whitelisted on your {$exchangeTitle} account.\nServer IP: [COPY]{$ip}[/COPY]\n\nYour trading is continuing through other whitelisted servers, but this creates a single point of failure risk.\n\n✅ HOW TO WHITELIST:\n\n1. Copy the IP address above\n2. Log into your {$exchangeTitle} account\n3. Go to API Management\n4. Find your API key settings\n5. Add the IP address to the whitelist/restriction list\n6. Save your changes",
                'pushoverMessage' => "⚠️ Whitelist IP {$ip} on {$exchangeTitle} to restore full redundancy",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Go to API Management',
            ],

            'api_access_denied' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'API Access Denied',
                'emailMessage' => "⚠️ CRITICAL: API ACCESS DENIED\n\nServer cannot access {$exchangeTitle} API.\nServer IP: [COPY]{$ip}[/COPY]\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."\n\n🔍 ROOT CAUSE ANALYSIS:\n\nAPI access denied errors typically indicate:\n\n1. Server IP not whitelisted in {$exchangeTitle} API settings\n2. API key has been disabled, deleted, or revoked\n3. API permissions have been changed or restricted\n4. Account-level access restrictions or bans\n5. Regional or compliance-based access limitations\n\n🚨 PLATFORM IMPACT:\n\n".($accountInfo ? "User account operations are blocked:\n\n- Cannot execute trades for this account\n- Cannot fetch account balances or positions\n- Cannot manage existing orders\n- Cannot monitor account status\n\nUser may experience service degradation or complete loss of automated trading for this account." : "System-level operations are blocked:\n\n- Cannot sync symbol data from {$exchangeTitle}\n- Cannot fetch public market data\n- Cannot update exchange configurations\n- Cannot perform system maintenance tasks")."\n\n✅ ADMIN INVESTIGATION STEPS:\n\n1. Verify server IP [COPY]{$ip}[/COPY] is whitelisted in {$exchangeTitle} API settings\n2. Check if API key is still active and not expired\n3. Confirm all required API permissions are enabled (Read, Trade, Contract)\n4. Review {$exchangeTitle} account for any restrictions or bans\n5. Check application logs for specific error codes or messages\n6. Verify account has not been flagged for compliance review\n\n🔄 AUTOMATIC RETRY:\n\nThe platform will continuously retry with exponential backoff. Once access is restored, operations will resume automatically.",
                'pushoverMessage' => "🚨 {$exchangeTitle} API denied on {$ip}".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Check API Settings',
            ],

            'api_rate_limit_exceeded' => [
                'severity' => NotificationSeverity::Medium,
                'title' => 'Rate Limit Exceeded',
                'emailMessage' => "{$exchangeTitle} API rate limit exceeded.\n\nServer IP: {$ip}\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."\nThe system has implemented request throttling and backoff. Pending operations are queued for retry.\n\nOther worker servers continue normal operation. This server will automatically resume normal request frequency within seconds to minutes.\n\nIf rate limits persist across multiple servers, review exchange API usage patterns or consider increasing rate limit tiers with {$exchangeTitle}.",
                'pushoverMessage' => "{$exchangeTitle} rate limit on {$ip}".($accountInfo ? " - {$accountInfo}" : ''),
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
                'emailMessage' => "⚠️ NETWORK CONNECTIVITY FAILURE\n\nServer cannot reach {$exchangeTitle} API endpoints.\nServer IP: [COPY]{$ip}[/COPY]\n\n📊 SYSTEM STATUS:\n\nThis worker server is unable to establish network connection to {$exchangeTitle}'s API servers. Connection failure is typically transient and resolves automatically.\n\n🔄 AUTOMATIC RECOVERY:\n\nThe platform is:\n\n- Continuously retrying connection attempts with exponential backoff\n- Monitoring {$exchangeTitle} API endpoint availability\n- Queuing failed operations for retry when connection restores\n- Routing new requests to other operational worker servers\n- Logging detailed connection diagnostics for analysis\n\n🔍 ROOT CAUSE ANALYSIS:\n\nNetwork connection failures typically stem from:\n\n- Temporary network interruption or packet loss on this server\n- {$exchangeTitle} API servers under maintenance or experiencing outages\n- ISP or datacenter routing issues affecting this server's network path\n- Exchange-side API downtime or DDoS mitigation measures\n- Firewall, security group, or network ACL configuration changes\n- DNS resolution failures for {$exchangeTitle} API domains\n\n✅ ADMIN TROUBLESHOOTING STEPS:\n\nIf this persists beyond 5-10 minutes:\n\n1. Check {$exchangeTitle}'s status/announcement page for known incidents\n2. Verify server network connectivity (ping, traceroute to {$exchangeTitle} domains)\n3. Check firewall rules and security group configurations\n4. Review DNS resolution for {$exchangeTitle} API endpoints\n5. Verify no recent network infrastructure changes\n6. Check server system logs for network-related errors\n7. Monitor if issue affects multiple servers or just this one\n\n🔧 PLATFORM RESILIENCE:\n\nLoad balancing ensures operations continue on other worker servers. This server will automatically resume normal operation once connectivity is restored. No manual intervention required unless the issue persists beyond 10 minutes.",
                'pushoverMessage' => "⚠️ {$exchangeTitle} connection failed on {$ip} - auto-retrying",
                'actionUrl' => self::getExchangeStatusUrl($exchange),
                'actionLabel' => 'Check Exchange Status',
            ],

            // NEW: Binance ambiguous error -2015 (covers credentials, IP whitelist, or permissions)
            'api_credentials_or_ip' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'API Access Issue Detected',
                'emailMessage' => "⚠️ ACTION REQUIRED: {$exchangeTitle} Access Problem\n\nWe're experiencing issues accessing your {$exchangeTitle} account.\n\nAccount: {$accountName}\nServer IP: [COPY]{$ip}[/COPY]\n\n🔍 POSSIBLE CAUSES:\n\nBinance error code -2015 can indicate ONE of these three issues:\n\n1. **Invalid API Credentials**\n   Your API key may be invalid, expired, or deleted\n\n2. **IP Not Whitelisted**\n   The server IP above may not be in your API key's whitelist\n\n3. **Insufficient API Permissions**\n   Your API key may lack required permissions (Read, Trade, Futures)\n\n✅ TROUBLESHOOTING STEPS:\n\n1. Log into your {$exchangeTitle} account\n2. Go to API Management\n3. Verify your API key is active and not expired\n4. Check that the server IP above is in your whitelist (or whitelist is disabled)\n5. Confirm these permissions are enabled:\n   - Read/View permissions ✓\n   - Trading permissions ✓\n   - Futures permissions ✓ (if you trade futures)\n6. If anything is missing or incorrect, update it and save\n\nOnce fixed, our platform will automatically reconnect within minutes.",
                'pushoverMessage' => "⚠️ {$exchangeTitle} access issue: {$accountName} - Check API key, IP whitelist, or permissions",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Fix API Settings',
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

            // NEW: Critical account status notifications
            'api_key_expired' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'URGENT: API Key Expired',
                'emailMessage' => "⚠️ IMMEDIATE ACTION REQUIRED ⚠️\n\nYour {$exchangeTitle} API key has expired and needs immediate renewal.\n\nAccount: {$accountName}\n\n🚨 CRITICAL IMPACT:\n\n- We can NO LONGER access your {$exchangeTitle} account\n- We CANNOT execute trades on your behalf\n- We CANNOT monitor your open positions\n- We CANNOT manage risk or close positions\n\nIf you have open positions, they are at risk.\n\n✅ WHAT YOU NEED TO DO NOW:\n\n1. Log into your {$exchangeTitle} account IMMEDIATELY\n2. Go to API Management\n3. Generate a new API key\n4. Update the API key in our platform\n5. Ensure all permissions are enabled (Read, Trade, Futures)\n\nTime is critical. Please act now to restore trading functionality.",
                'pushoverMessage' => "🚨 URGENT: {$exchangeTitle} API key expired for {$accountName} - Renew NOW!",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Renew API Key NOW',
            ],

            'account_in_liquidation' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'CRITICAL: Account in Liquidation',
                'emailMessage' => "🚨 CRITICAL ALERT: Account Liquidation in Progress\n\nYour {$exchangeTitle} account is currently undergoing liquidation.\n\nAccount: {$accountName}\n\n⚠️ WHAT'S HAPPENING:\n\n{$exchangeTitle} is automatically closing your positions due to insufficient margin. This is controlled by the exchange, not our platform.\n\n🚨 CRITICAL IMPACT:\n\n- We CANNOT stop the liquidation process\n- We CANNOT execute new trades\n- We CANNOT modify existing orders\n- Account operations are severely restricted\n\n✅ WHAT YOU CAN DO:\n\n1. Log into your {$exchangeTitle} account directly\n2. Add funds immediately if possible to stop further liquidation\n3. Monitor which positions are being liquidated\n4. Review your margin requirements\n5. Consider adjusting leverage settings after liquidation completes\n\nThe liquidation process is automatic and controlled by {$exchangeTitle}. Once complete, you can resume normal trading.",
                'pushoverMessage' => "🚨 CRITICAL: {$accountName} on {$exchangeTitle} is being liquidated!",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'View Account Status',
            ],

            'account_reduce_only_mode' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'URGENT: Account in Reduce-Only Mode',
                'emailMessage' => "⚠️ URGENT: Trading Restricted\n\nYour {$exchangeTitle} account has been placed in reduce-only mode.\n\nAccount: {$accountName}\n\n🚨 WHAT THIS MEANS:\n\n- You can ONLY close or reduce existing positions\n- You CANNOT open new positions\n- You CANNOT increase position sizes\n- Account is under risk control restrictions\n\n📊 WHY THIS HAPPENS:\n\nReduce-only mode is typically triggered by:\n\n- Risk management rules\n- Margin requirements not met\n- Account under review\n- Compliance restrictions\n- Exchange-imposed limitations\n\n✅ WHAT YOU NEED TO DO:\n\n1. Log into your {$exchangeTitle} account\n2. Check account status and notifications\n3. Review margin requirements and balances\n4. Close or reduce positions if needed\n5. Contact {$exchangeTitle} support if unclear why this was triggered\n6. Add funds if margin-related\n\nUntil this restriction is lifted, you can only reduce your exposure, not increase it.",
                'pushoverMessage' => "⚠️ URGENT: {$accountName} on {$exchangeTitle} in reduce-only mode",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Check Account Status',
            ],

            'account_trading_banned' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'CRITICAL: Trading Banned',
                'emailMessage' => "🚨 CRITICAL: Trading Completely Banned\n\nYour {$exchangeTitle} account has been banned from placing new orders.\n\nAccount: {$accountName}\n\n⚠️ CRITICAL IMPACT:\n\n- You CANNOT place any new orders\n- You CANNOT modify existing orders  \n- You MAY be able to cancel orders\n- Account operations are severely restricted\n\n🔍 POSSIBLE REASONS:\n\n- Account compliance issues\n- Violation of exchange terms\n- Regulatory restrictions\n- Security hold on account\n- Region-specific limitations\n\n✅ IMMEDIATE ACTIONS:\n\n1. Log into your {$exchangeTitle} account NOW\n2. Check for messages or alerts from {$exchangeTitle}\n3. Review account restrictions section\n4. Contact {$exchangeTitle} support immediately to understand why\n5. Resolve any compliance or verification issues\n\nIf you have open positions, manually monitor them on {$exchangeTitle} directly until this is resolved.\n\nThis is a serious restriction that requires immediate attention and resolution through {$exchangeTitle} support.",
                'pushoverMessage' => "🚨 CRITICAL: {$accountName} on {$exchangeTitle} - Trading BANNED!",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Contact Support NOW',
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

            'account_unauthorized' => [
                'severity' => NotificationSeverity::High,
                'title' => 'Unauthorized Operation',
                'emailMessage' => "⚠️ WARNING: Unauthorized Operation Attempted\n\nAn operation was attempted on your {$exchangeTitle} account that you don't have authorization for.\n\nAccount: {$accountName}\n\n🔍 WHAT HAPPENED:\n\nYour account tried to perform an operation but lacked the necessary permissions or authority.\n\n📊 POSSIBLE REASONS:\n\n- API key permissions are insufficient\n- Account type doesn't support this operation\n- Feature requires higher verification level\n- Regional restrictions apply\n- Account status prevents this action\n\n✅ TROUBLESHOOTING STEPS:\n\n1. Log into your {$exchangeTitle} account\n2. Check your account type and status\n3. Review API key permissions\n4. Verify your account has access to the required features\n5. Check for any account restrictions or holds\n6. Ensure you've completed required verifications\n\nIf you believe this is an error, contact {$exchangeTitle} support to verify your account permissions.",
                'pushoverMessage' => "⚠️ {$accountName} on {$exchangeTitle}: Unauthorized operation attempted",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Check Permissions',
            ],

            // NEW: Admin monitoring notifications
            'api_system_error' => [
                'severity' => NotificationSeverity::High,
                'title' => 'API System Error Detected',
                'emailMessage' => "⚠️ SYSTEM ALERT: Exchange API Error\n\n{$exchangeTitle} API returned an unexpected error.\nServer IP: [COPY]{$ip}[/COPY]\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."\n\n🔍 ERROR TYPE:\n\nUnknown error, timeout, or unexpected response from {$exchangeTitle} API.\n\n📊 POSSIBLE CAUSES:\n\n- Exchange backend timeout\n- Exchange system error\n- Malformed API response\n- Exchange service degradation\n- Temporary exchange infrastructure issue\n\n✅ SYSTEM STATUS:\n\nThe platform will automatically retry failed operations. If this persists, the exchange may be experiencing technical difficulties.\n\n🔄 MONITORING:\n\n- Check {$exchangeTitle} status page for incidents\n- Monitor if errors continue across multiple servers\n- Review error logs for patterns\n\n".($accountInfo ? 'User accounts may be affected if this continues.' : 'System-level operations affected.'),
                'pushoverMessage' => "⚠️ {$exchangeTitle} system error on {$ip}".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => self::getExchangeStatusUrl($exchange),
                'actionLabel' => 'Check Exchange Status',
            ],

            'api_network_error' => [
                'severity' => NotificationSeverity::High,
                'title' => 'API Network Error',
                'emailMessage' => "⚠️ SYSTEM ALERT: Network Connectivity Issue\n\nServer cannot establish network connection to {$exchangeTitle} API.\nServer IP: [COPY]{$ip}[/COPY]\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."\n\n🔍 ERROR TYPE:\n\nNetwork-level connectivity failure preventing API communication.\n\n📊 POSSIBLE CAUSES:\n\n- Server network connectivity issues\n- ISP routing problems\n- DNS resolution failures\n- Firewall or security group blocking traffic\n- {$exchangeTitle} API endpoint unavailable\n- DDoS mitigation blocking our IP\n\n✅ SYSTEM STATUS:\n\nThe platform will automatically retry. Other worker servers continue normal operation.\n\n🔧 TROUBLESHOOTING:\n\nIf this persists beyond 5-10 minutes:\n\n- Verify server network connectivity (ping, traceroute)\n- Check firewall rules and security groups\n- Verify DNS resolution for {$exchangeTitle} domains\n- Check if {$exchangeTitle} has IP restrictions\n- Review exchange status page for API outages\n\n".($accountInfo ? 'User account operations affected on this server only.' : 'System operations affected on this server only.'),
                'pushoverMessage' => "⚠️ {$exchangeTitle} network error on {$ip}".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => self::getExchangeStatusUrl($exchange),
                'actionLabel' => 'Check Network Status',
            ],

            'binance_websocket_error', 'bybit_websocket_error' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} WebSocket Error",
                'emailMessage' => "⚠️ WEBSOCKET CONNECTION FAILURE\n\n{$exchangeTitle} price streaming WebSocket encountered an error on server [COPY]{$ip}[/COPY].\n\n📊 SYSTEM IMPACT:\n\nThe WebSocket connection has been interrupted. Real-time price updates from {$exchangeTitle} are affected on this worker server only.\n\n🔄 AUTOMATIC RECOVERY PROCESS:\n\nThe platform is:\n\n- Automatically attempting reconnection with exponential backoff\n- Continuing price streaming from {$exchangeTitle} on other worker servers\n- Queuing missed price updates for reconciliation when connection restores\n- Monitoring connection stability and logging detailed error diagnostics\n- Maintaining price data integrity across remaining active connections\n\n✅ EXPECTED RESOLUTION:\n\nWebSocket connections typically restore automatically within seconds to minutes. The Horizon supervisor will restart the worker process if reconnection attempts fail after exhausting retry policy.\n\n🔍 ADMIN INVESTIGATION (IF PERSISTENT):\n\nIf errors persist beyond 10 minutes:\n\n1. Check {$exchangeTitle} API status page for WebSocket service incidents\n2. Review server network connectivity and stability\n3. Verify firewall rules allow WebSocket protocols (wss://)\n4. Check for rate limiting or IP-based restrictions from {$exchangeTitle}\n5. Review Horizon logs for WebSocket worker process errors\n6. Verify server resources (CPU, memory) are not constrained\n7. Monitor if issue affects single server or distributed across multiple nodes\n\n🔧 MANUAL INTERVENTION:\n\nNo immediate action required unless this error persists for more than 10 minutes across multiple worker servers, indicating a platform-wide connectivity issue.",
                'pushoverMessage' => "⚠️ {$exchangeTitle} WebSocket error on {$ip}",
                'actionUrl' => self::getExchangeStatusUrl($exchange),
                'actionLabel' => 'Check Exchange Status',
            ],

            'binance_invalid_json', 'bybit_invalid_json' => [
                'severity' => NotificationSeverity::Medium,
                'title' => "{$exchangeTitle} Invalid Data",
                'emailMessage' => "⚠️ DATA VALIDATION ERROR\n\n{$exchangeTitle} WebSocket returned invalid JSON data on server [COPY]{$ip}[/COPY].\n\n📊 ERROR DETAILS:\n\nThe price streaming service received malformed or unparseable data from {$exchangeTitle}'s WebSocket API. This could indicate:\n\n- Temporary API transmission errors or corrupt data packets\n- Protocol changes on {$exchangeTitle}'s WebSocket implementation\n- Network packet corruption during transmission\n- API server issues during high load or degraded performance\n- Incomplete message fragments due to connection instability\n\n✅ AUTOMATIC ERROR HANDLING:\n\nThe platform has:\n\n- Discarded the invalid data to prevent processing errors and data corruption\n- Continued listening for subsequent valid price updates\n- Logged the raw malformed data for debugging and forensic analysis\n- Maintained price data integrity by rejecting corrupted messages\n- Preserved the last known valid price state\n\n🔄 EXPECTED BEHAVIOR:\n\nThis is typically a transient issue. The next price update from {$exchangeTitle} should be valid. The WebSocket consumer continues monitoring and will process subsequent valid data normally.\n\n🔍 ADMIN INVESTIGATION (IF PERSISTENT):\n\nManual investigation required if:\n\n- Error occurs repeatedly (more than 5 times in 30 minutes)\n- All price updates from {$exchangeTitle} are failing validation\n- Multiple worker servers report the same issue simultaneously\n- Price data shows unusual gaps or staleness\n\n✅ TROUBLESHOOTING STEPS:\n\n1. Check application logs for pattern of invalid JSON messages\n2. Review raw WebSocket data to identify data format changes\n3. Verify {$exchangeTitle} hasn't updated their WebSocket protocol\n4. Check for network issues causing packet corruption\n5. Monitor if issue is isolated to single server or platform-wide\n6. Review {$exchangeTitle} API changelog for breaking changes\n\n⚡ IMPACT:\n\nClient trading operations continue normally. Price updates are maintained from other valid messages and redundant worker servers.",
                'pushoverMessage' => "⚠️ {$exchangeTitle} sent invalid JSON on {$ip}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_prices_restart', 'bybit_prices_restart' => [
                'severity' => NotificationSeverity::Info,
                'title' => "{$exchangeTitle} Price Stream Restart",
                'emailMessage' => "ℹ️ SYSTEM UPDATE\n\n{$exchangeTitle} price monitoring is restarting on server [COPY]{$ip}[/COPY] due to symbol configuration changes.\n\n📊 WHAT HAPPENED:\n\nThe number of trading pairs being monitored on {$exchangeTitle} has changed. The WebSocket connection is being restarted to subscribe to the updated symbol list.\n\n✅ AUTOMATIC PROCESS:\n\nThis is a normal operational event. The platform:\n\n- Detected new trading pairs or removed inactive pairs\n- Gracefully closed the existing WebSocket connection\n- Will reconnect with the updated subscription list\n- Resume real-time price streaming within seconds\n\n🔄 NO ACTION REQUIRED:\n\nThis restart is automated and expected during:\n\n- Addition of new trading pairs to your portfolio\n- Removal of delisted or inactive trading pairs\n- Bulk symbol configuration updates\n- Exchange symbol list synchronization\n\nPrice streaming will resume automatically. Your trading operations are unaffected as other servers continue normal operation during this brief restart.",
                'pushoverMessage' => "ℹ️ {$exchangeTitle} price stream restarting on {$ip}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_db_update_error', 'bybit_db_update_error' => [
                'severity' => NotificationSeverity::Critical,
                'title' => "{$exchangeTitle} Database Error",
                'emailMessage' => "⚠️ CRITICAL: DATABASE UPDATE FAILED\n\nFailed to save {$exchangeTitle} price update to database on server [COPY]{$ip}[/COPY].\n\n🚨 CRITICAL IMPACT:\n\nPrice data from {$exchangeTitle} is being received but NOT being persisted to the database. This affects:\n\n- Historical price accuracy\n- Trading algorithm data integrity\n- Position value calculations\n- Portfolio performance tracking\n\n✅ IMMEDIATE INVESTIGATION REQUIRED:\n\nPossible causes:\n\n1. Database connection pool exhaustion\n2. Disk space full on database server\n3. Database server performance degradation\n4. Network connectivity issues between app and database\n5. Database table corruption or lock issues\n6. Permission/authentication problems\n\n🔍 TROUBLESHOOTING STEPS:\n\n1. Check database server health and disk space\n2. Review database connection pool status\n3. Verify network connectivity to database\n4. Check database server logs for errors\n5. Monitor database performance metrics\n6. Review recent database schema changes\n\n⚡ PRIORITY ACTION:\n\nThis error requires immediate attention to prevent data loss and ensure trading algorithm accuracy. Price updates continue on other servers, but this server's data integrity is compromised until resolved.",
                'pushoverMessage' => "🚨 {$exchangeTitle} DB error on {$ip} - price data not saving!",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'binance_db_insert_error', 'bybit_db_insert_error' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} History Insert Error",
                'emailMessage' => "⚠️ DATABASE INSERT FAILED\n\nFailed to insert {$exchangeTitle} price history record on server [COPY]{$ip}[/COPY].\n\n📊 IMPACT:\n\nPrice history data (5-minute intervals) is not being saved to the database. This affects:\n\n- Historical chart data completeness\n- Long-term price analysis accuracy\n- Backtesting data integrity\n- Performance reporting historical context\n\n✅ CURRENT OPERATIONS:\n\nReal-time price updates continue normally. Only the historical price snapshot (taken every 5 minutes) failed to save.\n\n🔍 POSSIBLE CAUSES:\n\n- Database connection issues during snapshot\n- Table lock or deadlock during high load\n- Disk space constraints on database server\n- Duplicate key constraint violations\n- Permission issues on price_histories table\n\n🔄 AUTOMATIC RETRY:\n\nThe next 5-minute snapshot will attempt to save normally. If successful, only one historical data point will be missing.\n\n⚡ ACTION REQUIRED IF PERSISTENT:\n\nIf this error occurs repeatedly:\n\n1. Check database server health and available disk space\n2. Review price_histories table for any corruption\n3. Verify database connection pool has available connections\n4. Check for any schema migration issues\n5. Monitor database server load and performance",
                'pushoverMessage' => "⚠️ {$exchangeTitle} history insert error on {$ip}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'step_error' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'Step Execution Error',
                'emailMessage' => "⚠️ CRITICAL: STEP EXECUTION FAILED\n\nA background job step encountered an error during execution.\n\n🚨 IMPACT:\n\nA specific task in the job processing queue has failed. This may affect:\n\n- Automated trading operations\n- Data synchronization processes\n- Account balance updates\n- Order status monitoring\n- Position management workflows\n\n📊 SYSTEM BEHAVIOR:\n\nThe step has been marked as failed and logged for investigation. Depending on the step configuration:\n\n- It may be automatically retried with exponential backoff\n- It may require manual intervention to resolve\n- Dependent steps may be blocked until this resolves\n- Other independent workflows continue normally\n\n✅ AUTOMATIC MONITORING:\n\nThe platform:\n\n- Logs detailed error information for debugging\n- Notifies administrators of failure patterns\n- Tracks retry attempts and success rates\n- Maintains job queue integrity\n- Prevents cascading failures\n\n🔍 INVESTIGATION:\n\nReview the step error logs to identify:\n\n- Root cause of the failure\n- Affected trading pairs or accounts\n- Whether retry will resolve the issue\n- Need for code fixes or configuration changes\n\n⚡ PRIORITY:\n\nDepending on the failed step type, this may require immediate attention to prevent trading disruption or data inconsistencies.",
                'pushoverMessage' => '🚨 Step execution failed - check logs for details',
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'stale_price_detected' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} Stale Prices Detected",
                'emailMessage' => "⚠️ PRICE UPDATE DELAY\n\n{$exchangeTitle} price updates are significantly delayed.\n\n📊 SYSTEM STATUS:\n\nPrice data from {$exchangeTitle} has not been updated within the expected timeframe. This indicates:\n\n- WebSocket connection may be stalled or disconnected\n- {$exchangeTitle} API may be experiencing issues\n- Network connectivity problems on worker servers\n- Price streaming service process interruption\n\n🚨 PLATFORM IMPACT:\n\nStale prices affect client operations:\n\n- Entry/exit price accuracy for trading algorithms\n- Stop-loss and take-profit trigger calculations\n- Position value and P&L computations\n- Risk management system decisions\n- Real-time monitoring dashboards\n\n✅ AUTOMATIC RECOVERY:\n\nThe platform is:\n\n- Attempting to restart the WebSocket connection\n- Monitoring {$exchangeTitle} API health\n- Queuing operations for execution when prices resume\n- Logging detailed diagnostics for troubleshooting\n\n🔍 ADMIN INVESTIGATION STEPS:\n\n1. Check {$exchangeTitle} API status page for known outages\n2. Verify WebSocket supervisor process is running on all worker servers\n3. Review server network connectivity and firewall rules\n4. Check application logs for WebSocket error details\n5. Verify {$exchangeTitle} is not under scheduled maintenance\n6. Monitor database for any performance bottlenecks\n\n⚡ RECOVERY:\n\nThe WebSocket connection will be automatically restarted. You'll receive a confirmation notification when price updates resume. If the issue persists beyond 10 minutes, manual intervention may be required.",
                'pushoverMessage' => "⚠️ {$exchangeTitle} prices are stale - WebSocket restart triggered",
                'actionUrl' => self::getExchangeStatusUrl($exchange),
                'actionLabel' => 'Check Exchange Status',
            ],

            'forbidden_hostname_added' => [
                'severity' => NotificationSeverity::Critical,
                'title' => "{$exchangeTitle} Server IP Forbidden",
                'emailMessage' => "🚨 CRITICAL: SERVER BANNED BY EXCHANGE\n\nServer [COPY]{$ip}[/COPY] has been forbidden from accessing {$exchangeTitle} API.\n\n⚠️ CRITICAL IMPACT:\n\nThis server can NO LONGER communicate with {$exchangeTitle}. This severely impacts:\n\n- Trading operations capacity\n- System redundancy and failover\n- Load distribution across worker servers\n- Overall platform reliability\n\n🔍 POSSIBLE CAUSES:\n\n1. Repeated API violations or abuse detection\n2. Suspicious activity patterns flagged by {$exchangeTitle}\n3. Too many failed authentication attempts\n4. Rate limit violations from this IP address\n5. Security incident or compromise detected\n6. Violating {$exchangeTitle}'s terms of service\n\n✅ IMMEDIATE ACTIONS REQUIRED:\n\n1. Contact {$exchangeTitle} support immediately\n2. Request details on why the IP was banned\n3. Provide justification that this is a legitimate trading bot\n4. Request IP unban or whitelist restoration\n5. Review server logs for any suspicious activity\n6. Implement additional rate limiting if needed\n\n🔄 TEMPORARY MITIGATION:\n\nWhile this server is banned:\n\n- Other worker servers continue handling {$exchangeTitle} operations\n- Trading continues but with reduced redundancy\n- Consider adding additional worker servers if capacity is affected\n\n⚡ PRIORITY: IMMEDIATE\n\nThis requires urgent attention to restore full operational capacity and system redundancy.",
                'pushoverMessage' => "🚨 {$ip} forbidden by {$exchangeTitle} - contact support!",
                'actionUrl' => self::getApiManagementUrl($exchange),
                'actionLabel' => 'Contact Exchange Support',
            ],

            'server_ip_whitelisted' => [
                'severity' => NotificationSeverity::Info,
                'title' => "{$exchangeTitle} Server IP Whitelisted",
                'emailMessage' => "✅ SUCCESS: Server IP Whitelisted\n\nServer [COPY]{$ip}[/COPY] has been successfully whitelisted on {$exchangeTitle}.\n\n🎉 GREAT NEWS:\n\nThe IP address is now authorized to access your {$exchangeTitle} account API. This means:\n\n- Trading operations can now execute from this server\n- System redundancy has been restored\n- Load balancing across worker servers is improved\n- Platform reliability is enhanced\n\n✅ AUTOMATIC RESUMPTION:\n\nThe platform will:\n\n- Automatically detect the whitelist status\n- Resume normal API operations from this server\n- Begin processing queued operations\n- Restore full trading capacity\n\nNo action is required. All systems are operating normally.",
                'pushoverMessage' => "✅ {$ip} whitelisted on {$exchangeTitle}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'symbol_synced' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Symbol Synced with CoinMarketCap',
                'emailMessage' => "ℹ️ SYMBOL SYNCHRONIZED\n\nA trading symbol has been successfully synced with CoinMarketCap data.\n\n📊 WHAT HAPPENED:\n\nThe platform matched a symbol from the exchange with its corresponding CoinMarketCap entry, enabling:\n\n- Market cap data tracking\n- Symbol metadata and information\n- Cross-exchange symbol correlation\n- Enhanced market analysis capabilities\n\n✅ AUTOMATIC PROCESS:\n\nThis is a routine data synchronization event. Symbol metadata is continuously updated to ensure:\n\n- Accurate symbol identification\n- Complete market information\n- Proper categorization and tagging\n- Enhanced trading algorithm context\n\nNo action required. This is informational only.",
                'pushoverMessage' => 'ℹ️ Symbol synced with CoinMarketCap',
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'notification_gateway_error' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'Notification Gateway Error',
                'emailMessage' => "🚨 CRITICAL: NOTIFICATION DELIVERY FAILED\n\nA notification could not be delivered due to a gateway error.\n\n⚠️ IMPACT:\n\nThe notification system encountered an error when attempting to deliver a message. This may indicate:\n\n- Pushover API connectivity issues\n- Email gateway (Zeptomail) delivery problems\n- Network connectivity failures\n- Invalid notification credentials or API keys\n- Rate limiting by notification gateways\n\n🔍 INVESTIGATION REQUIRED:\n\nReview the notification_logs table for:\n\n- Error message details\n- Gateway response codes\n- Failed delivery timestamps\n- Affected notification channels\n\n✅ AUTOMATIC RETRY:\n\nDepending on the error type:\n\n- Transient errors may be automatically retried\n- Permanent errors require manual intervention\n- Gateway credentials may need updating\n\n⚡ PRIORITY:\n\nNotification delivery is critical for system monitoring and alerts. Investigate immediately to restore notification service.",
                'pushoverMessage' => '🚨 Notification delivery failed - check gateway logs',
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
}
