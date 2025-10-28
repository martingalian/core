<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support\Surveillance;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Support\NotificationThrottler;
use Throwable;

/**
 * AssessExchangeUnknownOrdersJob checks exchange open orders that do not map.
 * It groups orders by symbol, eliminates sides with active positions, and
 * notifies admins about potential unknown orders after a grace window.
 *
 * 2025-08-19 12:05 CEST:
 *   Only notify if a position for the symbol was closed
 *   more than 15 minutes ago. Replaced 5-minute check and added status check.
 *
 * 2025-10-01 10:30 CEST:
 *   Tighten to a "latest position closed ≥ 5 minutes ago" rule.
 *   Previously we asked: "Is there any closed position older than N minutes?"
 *   That caused false positives when a fresh close still had open orders
 *   awaiting cancellation by the Graph workflow.
 *   Now we look at the most recent position for the symbol:
 *     - If there is no position history: notify immediately.
 *     - If the most recent position is CLOSED and its updated_at is older
 *       than the grace window (default 5m): eligible to notify.
 *     - Otherwise: skip (still within grace period or still open/closing).
 */
final class AssessExchangeUnknownOrdersJob extends BaseQueueableJob
{
    /**
     * Target account to be analyzed.
     */
    public Account $account;

    /**
     * Construct the job with the account identifier.
     */
    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    /**
     * Provide relatable model for logging and context.
     */
    public function relatable()
    {
        return $this->account;
    }

    /**
     * Compute the surveillance analysis for unknown exchange orders.
     */
    public function compute()
    {
        // --- Configuration: grace window (minutes) before we consider unknowns actionable.
        // You can override via config('martingalian.surveillance.grace_minutes').
        $graceMinutes = (int) config('martingalian.surveillance.grace_minutes', 5);

        // --- Snapshot: exchange "open orders" and "positions"
        $ordersOnExchange = collect(ApiSnapshot::getFrom($this->account, 'account-open-orders'));
        $positionsSnapshot = ApiSnapshot::getFrom($this->account, 'account-positions');

        // --- Gather DB-mapped active exchange_order_ids from ongoing positions
        $positionsOnDB = $this->account->positions()->ongoing()->get();

        $dbOrderIds = $positionsOnDB
            ->flatMap(function ($position) {
                // Only active-on-exchange orders should be considered mapped.
                return $position->orders()->activeOnExchange()->pluck('exchange_order_id');
            })
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        // --- Group exchange open orders by symbol for targeted checks
        $exchangeOrderGroups = $ordersOnExchange->groupBy('symbol');

        foreach ($exchangeOrderGroups as $symbol => $orders) {
            // --- From exchange snapshot: which directions have an actual non-zero position?
            $positionSnapshots = $positionsSnapshot[$symbol] ?? [];

            $validDirections = collect(is_array($positionSnapshots) ? $positionSnapshots : [$positionSnapshots])
                ->filter(fn ($p) => isset($p['positionAmt']) && (float) $p['positionAmt'] !== 0.0)
                ->map(fn ($p) => ($p['positionAmt'] > 0 ? 'LONG' : 'SHORT'))
                ->values();

            // --- DB safety: if we still have any ongoing position for this symbol, skip
            $hasOngoingDbPosition = $this->account->positions()
                ->ongoing()
                ->where('parsed_trading_pair', $symbol)
                ->exists();

            if ($hasOngoingDbPosition) {
                // We rely on the Graph to cancel or resolve orders; do not alert while ongoing.
                continue;
            }

            // --- Identify orders that are unknown to our DB and not aligned with an active side
            $unknownOrders = collect($orders)
                ->filter(function ($order) use ($dbOrderIds, $validDirections) {
                    // If the order id is already mapped, it's not unknown.
                    $isUnknown = ! $dbOrderIds->contains((string) ($order['orderId'] ?? ''));

                    // Map exchange "side" to our "direction"
                    $side = $order['side'] ?? null;
                    $direction = $side === 'BUY' ? 'LONG' : ($side === 'SELL' ? 'SHORT' : null);

                    // Some exchanges may include states for open orders; if present, keep only live ones.
                    $status = $order['status'] ?? 'NEW';
                    $isLive = in_array($status, ['NEW', 'PARTIALLY_FILLED', 'PENDING_CANCEL'], true);

                    return $isUnknown
                        && $direction
                        && $isLive
                        // If exchange reports an active position in that direction, do not flag orders for that side.
                        && ! $validDirections->contains($direction);
                })
                ->map(function ($order) {
                    // Keep a succinct human string for the notification.
                    return sprintf(
                        '#%s (%s @ %s qty %s, status: %s)',
                        $order['orderId'] ?? '?',
                        $order['type'] ?? '?',
                        $order['price'] ?? '?',
                        $order['origQty'] ?? '?',
                        $order['status'] ?? '?'
                    );
                })
                ->values();

            if ($unknownOrders->isEmpty()) {
                continue;
            }

            // --- Grace logic: inspect the most recent position row for this symbol on DB
            $latestPosition = $this->account->positions()
                ->where('parsed_trading_pair', $symbol)
                ->latest('updated_at')
                ->first();

            $eligibleToNotify = false;

            if (is_null($latestPosition)) {
                // Never traded this symbol -> any open "unknown" orders are suspicious immediately.
                $eligibleToNotify = true;
            } elseif ($latestPosition->status === 'closed') {
                // Only notify if the latest (most recent) position has been CLOSED long enough.
                $eligibleToNotify = $latestPosition->updated_at->lte(now()->subMinutes($graceMinutes));
            } else {
                // Latest position is not yet closed (opening/opened/closing): skip for now.
                $eligibleToNotify = false;
            }

            if (! $eligibleToNotify) {
                // Within grace window or still active pipeline — likely the Graph is cancelling.
                continue;
            }

            // --- All guards passed: notify admins with context
            NotificationThrottler::sendToAdmin(
                messageCanonical: 'assess_unknown_orders',
                message: 'Unknown exchange orders for ['.$symbol.']: '.$unknownOrders->implode(',
            title: ').'.',
                deliveryGroup: 'exceptions'
            );
        }
    }

    /**
     * Handle exceptions by notifying admins with a friendly message.
     */
    public function resolveException(Throwable $e)
    {
        NotificationThrottler::sendToAdmin(
            messageCanonical: 'assess_unknown_orders_2',
            message: '['.$this->account->id.'] Account '
            .$this->account->user->name.'/'
            .$this->account->tradingQuote->canonical
            .' surveillance error - '
            .ExceptionParser::with($e)->friendlyMessage(),
            title: '['.class_basename(self::class).'] - Error.',
            deliveryGroup: 'exceptions'
        );
    }
}
