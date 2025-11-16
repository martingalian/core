<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use DB;
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
     * Triggers notifications for API errors based on HTTP response codes.
     */
    public function saved(ApiRequestLog $log): void
    {
        // Delegate to the model's notification logic
        $log->sendNotificationIfNeeded();

        // Auto-deactivate exchange symbols with no TAAPI data
        $this->deactivateExchangeSymbolIfNoTaapiData($log);
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
                'is_tradeable' => false,
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
        $symbolToken = $exchangeSymbol->symbol->token;
        $quoteCanonical = $exchangeSymbol->quote->canonical;
        $exchangeName = $exchangeSymbol->apiSystem->name;

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
                'symbol_token' => $symbolToken,
                'quote_canonical' => $quoteCanonical,
                'exchange_name' => $exchangeName,
                'failure_count' => $failureCount,
            ],
            relatable: $exchangeSymbol
        );
    }
}
