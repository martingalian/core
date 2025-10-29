<?php

declare(strict_types=1);

namespace Martingalian\Core\Observers;

use App\Support\NotificationService;
use App\Support\Throttler;
use Illuminate\Support\Carbon;
use Martingalian\Core\Models\ExchangeSymbol;

final class ExchangeSymbolObserver
{
    public function creating(ExchangeSymbol $model): void
    {
        $model->cacheChangesForCreate();
    }

    public function updating(ExchangeSymbol $model): void
    {
        $model->cacheChangesForUpdate();
    }

    public function created(ExchangeSymbol $model): void {}

    public function updated(ExchangeSymbol $model): void
    {

        // Notify admins when delivery date changes (delisting schedule update)
        // Only notify when both old and new values are non-null (actual change in schedule)
        if ($model->isDirty('delivery_ts_ms')) {
            $oldValue = $model->getOriginal('delivery_ts_ms');
            $newValue = $model->delivery_ts_ms;

            // Only notify when both values are non-null and different
            if ($oldValue !== null && $newValue !== null && $oldValue !== $newValue) {
                // Prepare human-friendly UTC date
                $when = Carbon::createFromTimestampMs((int) $newValue)->utc()->format('j M Y H:i');

                // Use parsed_trading_pair accessor for display (e.g., BTC/USDT)
                $pairLabel = $model->parsed_trading_pair ?? 'N/A';

                // Get exchange name
                $exchangeName = $model->apiSystem->name ?? 'Unknown Exchange';

                // Notify admins about the delisting schedule update
                $msg = sprintf(
                    'Delisting schedule updated: %s on %s set to %s UTC. Trading disabled for this symbol.',
                    $pairLabel,
                    $exchangeName,
                    $when
                );
                $title = '[ExchangeSymbolObserver] Futures delisting detected';
                Throttler::using(NotificationService::class)
                    ->withCanonical('futures_delisting_detected')
                    ->execute(function () {
                        NotificationService::sendToAdmin(
                            message: $msg,
                            title: $title,
                            deliveryGroup: 'exceptions'
                        );
                    });
            }
        }
    }

    public function deleted(ExchangeSymbol $model): void {}

    public function forceDeleted(ExchangeSymbol $model): void {}
}
