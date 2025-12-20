<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;

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
     * Get the maximum symbols per WebSocket group for an exchange.
     * Returns null if the exchange doesn't require group splitting.
     */
    private function getMaxSymbolsPerGroup(int $apiSystemId): ?int
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
    }
}
