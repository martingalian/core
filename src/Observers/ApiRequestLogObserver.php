<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use DB;
use Exception;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;

final class ApiRequestLogObserver
{
    /**
     * Handle the ApiRequestLog "saved" event.
     * Sends notifications for API errors based on HTTP response codes.
     */
    public function saved(ApiRequestLog $log): void
    {
        // Send notification if needed
        $this->sendNotificationIfNeeded($log);

        // Auto-deactivate exchange symbols with no TAAPI data
        $this->deactivateExchangeSymbolIfNoTaapiData($log);
    }

    /**
     * Send notification for API errors.
     * No business logic - just notification routing based on error type.
     */
    private function sendNotificationIfNeeded(ApiRequestLog $log): void
    {
        // Skip if no HTTP response code yet (request still in progress)
        if ($log->http_response_code === null) {
            return;
        }

        // Skip if successful response (2xx or 3xx)
        if ($log->http_response_code < 400) {
            return;
        }

        // Load API system to determine which exception handler to use
        $apiSystem = ApiSystem::find($log->api_system_id);
        if (! $apiSystem) {
            return;
        }

        // Create the appropriate exception handler for error code analysis
        try {
            $handler = BaseExceptionHandler::make($apiSystem->canonical);
        } catch (Exception $e) {
            return;
        }

        $httpCode = $log->http_response_code;
        $vendorCode = $this->extractVendorCode($log);
        $hostname = $log->hostname ?? gethostname();

        // Build base reference data
        $baseData = [
            'exchange' => $apiSystem->canonical,
            'ip' => Martingalian::ip(),
            'hostname' => $hostname,
            'http_code' => $httpCode,
            'vendor_code' => $vendorCode,
            'path' => $log->path,
        ];

        // Server rate limit errors (429, 400 with vendor codes)
        if ($handler->isServerRateLimitedFromLog($httpCode, $vendorCode)) {
            // Load account info for context (sent to admin, shows which account hit the limit)
            $account = $log->account()->with('user')->first();
            $accountInfo = null;
            if ($account && $account->user) {
                $accountInfo = "{$account->user->name} ({$account->name})";
            }

            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'server_rate_limit_exceeded',
                referenceData: array_merge($baseData, array_filter([
                    'account_info' => $accountInfo,
                ])),
                relatable: $apiSystem,
                cacheKey: $log->account_id
                    ? $apiSystem->canonical.',account:'.$log->account_id
                    : $apiSystem->canonical
            );

            return;
        }

        // Server forbidden errors (418 and specific vendor codes - server/IP bans)
        if ($handler->isServerForbiddenFromLog($httpCode, $vendorCode)) {
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'server_forbidden',
                referenceData: $baseData,
                relatable: $apiSystem,
                cacheKey: $log->account_id
                    ? "account:{$log->account_id},server:{$hostname}"
                    : "server:{$hostname}"
            );

