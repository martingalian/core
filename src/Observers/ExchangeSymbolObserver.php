<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\TokenMapper;
use Martingalian\Core\Support\Proxies\TradingMapperProxy;

final class ExchangeSymbolObserver
{
    /**
     * Max symbols per websocket group for KuCoin.
     * KuCoin documentation says 300 limit but in practice 100 works reliably.
     */
    private const KUCOIN_MAX_PER_GROUP = 100;

    /**
     * Max symbols per websocket group for BitGet.
     * BitGet recommends <50 channels per connection for stability.
     * Using 45 for extra safety margin.
     */
    private const BITGET_MAX_PER_GROUP = 45;

    /**
     * Cached Binance system ID to avoid repeated queries.
     */
    private static ?int $binanceSystemId = null;

    public function creating(ExchangeSymbol $model): void
    {
        // Default api_statuses on creation
        $apiStatuses = $model->api_statuses ?? [];

        // Default cmc_api_called: true if symbol_id is set, false otherwise
        if (! isset($apiStatuses['cmc_api_called'])) {
            $apiStatuses['cmc_api_called'] = $model->symbol_id !== null;
        }

        // Default taapi_verified to false (not yet checked)
        if (! isset($apiStatuses['taapi_verified'])) {
            $apiStatuses['taapi_verified'] = false;
        }

        // Default has_taapi_data to false (unknown until verified)
        if (! isset($apiStatuses['has_taapi_data'])) {
            $apiStatuses['has_taapi_data'] = false;
        }

        $model->api_statuses = $apiStatuses;

        // Assign websocket_group for exchanges with subscription limits (KuCoin, BitGet)
        $this->assignWebsocketGroup($model);
    }

    /**
     * Handle overlap logic before saving (create or update).
     * Sets overlaps_with_binance and handles delisting cascades.
     */
    public function saving(ExchangeSymbol $model): void
    {
        $apiSystem = ApiSystem::find($model->api_system_id);

        if ($apiSystem === null) {
            return;
        }

        $isBinance = $apiSystem->canonical === 'binance';

        if ($isBinance) {
            // Rule 1: Binance symbols always overlap with themselves
            $model->overlaps_with_binance = true;

            // Rule 3: If Binance symbol is being delisted, mark other exchanges' overlaps as false
            if ($this->isBeingDelisted($model, $apiSystem->canonical)) {
                $this->markOtherExchangesAsNotOverlapping($model->token);
            }
        } else {
            // Rule 2: Non-Binance - check if token exists on Binance (direct or via TokenMapper)
            $model->overlaps_with_binance = $this->tokenExistsOnBinance($model->token, $model->api_system_id);
        }
    }

    public function updating(ExchangeSymbol $model): void
    {
        // If symbol_id is being set, mark CMC as verified
        if ($model->isDirty('symbol_id') && $model->symbol_id !== null) {
            $apiStatuses = $model->api_statuses ?? [];
            if (($apiStatuses['cmc_api_called'] ?? false) !== true) {
                $apiStatuses['cmc_api_called'] = true;
                $model->api_statuses = $apiStatuses;
            }
        }
    }

    public function saved(ExchangeSymbol $model): void
    {
        // Model-specific business logic
        $model->sendDelistingNotificationIfNeeded();

        // Propagate TAAPI data if this is a Binance symbol with api_statuses changed
        if ($this->isBinanceSymbol($model) && $model->wasChanged('api_statuses')) {
            $this->propagateTaapiDataToOverlappingSymbols($model);
        }
    }

    /**
     * Assign websocket_group for exchanges with subscription limits.
     * Some exchanges have per-connection subscription limits that require
     * splitting symbols across multiple WebSocket connections.
     */
    public function assignWebsocketGroup(ExchangeSymbol $model): void
    {
        // Get the max symbols per group for this exchange (null if no limit)
        $maxPerGroup = $this->getMaxSymbolsPerGroup($model->api_system_id);

        if ($maxPerGroup === null) {
            // Exchange doesn't need group splitting - uses default 'group-1'
            return;
        }

        // Find first group with available capacity
        $groupNumber = 1;
        while (true) {
            $groupName = "group-{$groupNumber}";
            $count = ExchangeSymbol::where('api_system_id', $model->api_system_id)
                ->where('websocket_group', $groupName)
                ->count();

            if ($count < $maxPerGroup) {
                $model->websocket_group = $groupName;

                return;
            }
            $groupNumber++;
        }
    }

    /**
     * Check if a token exists on Binance (direct match or via TokenMapper).
     */
    public function tokenExistsOnBinance(string $token, ?int $apiSystemId = null): bool
    {
        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return false;
        }

        // Direct token match
        $directMatch = ExchangeSymbol::where('api_system_id', $binanceSystemId)
            ->where('token', $token)
            ->exists();

        if ($directMatch) {
            return true;
        }

        // Check via TokenMapper for name variations (e.g., PEPE on KuCoin = 1000PEPE on Binance)
        if ($apiSystemId !== null) {
            $mapping = TokenMapper::where('other_token', $token)
                ->where('other_api_system_id', $apiSystemId)
                ->first();

            if ($mapping !== null) {
                return ExchangeSymbol::where('api_system_id', $binanceSystemId)
                    ->where('token', $mapping->binance_token)
                    ->exists();
            }
        }

