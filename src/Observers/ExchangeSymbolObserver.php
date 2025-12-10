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

        // Assign websocket_group for KuCoin (other exchanges use default 'group-1')
        $this->assignWebsocketGroup($model);
    }

    /**
     * Assign websocket_group for exchanges with subscription limits.
     * KuCoin has a 300 subscription limit per WebSocket session, so we cap at 250 per group.
     */
    public function assignWebsocketGroup(ExchangeSymbol $model): void
    {
        // Only assign for KuCoin - other exchanges use the default 'group-1'
        $kucoinSystem = ApiSystem::firstWhere('canonical', 'kucoin');

        if ($kucoinSystem === null || $model->api_system_id !== $kucoinSystem->id) {
            return;
        }

        // Find first group with available capacity
        $groupNumber = 1;
        while (true) {
            $groupName = "group-{$groupNumber}";
            $count = ExchangeSymbol::where('api_system_id', $kucoinSystem->id)
                ->where('websocket_group', $groupName)
                ->count();

            if ($count < self::KUCOIN_MAX_PER_GROUP) {
                $model->websocket_group = $groupName;

                return;
            }
            $groupNumber++;
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
    }
}
