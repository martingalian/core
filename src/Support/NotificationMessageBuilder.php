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

        // Support both Eloquent models (new format) and raw values (legacy format)
        // New format: 'apiSystem' => $model, 'apiRequestLog' => $model, 'account' => $model
        // Legacy format: 'exchange' => 'binance', 'http_code' => 418, etc.
        $apiSystem = $context['apiSystem'] ?? null;
        $apiRequestLog = $context['apiRequestLog'] ?? null;
        $accountModel = $context['account'] ?? null;
        $contextUser = $context['user'] ?? null;

        // Extract exchange info from apiSystem model or legacy 'exchange' key
        $exchangeRaw = $apiSystem ?? ($context['exchange'] ?? 'exchange');
        $exchange = is_object($exchangeRaw) ? ($exchangeRaw->canonical ?? 'exchange') : (is_string($exchangeRaw) ? $exchangeRaw : 'exchange');
        $exchangeTitle = is_object($exchangeRaw) ? ($exchangeRaw->name ?? ucfirst($exchange)) : (is_string($exchangeRaw) ? ucfirst($exchangeRaw) : ucfirst($exchange));

        // Extract IP from Martingalian model or legacy 'ip' key
        $ipRaw = $context['ip'] ?? null;
        $ip = is_string($ipRaw) ? $ipRaw : \Martingalian\Core\Models\Martingalian::ip();

        // Extract hostname from 'server' key (new) or 'hostname' key (legacy) or apiRequestLog
        $hostnameRaw = $context['server'] ?? ($context['hostname'] ?? ($apiRequestLog?->hostname ?? gethostname()));
        $hostname = is_string($hostnameRaw) ? $hostnameRaw : (string) gethostname();

        // Build account info string from account model or legacy 'account_info' key
        $accountInfoRaw = $context['account_info'] ?? null;
        if ($accountInfoRaw === null && $accountModel && $contextUser) {
            $accountInfo = "{$contextUser->name} ({$accountModel->name})";
        } elseif ($accountInfoRaw === null && $accountModel) {
            $accountInfo = $accountModel->name;
        } else {
            $accountInfo = is_string($accountInfoRaw) ? $accountInfoRaw : null;
        }

        $accountNameRaw = $context['account_name'] ?? ($accountModel?->name ?? 'your account');
        $accountName = is_string($accountNameRaw) ? $accountNameRaw : 'your account';

        $walletBalanceRaw = $context['wallet_balance'] ?? 'N/A';
        $walletBalance = is_string($walletBalanceRaw) ? $walletBalanceRaw : 'N/A';

        $unrealizedPnlRaw = $context['unrealized_pnl'] ?? 'N/A';
        $unrealizedPnl = is_string($unrealizedPnlRaw) ? $unrealizedPnlRaw : 'N/A';

        $exceptionRaw = $context['exception'] ?? null;
        $exception = is_string($exceptionRaw) ? $exceptionRaw : null;

        // Extract HTTP code from apiRequestLog model or legacy 'http_code' key
        $httpCodeRaw = $apiRequestLog?->http_response_code ?? ($context['http_code'] ?? null);
        $httpCode = is_int($httpCodeRaw) ? $httpCodeRaw : (is_string($httpCodeRaw) ? (int) $httpCodeRaw : null);

        // Extract vendor code from apiRequestLog response or legacy 'vendor_code' key
        $vendorCodeRaw = $context['vendor_code'] ?? null;
        if ($vendorCodeRaw === null && $apiRequestLog) {
            $response = $apiRequestLog->response;
            if (is_array($response)) {
                $vendorCodeRaw = $response['code'] ?? $response['retCode'] ?? null;
            }
        }
        $vendorCode = is_int($vendorCodeRaw) ? $vendorCodeRaw : (is_string($vendorCodeRaw) ? (int) $vendorCodeRaw : null);

        return match ($canonicalString) {

            'server_rate_limit_exceeded' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Rate Limit Exceeded',
                'emailMessage' => "{$exchangeTitle} API rate limit exceeded.\n\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."Server: {$hostname}\n\nPlatform automatically implemented request throttling and exponential backoff. Pending operations queued for retry.\n\nResolution steps:\n\n1. Check recent API request patterns:\n[CMD]SELECT path, COUNT(*) as requests, AVG(duration) as avg_ms FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND created_at > NOW() - INTERVAL 5 MINUTE GROUP BY path ORDER BY requests DESC LIMIT 10;[/CMD]\n\n2. Check recent rate limit errors:\n[CMD]SELECT created_at, path, http_response_code, hostname FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND http_response_code = 429 AND created_at > NOW() - INTERVAL 1 HOUR ORDER BY created_at DESC LIMIT 10;[/CMD]",
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

            'server_ip_rate_limited' => (function () use ($context, $exchangeTitle, $ip, $hostname) {
                // Extract rate limit details
                $forbiddenUntilRaw = $context['forbidden_until'] ?? null;
                $errorCode = is_string($context['error_code'] ?? null) ? $context['error_code'] : 'N/A';
                $errorMessage = is_string($context['error_message'] ?? null) ? $context['error_message'] : 'N/A';

                // Parse forbidden_until for display
                $forbiddenUntilCarbon = null;
                if (is_string($forbiddenUntilRaw)) {
                    try {
                        $forbiddenUntilCarbon = \Carbon\Carbon::parse($forbiddenUntilRaw);
                    } catch (\Exception $e) {
                        // Invalid date format, ignore
                    }
                }

                $forbiddenText = $forbiddenUntilCarbon
                    ? "Rate limit expires: {$forbiddenUntilCarbon->format('H:i:s')} ({$forbiddenUntilCarbon->diffForHumans()})"
                    : 'Rate limit duration: Unknown (typically 2-10 minutes)';

                $pushoverRecovery = $forbiddenUntilCarbon
                    ? "Resumes: {$forbiddenUntilCarbon->format('H:i:s')}"
                    : 'Resumes: ~2-10 min';

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Server IP Temporarily Rate Limited',
                    'emailMessage' => "⚠️ Server IP Temporarily Rate Limited\n\n".
                        "The server IP has been temporarily rate-limited by {$exchangeTitle}.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Server IP: [COPY]{$ip}[/COPY]\n".
                        "• Hostname: {$hostname}\n".
                        "• {$forbiddenText}\n".
                        "• Error Code: {$errorCode}\n".
                        "• Error Message: {$errorMessage}\n\n".
                        "✅ AUTOMATIC RECOVERY:\n\n".
                        "• The system will automatically pause requests to {$exchangeTitle}\n".
                        "• Requests will resume after the rate limit expires\n".
                        "• No manual intervention required in most cases\n\n".
                        "🔍 IF ISSUE PERSISTS:\n\n".
                        "• Check recent API request volume:\n".
                        "[CMD]SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute, COUNT(*) as requests FROM api_request_logs WHERE created_at > NOW() - INTERVAL 30 MINUTE GROUP BY minute ORDER BY minute DESC;[/CMD]\n\n".
                        "• Review request patterns by endpoint:\n".
                        '[CMD]SELECT path, COUNT(*) as requests FROM api_request_logs WHERE created_at > NOW() - INTERVAL 10 MINUTE GROUP BY path ORDER BY requests DESC LIMIT 10;[/CMD]',
                    'pushoverMessage' => "{$exchangeTitle}: IP rate limited\nServer: {$hostname}\nError: {$errorCode} - {$errorMessage}\n{$pushoverRecovery}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'server_ip_banned' => (function () use ($context, $exchangeTitle, $ip, $hostname) {
                // Extract ban details
                $errorCode = is_string($context['error_code'] ?? null) ? $context['error_code'] : 'N/A';
                $errorMessage = is_string($context['error_message'] ?? null) ? $context['error_message'] : 'N/A';
                $exchange = is_string($context['exchange'] ?? null) ? $context['exchange'] : 'exchange';

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Server IP Permanently Banned',
                    'emailMessage' => "🚨 CRITICAL: Server IP Permanently Banned\n\n".
                        "The server IP has been permanently banned by {$exchangeTitle}.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Server IP: [COPY]{$ip}[/COPY]\n".
                        "• Hostname: {$hostname}\n".
                        "• Error Code: {$errorCode}\n".
                        "• Error Message: {$errorMessage}\n\n".
                        "⚠️ IMPACT:\n\n".
                        "• All API requests from this server to {$exchangeTitle} are BLOCKED\n".
                        "• This ban does NOT expire automatically\n".
                        "• Other workers (if available) will continue operating\n".
                        "• Manual intervention REQUIRED\n\n".
                        "🔧 RESOLUTION OPTIONS:\n\n".
                        "1. Contact {$exchangeTitle} Support:\n".
                        "   • Provide the banned IP address: {$ip}\n".
                        "   • Request IP unban or whitelist\n".
                        "   • Explain legitimate trading bot usage\n\n".
                        "2. Server IP Rotation:\n".
                        "   • Provision new server with different IP\n".
                        "   • Update infrastructure configuration\n".
                        "   • Migrate workloads to new server\n\n".
                        "3. Review Rate Limiting Patterns:\n".
                        "[CMD]SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour, COUNT(*) as requests, SUM(CASE WHEN http_response_code = 429 THEN 1 ELSE 0 END) as rate_limits FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND created_at > NOW() - INTERVAL 24 HOUR GROUP BY hour ORDER BY hour DESC;[/CMD]",
                    'pushoverMessage' => "🚨 CRITICAL: {$exchangeTitle} BANNED\nIP: {$ip}\nServer: {$hostname}\nManual intervention required!",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

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
                        "1. Check stale prices (symbols with auto_disabled=0 and stale mark_price_synced_at):\n".
                        "[CMD]SELECT CONCAT(es.token, '/', es.quote) AS pair, es.mark_price, es.mark_price_synced_at, TIMESTAMPDIFF(SECOND, es.mark_price_synced_at, NOW()) AS seconds_stale FROM exchange_symbols es WHERE es.api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchangeLower}') AND es.auto_disabled = 0 AND es.mark_price IS NOT NULL ORDER BY es.mark_price_synced_at ASC LIMIT 10;[/CMD]\n\n".
                        "2. Check supervisor status:\n".
                        "[CMD]supervisorctl status update-{$exchangeLower}-prices[/CMD]\n\n".
                        "3. Check logs for errors:\n".
                        "[CMD]supervisorctl tail -f update-{$exchangeLower}-prices[/CMD]\n\n".
                        "4. Restart if needed:\n".
                        "[CMD]supervisorctl restart update-{$exchangeLower}-prices[/CMD]",
                    'pushoverMessage' => "⚠️ {$exchangeTitle} stale prices detected\n".
                        "Example: {$oldestSymbol} ({$oldestMinutes}m ago)\n".
                        'Check supervisor: update-'.$exchangeLower.'-prices',
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'stale_priority_steps_detected' => (function () use ($context, $hostname) {
                // Extract stale step details - CRITICAL: steps stuck even after promotion
                $count = is_int($context['count'] ?? null) ? $context['count'] : 0;
                $oldestStepId = is_int($context['oldest_step_id'] ?? null) ? $context['oldest_step_id'] : 0;
                $oldestCanonical = is_string($context['oldest_canonical'] ?? null) ? $context['oldest_canonical'] : 'N/A';
                $oldestGroup = is_string($context['oldest_group'] ?? null) ? $context['oldest_group'] : 'N/A';
                $oldestIndex = is_int($context['oldest_index'] ?? null) ? $context['oldest_index'] : 0;
                $oldestMinutesStuck = is_int($context['oldest_minutes_stuck'] ?? null) ? $context['oldest_minutes_stuck'] : 0;
                $oldestDispatchedAt = is_string($context['oldest_dispatched_at'] ?? null) ? $context['oldest_dispatched_at'] : 'N/A';

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Priority Steps Still Stuck - Manual Action Required',
                    'emailMessage' => "🚨 CRITICAL: {$count} step(s) still stuck after promotion to priority queue!\n\n".
                        "Self-healing FAILED. These steps were promoted to high priority but are still not being processed.\n\n".
                        "Oldest stuck step:\n\n".
                        "• Step ID: {$oldestStepId}\n".
                        "• Class: {$oldestCanonical}\n".
                        "• Group: {$oldestGroup}\n".
                        "• Index: {$oldestIndex}\n".
                        "• Minutes Stuck: {$oldestMinutesStuck}\n".
                        "• Dispatched At: {$oldestDispatchedAt}\n".
                        "• Server: {$hostname}\n\n".
                        "MANUAL INTERVENTION REQUIRED:\n\n".
                        "• Check priority queue workers: supervisorctl status\n".
                        "• Check Redis connection\n".
                        "• Check circuit breaker (can_dispatch_steps)\n".
                        '• Consider resetting steps to Pending state',
                    'pushoverMessage' => "🚨 CRITICAL: {$count} step(s) STILL stuck after promotion!\n".
                        "Self-healing FAILED\n".
                        "Oldest: Step #{$oldestStepId}\n".
                        "Stuck: {$oldestMinutesStuck}m\n".
                        'MANUAL ACTION REQUIRED',
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'stale_dispatched_steps_detected' => (function () use ($context, $hostname) {
                // Extract stale step details
                $count = is_int($context['count'] ?? null) ? $context['count'] : 0;
                $oldestStepId = is_int($context['oldest_step_id'] ?? null) ? $context['oldest_step_id'] : 0;
                $oldestCanonical = is_string($context['oldest_canonical'] ?? null) ? $context['oldest_canonical'] : 'N/A';
                $oldestGroup = is_string($context['oldest_group'] ?? null) ? $context['oldest_group'] : 'N/A';
                $oldestIndex = is_int($context['oldest_index'] ?? null) ? $context['oldest_index'] : 0;
                $oldestMinutesStuck = is_int($context['oldest_minutes_stuck'] ?? null) ? $context['oldest_minutes_stuck'] : 0;
                $oldestDispatchedAt = is_string($context['oldest_dispatched_at'] ?? null) ? $context['oldest_dispatched_at'] : 'N/A';
                $promotedCount = is_int($context['promoted_count'] ?? null) ? $context['promoted_count'] : 0;

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Stale Dispatched Steps Detected',
                    'emailMessage' => "{$count} step(s) stuck in Dispatched state for over 5 minutes.\n\n".
                        "✅ SELF-HEALING APPLIED:\n\n".
                        "{$promotedCount} step(s) promoted to high priority queue.\n\n".
                        "Oldest stuck step:\n\n".
                        "• Step ID: {$oldestStepId}\n".
                        "• Class: {$oldestCanonical}\n".
                        "• Group: {$oldestGroup}\n".
                        "• Index: {$oldestIndex}\n".
                        "• Minutes Stuck: {$oldestMinutesStuck}\n".
                        "• Dispatched At: {$oldestDispatchedAt}\n".
                        "• Server: {$hostname}\n\n".
                        "If issue persists, check:\n\n".
                        "• Redis connection\n".
                        "• Priority queue workers (supervisorctl status)\n".
                        '• Circuit breaker (can_dispatch_steps)',
                    'pushoverMessage' => "{$count} step(s) stuck in Dispatched\n".
                        "Self-healing: {$promotedCount} promoted to priority queue\n".
                        "Oldest: Step #{$oldestStepId}\n".
                        "Stuck: {$oldestMinutesStuck}m",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'exchange_symbol_no_taapi_data' => (function () use ($context) {
                // Support both new format ('exchangeSymbol') and legacy format ('exchange_symbol')
                $exchangeSymbol = $context['exchangeSymbol'] ?? ($context['exchange_symbol'] ?? null);

                // Build display string manually: "TOKEN/QUOTE@Exchange" for readability
                $displayString = 'Exchange Symbol';
                if ($exchangeSymbol) {
                    $symbolToken = $exchangeSymbol->token ?? 'UNKNOWN';
                    $quoteCanonical = $exchangeSymbol->quote ?? 'UNKNOWN';
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
                        "• Symbol marked as auto_disabled = true\n".
                        "• Symbol marked with auto_disabled_reason = 'no_indicator_data'\n".
                        "• Symbol marked as receives_indicator_data = false\n".
                        "• No more TAAPI requests will be made for this symbol\n".
                        "• No new positions will be opened for this symbol\n".
                        '• Already open positions will continue normally',
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
                'pushoverMessage' => "{$exchangeTitle} price stream restarted\nSymbols: ".($context['old_count'] ?? '?').' → '.($context['new_count'] ?? '?'),
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
                'pushoverMessage' => "{$exchangeTitle} WebSocket invalid JSON - ".($context['hits'] ?? 0).' hits/min',
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
                    "Exchange: {$exchangeTitle}\n".
                    "Token: {$pairText}\n".
                    "Deadline: {$deliveryDate} UTC\n".
                    "Total open positions: {$positionsCount}\n\n";

                if ($positionsCount > 0) {
                    $message .= "⚠️ OPEN POSITIONS - System will not take any action on them:\n\n{$positionsDetails}";
                }

                $pushoverMessage = "Exchange: {$exchangeTitle}\n".
                    "Token: {$pairText}\n".
                    "Deadline: {$deliveryDate}\n".
                    "Total open positions: {$positionsCount}";

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Token Delisting Detected',
                    'emailMessage' => $message,
                    'pushoverMessage' => $pushoverMessage,
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'slow_query_detected' => (function () use ($context) {
                $sqlFull = is_string($context['sql_full'] ?? null) ? $context['sql_full'] : 'N/A';
                $timeMs = is_int($context['time_ms'] ?? null) ? $context['time_ms'] : 0;
                $connection = is_string($context['connection'] ?? null) ? $context['connection'] : 'unknown';
                $thresholdMs = is_int($context['threshold_ms'] ?? null) ? $context['threshold_ms'] : 2500;

                // Truncate SQL for pushover (max ~256 chars recommended)
                $truncatedSql = mb_strlen($sqlFull) > 100 ? mb_substr($sqlFull, 0, 100).'...' : $sqlFull;

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Slow Database Query Detected',
                    'emailMessage' => "A database query exceeded the configured threshold.\n\n".
                        "Query duration: {$timeMs}ms\n".
                        "Threshold: {$thresholdMs}ms\n".
                        "Connection: {$connection}\n\n".
                        "Query:\n\n".
                        "[CMD]{$sqlFull}[/CMD]",
                    'pushoverMessage' => "Query duration: {$timeMs}ms\n".
                        "Threshold: {$thresholdMs}ms\n".
                        "Query: {$truncatedSql}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'server_ip_not_whitelisted' => (function () use ($context, $exchangeTitle, $ip, $accountName) {
                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Please Whitelist Our Server IP',
                    'emailMessage' => "Hi!\n\n".
                        "We noticed that your {$exchangeTitle} API key requires IP whitelisting. ".
                        "To ensure uninterrupted service, please add our server IP to your API key whitelist.\n\n".
                        "Exchange: {$exchangeTitle}\n".
                        "Account: {$accountName}\n".
                        "IP to whitelist: [COPY]{$ip}[/COPY]\n\n".
                        "🔧 HOW TO ADD:\n\n".
                        "1. Log into {$exchangeTitle}\n".
                        "2. Go to API Management\n".
                        "3. Edit the API key used for \"{$accountName}\"\n".
                        "4. Add this IP to the whitelist: {$ip}\n".
                        "5. Save\n\n".
                        'Once done, everything will work seamlessly. Thank you!',
                    'pushoverMessage' => "{$exchangeTitle}: Please whitelist IP {$ip}\nAccount: {$accountName}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'server_account_blocked' => (function () use ($context, $exchangeTitle, $ip, $hostname, $accountName) {
                // Extract details
                $errorCode = is_string($context['error_code'] ?? null) ? $context['error_code'] : 'N/A';
                $errorMessage = is_string($context['error_message'] ?? null) ? $context['error_message'] : 'N/A';
                $accountId = $context['account_id'] ?? null;

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Account API Access Blocked',
                    'emailMessage' => "🚨 Account API Access Blocked\n\n".
                        "Your {$exchangeTitle} account API access has been blocked.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Account: {$accountName}\n".
                        "• Server IP: {$ip}\n".
                        "• Hostname: {$hostname}\n".
                        "• Error Code: {$errorCode}\n".
                        "• Error Message: {$errorMessage}\n\n".
                        "⚠️ POSSIBLE CAUSES:\n\n".
                        "• API key has been revoked or disabled\n".
                        "• API key permissions are insufficient\n".
                        "• Account has been restricted by the exchange\n".
                        "• Payment or subscription issues\n\n".
                        "🔧 HOW TO FIX:\n\n".
                        "1. Log into your {$exchangeTitle} account\n".
                        "2. Go to API Management settings\n".
                        "3. Check your API key status\n".
                        "4. If needed, generate a new API key with correct permissions:\n".
                        "   • Enable Futures trading (if using futures)\n".
                        "   • Enable Spot trading (if using spot)\n".
                        "   • Enable Read permissions\n".
                        "5. Update your API credentials in the platform\n\n".
                        "⏱️ WHAT HAPPENS NEXT:\n\n".
                        "• Once you fix the API key, update your credentials in Settings\n".
                        '• The system will automatically resume operations',
                    'pushoverMessage' => "🚨 {$exchangeTitle} API BLOCKED\nAccount: {$accountName}\nCheck API key permissions",
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