        return false;
    }

    /**
     * Mark other exchanges' symbols as not overlapping when Binance delistings.
     * Uses withoutEvents to prevent circular observer calls.
     */
    public function markOtherExchangesAsNotOverlapping(string $token): void
    {
        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return;
        }

        ExchangeSymbol::withoutEvents(function () use ($token, $binanceSystemId): void {
            ExchangeSymbol::where('token', $token)
                ->where('api_system_id', '!=', $binanceSystemId)
                ->update(['overlaps_with_binance' => false]);
        });
    }

    /**
     * Check if a symbol is being delisted for the first time.
     * Only triggers on first detection to prevent repeated cascades.
     */
    public function isBeingDelisted(ExchangeSymbol $model, string $canonical): bool
    {
        // Already marked as delisted - no need to cascade again
        if ($model->is_marked_for_delisting) {
            return false;
        }

        $proxy = new TradingMapperProxy($canonical);

        if ($proxy->isNowDelisted($model)) {
            // Mark on the model so it gets saved with this state
            $model->is_marked_for_delisting = true;

            return true;
        }

        return false;
    }

    /**
     * Propagate has_taapi_data from a Binance symbol to overlapping non-Binance symbols.
     * Uses withoutEvents to prevent circular observer calls.
     */
    public function propagateTaapiDataToOverlappingSymbols(ExchangeSymbol $binanceSymbol): void
    {
        $hasTaapiData = $binanceSymbol->api_statuses['has_taapi_data'] ?? null;

        if ($hasTaapiData === null) {
            return;
        }

        $binanceSystemId = $this->getBinanceSystemId();

        if ($binanceSystemId === null) {
            return;
        }

        // Direct token match propagation
        ExchangeSymbol::withoutEvents(function () use ($binanceSymbol, $hasTaapiData, $binanceSystemId): void {
            ExchangeSymbol::where('token', $binanceSymbol->token)
                ->where('api_system_id', '!=', $binanceSystemId)
                ->where('overlaps_with_binance', true)
                ->each(function (ExchangeSymbol $symbol) use ($hasTaapiData): void {
                    $apiStatuses = $symbol->api_statuses ?? [];
                    $apiStatuses['has_taapi_data'] = $hasTaapiData;
                    $symbol->updateSaving(['api_statuses' => $apiStatuses]);
                });
        });

        // TokenMapper reverse lookup propagation (for different token names across exchanges)
        $this->propagateViaMappedTokens($binanceSymbol, $hasTaapiData, $binanceSystemId);
    }

    /**
     * Propagate TAAPI data via TokenMapper for cross-exchange token name differences.
     * E.g., NEIRO on Binance = 1000NEIRO on KuCoin.
     */
    public function propagateViaMappedTokens(ExchangeSymbol $binanceSymbol, bool $hasTaapiData, int $binanceSystemId): void
    {
        // Find mappings where this Binance token has different names on other exchanges
        $mappings = TokenMapper::where('binance_token', $binanceSymbol->token)->get();

        if ($mappings->isEmpty()) {
            return;
        }

        ExchangeSymbol::withoutEvents(function () use ($mappings, $hasTaapiData, $binanceSystemId): void {
            foreach ($mappings as $mapping) {
                ExchangeSymbol::where('api_system_id', $mapping->other_api_system_id)
                    ->where('token', $mapping->other_token)
                    ->where('api_system_id', '!=', $binanceSystemId)
                    ->where('overlaps_with_binance', true)
                    ->each(function (ExchangeSymbol $symbol) use ($hasTaapiData): void {
                        $apiStatuses = $symbol->api_statuses ?? [];
                        $apiStatuses['has_taapi_data'] = $hasTaapiData;
                        $symbol->updateSaving(['api_statuses' => $apiStatuses]);
                    });
            }
        });
    }

    /**
     * Check if a symbol belongs to Binance.
     */
    public function isBinanceSymbol(ExchangeSymbol $model): bool
    {
        $binanceSystemId = $this->getBinanceSystemId();

        return $binanceSystemId !== null && $model->api_system_id === $binanceSystemId;
    }

    /**
     * Get the Binance system ID, caching it for performance.
     */
    public function getBinanceSystemId(): ?int
    {
        if (self::$binanceSystemId === null) {
            self::$binanceSystemId = ApiSystem::where('canonical', 'binance')->value('id');
        }

        return self::$binanceSystemId;
    }

    /**
     * Get the maximum symbols per WebSocket group for an exchange.
     * Returns null if the exchange doesn't require group splitting.
     */
    public function getMaxSymbolsPerGroup(int $apiSystemId): ?int
    {
        // Mapping of exchange canonicals to their max symbols per group
        $exchangeLimits = [
            'kucoin' => self::KUCOIN_MAX_PER_GROUP,
            'bitget' => self::BITGET_MAX_PER_GROUP,
        ];

        // Find the canonical name for this api_system_id
        $apiSystem = ApiSystem::find($apiSystemId);

        if ($apiSystem === null) {
            return null;
        }

        return $exchangeLimits[$apiSystem->canonical] ?? null;
    }

    /**
     * Reset the cached Binance system ID.
     * Useful for testing.
     */
    public static function resetBinanceSystemIdCache(): void
    {
        self::$binanceSystemId = null;
    }
}