            return;
        }
    }

    /**
     * Extract vendor-specific error code from response body.
     */
    private function extractVendorCode(ApiRequestLog $log): ?int
    {
        $response = $log->response;

        if (! is_array($response)) {
            return null;
        }

        // Binance uses 'code', Bybit uses 'retCode'
        return $response['code'] ?? $response['retCode'] ?? null;
    }

    private function deactivateExchangeSymbolIfNoTaapiData(ApiRequestLog $log): void
    {
        // Only process TAAPI requests
        $taapiSystem = ApiSystem::where('canonical', 'taapi')->first();
        if (! $taapiSystem || $log->api_system_id !== $taapiSystem->id) {
            return;
        }

        // Only process permanent "no data" errors
        if (! $this->isPermanentNoDataError($log)) {
            return;
        }

        // Parse payload to identify the ExchangeSymbol
        $exchangeSymbol = $this->resolveExchangeSymbolFromPayload($log);
        if (! $exchangeSymbol) {
            return;
        }

        // Use transaction with pessimistic locking to prevent race conditions
        // Multiple concurrent API requests might all see is_active=true before any can update it
        $shouldNotify = DB::transaction(function () use ($exchangeSymbol, $log) {
            // Lock the row for update - prevents concurrent deactivations
            $lockedSymbol = ExchangeSymbol::where('id', $exchangeSymbol->id)
                ->lockForUpdate()
                ->first();

            // Skip if already deactivated (checked inside transaction after lock acquired)
            if (! $lockedSymbol || ! $lockedSymbol->is_active) {
                return false;
            }

            // Check if this is a consistent pattern (last N requests all failed)
            if (! $this->hasConsistentFailurePattern($lockedSymbol, $log)) {
                return false;
            }

            // Deactivate the ExchangeSymbol
            $lockedSymbol->update([
                'is_active' => false,
                'is_eligible' => false,
            ]);

            return true;
        });

        // Send notification outside transaction (only if we deactivated the symbol)
        if ($shouldNotify) {
            $this->sendDeactivationNotification($exchangeSymbol->fresh(), $log);
        }
    }

    private function isPermanentNoDataError(ApiRequestLog $log): bool
    {
        // Must be 400 error
        if ($log->http_response_code !== 400) {
            return false;
        }

        $response = is_string($log->response) ? $log->response : json_encode($log->response);

        // Check for known "no data" patterns
        $noDataPatterns = [
            'No candles were found!',
            'An unknown error occurred. Please check your parameters',
            'No data available',
            'Symbol not found',
        ];

        foreach ($noDataPatterns as $pattern) {
            if (str_contains($response, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function resolveExchangeSymbolFromPayload(ApiRequestLog $log): ?ExchangeSymbol
    {
        $payload = is_string($log->payload) ? json_decode($log->payload, true) : $log->payload;

        if (! isset($payload['options']['exchange'], $payload['options']['symbol'])) {
            return null;
        }

        $exchange = $payload['options']['exchange']; // "bybit" or "binancefutures"
        $symbolWithQuote = $payload['options']['symbol']; // "1000BONK/USDT" or "ETC/USDC"

        // Split symbol and quote
        $parts = explode('/', $symbolWithQuote);
        if (count($parts) !== 2) {
            return null;
        }

        [$baseToken, $quoteCanonical] = $parts;

        // Map TAAPI exchange name to ApiSystem
        $exchangeCanonical = match ($exchange) {
            'binancefutures' => 'binance',
            'bybit' => 'bybit',
            default => null,
        };

        if (! $exchangeCanonical) {
            return null;
        }

        $apiSystem = ApiSystem::where('canonical', $exchangeCanonical)->first();
        if (! $apiSystem) {
            return null;
        }

        // Check BaseAssetMapper for reverse mapping (e.g., 1000BONK → BONK)
        $symbolToken = $baseToken;
        $mapper = BaseAssetMapper::where('api_system_id', $apiSystem->id)
            ->where('exchange_token', $baseToken)
            ->first();

        if ($mapper) {
            $symbolToken = $mapper->symbol_token;
        }

        // Find the ExchangeSymbol
        return ExchangeSymbol::where('api_system_id', $apiSystem->id)
            ->whereHas('symbol', function ($q) use ($symbolToken) {
                $q->where('token', $symbolToken);
            })
            ->whereHas('quote', function ($q) use ($quoteCanonical) {
                $q->where('canonical', $quoteCanonical);
            })
            ->first();
    }

    private function hasConsistentFailurePattern(ExchangeSymbol $exchangeSymbol, ApiRequestLog $currentLog): bool
    {
        // Build the search pattern for this specific exchange/symbol/quote combination
        $payload = is_string($currentLog->payload) ? json_decode($currentLog->payload, true) : $currentLog->payload;
        $exchange = $payload['options']['exchange'] ?? null;
        $symbol = $payload['options']['symbol'] ?? null;

        if (! $exchange || ! $symbol) {
            return false;
        }

        $taapiSystem = ApiSystem::where('canonical', 'taapi')->first();

        // Escape the symbol for LIKE query (JSON stores it as "ETC\/USDC")
        // MySQL LIKE needs quadruple backslash in PHP to match JSON's escaped slash
        $escapedSymbol = str_replace('/', '\\\/', $symbol);

        // Get last 5 requests for this same exchange/symbol/quote combination
        $recentLogs = ApiRequestLog::where('api_system_id', $taapiSystem->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('payload', 'LIKE', "%{$exchange}%")
            ->where('payload', 'LIKE', "%{$escapedSymbol}%")
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Need at least 3 failed requests to confirm pattern
        if ($recentLogs->count() < 3) {
            return false;
        }

        // Check if ALL recent requests failed with "no data" error
        return $recentLogs->every(function ($log) {
            return $this->isPermanentNoDataError($log);
        });
    }

    private function sendDeactivationNotification(ExchangeSymbol $exchangeSymbol, ApiRequestLog $log): void
    {
        // Count how many failures occurred
        $payload = is_string($log->payload) ? json_decode($log->payload, true) : $log->payload;
        $exchange = $payload['options']['exchange'] ?? null;
        $symbol = $payload['options']['symbol'] ?? null;
        $escapedSymbol = str_replace('/', '\\\/', $symbol);

        $taapiSystem = ApiSystem::where('canonical', 'taapi')->first();
        $failureCount = ApiRequestLog::where('api_system_id', $taapiSystem->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('payload', 'LIKE', "%{$exchange}%")
            ->where('payload', 'LIKE', "%{$escapedSymbol}%")
            ->where('http_response_code', 400)
            ->count();

        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'exchange_symbol_no_taapi_data',
            referenceData: [
                'exchange_symbol' => $exchangeSymbol,
                'failure_count' => $failureCount,
            ],
            relatable: $exchangeSymbol,
            cacheKey: "exchange_symbol:{$exchangeSymbol->id},exchange:{$exchangeSymbol->apiSystem->canonical}"
        );
    }
}
