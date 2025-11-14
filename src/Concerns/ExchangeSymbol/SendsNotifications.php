<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\ExchangeSymbol;

use Illuminate\Support\Carbon;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Support\NotificationService;

/**
 * SendsNotifications
 *
 * Handles delisting notification logic for ExchangeSymbol.
 * This is the single source of truth for symbol delisting notifications.
 */
trait SendsNotifications
{
    /**
     * Send delisting notification if delivery date changed in a way that indicates delisting.
     * Called by ExchangeSymbolObserver::saved() after the symbol is saved.
     *
     * Exchange-specific logic:
     * - Binance: Delivery date changed (value → different value) = contract rollover/delisting
     * - Bybit: Delivery date set (null → value) = perpetual being delisted
     */
    public function sendDelistingNotificationIfNeeded(): void
    {
        // Check if delivery_ts_ms changed - this works for both creates and updates
        if (! $this->wasChanged('delivery_ts_ms')) {
            return;
        }

        $oldValue = $this->getOriginal('delivery_ts_ms');
        $newValue = $this->delivery_ts_ms;

        // Get exchange to determine notification logic
        $exchange = $this->apiSystem->canonical ?? null;
        if (! $exchange) {
            return;
        }

        $shouldNotify = false;

        // Binance perpetual default (Dec 25, 2100) - any other value indicates delisting
        $binancePerpetualDefault = 4133404800000;

        // Binance: Delivery date changed to non-perpetual value
        // - First time set (null → value): Just initial sync, DO NOT notify
        // - Changed (value → different value): Delisting reschedule, notify
        // - Ignore perpetual default value (4133404800000)
        if ($exchange === 'binance') {
            $isDelistedValue = $newValue !== null && $newValue !== $binancePerpetualDefault;

            if ($isDelistedValue) {
                // Notify ONLY if: changed to different value (not first time set)
                if ($oldValue !== null && $oldValue !== $newValue) {
                    $shouldNotify = true;
                }
            }
        }

        // Bybit: Delivery date set for first time (null → value)
        // This indicates a perpetual is being delisted
        // Also handle delivery date changes (rare but possible)
        if ($exchange === 'bybit') {
            if (($oldValue === null && $newValue !== null) ||
                ($oldValue !== null && $newValue !== null && $oldValue !== $newValue)) {
                $shouldNotify = true;
            }
        }

        if ($shouldNotify) {
            $this->sendDelistingNotification($newValue);
        }
    }

    /**
     * Send complete delisting notification with position details to admin.
     *
     * @param  int  $deliveryTimestampMs  The delivery timestamp in milliseconds
     */
    protected function sendDelistingNotification(int $deliveryTimestampMs): void
    {
        // Get symbol information
        $pairText = $this->parsed_trading_pair ?? 'N/A';
        $exchangeName = $this->apiSystem->name ?? 'Unknown Exchange';
        $deliveryDate = Carbon::createFromTimestampMs($deliveryTimestampMs)->utc()->format('j M Y H:i');

        // Find all open positions for this exchange symbol
        $positions = Position::query()
            ->opened()
            ->where('exchange_symbol_id', $this->id)
            ->whereHas('account', function ($q) {
                $q->where('api_system_id', $this->api_system_id);
            })
            ->get();

        // Build notification message
        $message = sprintf(
            "Token delisting detected: %s on %s\n\nDelivery Date: %s UTC\n\n",
            $pairText,
            $exchangeName,
            $deliveryDate
        );

        // Add position details if any exist
        if ($positions->isEmpty()) {
            $message .= 'No open positions for this symbol.';
        } else {
            $message .= "Open positions requiring manual review:\n\n";

            foreach ($positions as $position) {
                $account = $position->account;
                $user = $account->user;
                $userName = $user ? $user->name : 'No User Assigned';
                $accountName = $account->name ?? "Account #{$account->id}";
                $direction = mb_strtoupper((string) $position->direction);

                $message .= sprintf(
                    "• Position #%d (%s)\n  Account: %s\n  User: %s\n\n",
                    $position->id,
                    $direction,
                    $accountName,
                    $userName
                );
            }

            $message .= sprintf('Total positions requiring attention: %d', $positions->count());
        }

        $title = 'Token Delisting Detected';

        // Send immediate notification to admin (no throttling - critical event)
        NotificationService::send(
            user: Martingalian::admin(),
            message: $message,
            title: $title,
            canonical: 'symbol_delisting_positions_detected',
            deliveryGroup: 'exceptions'
        );
    }
}
