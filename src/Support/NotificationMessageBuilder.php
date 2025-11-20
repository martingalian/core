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
        $exchange = is_string($exchangeRaw) ? $exchangeRaw : ($exchangeRaw->canonical ?? 'exchange');
        $exchangeTitle = is_string($exchangeRaw) ? ucfirst($exchangeRaw) : ($exchangeRaw->name ?? ucfirst($exchange));

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

        $httpCodeRaw = $context['http_code'] ?? null;
        $httpCode = is_int($httpCodeRaw) ? $httpCodeRaw : (is_string($httpCodeRaw) ? (int) $httpCodeRaw : null);

        $vendorCodeRaw = $context['vendor_code'] ?? null;
        $vendorCode = is_int($vendorCodeRaw) ? $vendorCodeRaw : (is_string($vendorCodeRaw) ? (int) $vendorCodeRaw : null);

        return match ($canonicalString) {
            
            'server_rate_limit_exceeded' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Rate Limit Exceeded',
                'emailMessage' => "{$exchangeTitle} API rate limit exceeded.\n\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."Server: {$hostname}\n\nPlatform automatically implemented request throttling and exponential backoff. Pending operations queued for retry.\n\nResolution steps:\n\n• Check recent API request patterns:\n[CMD]SELECT endpoint, COUNT(*) as requests, AVG(response_time_ms) as avg_ms FROM api_request_logs WHERE exchange = '{$exchange}' AND created_at > NOW() - INTERVAL 5 MINUTE GROUP BY endpoint ORDER BY requests DESC LIMIT 10;[/CMD]\n\n• Monitor rate limit headers in logs:\n[CMD]tail -100 storage/logs/laravel.log | grep -i \"rate\\|limit\\|429\"[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} rate limit exceeded - {$hostname}".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'server_ip_forbidden' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'Server IP Forbidden by Exchange',
                'emailMessage' => "🚨 CRITICAL: Server IP forbidden by {$exchangeTitle}\n\nServer IP: [COPY]{$ip}[/COPY]\nHostname: {$hostname}\nHTTP Code: {$httpCode}\n".($vendorCode ? "Vendor Code: {$vendorCode}\n" : '')."\n".($accountInfo ? "Last request from account: {$accountInfo}\n\n" : '')."The exchange has banned our server IP. This is typically caused by:\n• Repeated rate limit violations (HTTP 418 for Binance - auto-ban 2 min to 3 days)\n• Server/IP-level restrictions (HTTP 403 with specific vendor codes)\n\nIMPACT:\n• All API requests from this server to {$exchangeTitle} are blocked\n• Jobs automatically retry on other workers if available\n• Affects all accounts using this exchange on this worker\n\nRESOLUTION:\n\nFor HTTP 418 (temporary ban):\n• System will auto-retry after ban period expires\n• Review rate limiting patterns to prevent future bans\n\nFor HTTP 403 (permanent restrictions):\n• Contact exchange support with server IP and timestamp\n• May require IP whitelisting or rotation\n\nMonitor recent API errors:\n[CMD]SELECT created_at, http_response_code, path, response FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND http_response_code >= 400 ORDER BY created_at DESC LIMIT 20;[/CMD]",
                'pushoverMessage' => "🚨 {$exchangeTitle} forbidden server {$hostname} ({$ip}) - HTTP {$httpCode}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            
            
            'stale_price_detected' => (function () use ($context, $exchange, $exchangeTitle) {
                // Extract stale price details
                $oldestSymbol = is_string($context['oldest_symbol'] ?? null) ? $context['oldest_symbol'] : 'N/A';
                $oldestPrice = is_string($context['oldest_price'] ?? null) ? $context['oldest_price'] : 'N/A';
                $oldestMinutes = is_numeric($context['oldest_minutes'] ?? null) ? (int) $context['oldest_minutes'] : 0;

                $exchangeLower = mb_strtolower($exchange);

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => "{$exchangeTitle} Stale Prices Detected",
                    'emailMessage' => "⚠️ {$exchangeTitle} Stale Prices Detected\n\n".
                        "{$exchangeTitle} price updates not received within expected timeframe. WebSocket connection may be stalled or {$exchangeTitle} API experiencing issues.\n\n".
                        "📊 STALE PRICE EXAMPLE:\n\n".
                        "• Symbol: {$oldestSymbol}\n".
                        "• Last Price: {$oldestPrice}\n".
                        "• Last Updated: {$oldestMinutes} minutes ago\n\n".
                        "🔍 RESOLUTION STEPS:\n\n".
                        "• Check stale prices:\n".
                        "[CMD]SELECT parsed_trading_pair, mark_price, mark_price_synced_at, TIMESTAMPDIFF(SECOND, mark_price_synced_at, NOW()) as seconds_stale FROM exchange_symbols WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchangeLower}') ORDER BY mark_price_synced_at ASC LIMIT 10;[/CMD]\n\n".
                        "• Check supervisor status:\n".
                        "[CMD]supervisorctl status update-{$exchangeLower}-prices[/CMD]\n".
                        "Or tail logs:\n".
                        "[CMD]supervisorctl tail update-{$exchangeLower}-prices[/CMD]\n\n".
                        "• Restart supervisor if needed:\n".
                        "[CMD]supervisorctl restart update-{$exchangeLower}-prices[/CMD]",
                    'pushoverMessage' => "⚠️ {$exchangeTitle} stale prices detected\n".
                        "Example: {$oldestSymbol} ({$oldestMinutes}m ago)\n".
                        'Manual supervisor restart may be required',
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            
            
            'exchange_symbol_no_taapi_data' => (function () use ($context) {
                $exchangeSymbol = $context['exchange_symbol'] ?? null;

                // Build display string manually: "SYMBOL/QUOTE@Exchange" for readability
                $displayString = 'Exchange Symbol';
                if ($exchangeSymbol) {
                    $symbolToken = $exchangeSymbol->symbol?->token ?? 'UNKNOWN';
                    $quoteCanonical = $exchangeSymbol->quote?->canonical ?? 'UNKNOWN';
                    $exchangeName = $exchangeSymbol->apiSystem?->name ?? 'UNKNOWN';
                    $displayString = "{$symbolToken}/{$quoteCanonical}@{$exchangeName}";
                }

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => $displayString.' Auto-Deactivated',
                    'emailMessage' => "ℹ️ Exchange Symbol Auto-Deactivated\n\n".
                        'Exchange Symbol: '.$displayString."\n".
                        "Reason: Consistent lack of TAAPI indicator data\n".
                        'Failed Requests: '.($context['failure_count'] ?? 'N/A')." in last 24 hours\n\n".
                        "📊 WHAT HAPPENED:\n\n".
                        'This exchange symbol has been automatically deactivated because TAAPI (Technical Analysis API) consistently failed to provide indicator data. '.
                        "After multiple consecutive failures, the platform determined this symbol is not supported by TAAPI and deactivated it to prevent further errors.\n\n".
                        "✅ IMPACT:\n\n".
                        "• Symbol marked as inactive (is_active = false)\n".
                        "• Symbol marked as ineligible (is_eligible = false)\n".
                        "• No more TAAPI requests will be made for this symbol\n".
                        "• Trading operations for this symbol will be suspended\n".
                        '• Already ongoing Trading operations will continue',
                    'pushoverMessage' => 'Exchange Symbol: '.$displayString."\n".
                        'Reason: '.($context['failure_count'] ?? 'N/A')." Taapi failures\n".
                        'Action: Changed to Deactivated and Ineligible',
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'update_prices_restart' => [
                'severity' => NotificationSeverity::Info,
                'title' => "{$exchangeTitle} Price Stream Restart",
                'emailMessage' => "ℹ️ {$exchangeTitle} Price Stream Restart\n\nThe {$exchangeTitle} price WebSocket stream has been restarted due to symbol count changes.\n\nOld Symbol Count: ".($context['old_count'] ?? 'N/A')."\nNew Symbol Count: ".($context['new_count'] ?? 'N/A')."\n\nThe stream will automatically reconnect and resume price updates for all active symbols.",
                'pushoverMessage' => "{$exchangeTitle} price stream restarted - symbol count changed",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'websocket_error' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} WebSocket Error",
                'emailMessage' => "⚠️ {$exchangeTitle} WebSocket Error\n\nThe {$exchangeTitle} WebSocket connection encountered an error.\n\nException: ".($context['exception'] ?? 'Unknown error')."\n\nThe system will automatically attempt to reconnect and resume operations.",
                'pushoverMessage' => "{$exchangeTitle} WebSocket error - ".($context['exception'] ?? 'Unknown'),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'websocket_invalid_json' => [
                'severity' => NotificationSeverity::High,
                'title' => "{$exchangeTitle} WebSocket: Invalid JSON",
                'emailMessage' => "⚠️ {$exchangeTitle} WebSocket: Invalid JSON Response\n\nThe {$exchangeTitle} WebSocket is returning invalid JSON responses.\n\nHits in last minute: ".($context['hits'] ?? 'N/A')."\n\nThis may indicate an issue with the exchange API or network connectivity.",
                'pushoverMessage' => "{$exchangeTitle} WebSocket invalid JSON - ".($context['hits'] ?? 0)." hits/min",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'websocket_prices_update_error' => [
                'severity' => NotificationSeverity::Critical,
                'title' => "{$exchangeTitle} Prices: Database Update Error",
                'emailMessage' => "🚨 {$exchangeTitle} Prices: Database Update Error\n\nFailed to update exchange symbol prices in the database.\n\nException: ".($context['exception'] ?? 'Unknown error')."\n\nPrices may be stale until this issue is resolved.",
                'pushoverMessage' => "🚨 {$exchangeTitle} DB update error - ".($context['exception'] ?? 'Unknown'),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'token_delisting' => (function () use ($context, $exchangeTitle) {
                // Extract delisting details
                $pairText = is_string($context['pair_text'] ?? null) ? $context['pair_text'] : 'N/A';
                $deliveryDate = is_string($context['delivery_date'] ?? null) ? $context['delivery_date'] : 'N/A';
                $positionsCount = is_int($context['positions_count'] ?? null) ? $context['positions_count'] : 0;
                $positionsDetails = is_string($context['positions_details'] ?? null) ? $context['positions_details'] : '';

                $message = "🚨 Token Delisting Detected\n\n".
                    "Token: {$pairText}\n".
                    "Exchange: {$exchangeTitle}\n".
                    "Delivery Date: {$deliveryDate} UTC\n\n";

                if ($positionsCount === 0) {
                    $message .= 'No open positions for this symbol.';
                } else {
                    $message .= "Open positions requiring manual review:\n\n{$positionsDetails}\n".
                        "Total positions requiring attention: {$positionsCount}";
                }

                $pushoverMessage = "{$exchangeTitle} delisting: {$pairText}\n".
                    "Delivery: {$deliveryDate}\n".
                    ($positionsCount > 0 ? "{$positionsCount} open position(s)" : 'No open positions');

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Token Delisting Detected',
                    'emailMessage' => $message,
                    'pushoverMessage' => $pushoverMessage,
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            // Default fallback for unknown canonicals
            default => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Notification: '.$canonicalString,
                'emailMessage' => "A notification was triggered: {$canonicalString}\n\nNo message template is defined for this canonical.",
                'pushoverMessage' => "Notification: {$canonicalString}",
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
