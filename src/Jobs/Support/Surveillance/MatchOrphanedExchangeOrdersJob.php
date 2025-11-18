<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support\Surveillance;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Throwable;

final class MatchOrphanedExchangeOrdersJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function compute()
    {
        $ordersOnExchange = ApiSnapshot::getFrom($this->account, 'account-open-orders');

        $positionsOnDB = $this->account
            ->positions()
            ->opened()
            ->with('orders')
            ->get();

        $dbOrderIds = $positionsOnDB
            ->flatMap(fn ($p) => $p->orders->pluck('exchange_order_id'))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique();

        $exchangeOrderIds = collect($ordersOnExchange)
            ->filter(fn ($order) => isset($order['orderId']))
            ->map(fn ($order) => (string) $order['orderId'])
            ->unique();

        $orphanedOrders = collect($ordersOnExchange)
            ->filter(function ($order) use ($dbOrderIds) {
                return ! $dbOrderIds->contains((string) $order['orderId']);
            })
            ->values();

        if ($orphanedOrders->isNotEmpty()) {
            $formattedOrphans = $orphanedOrders->map(function ($order) {
                $symbol = $order['symbol'] ?? '???';
                $side = $order['side'] ?? '?';
                $type = $order['type'] ?? '?';
                $price = $order['price'] ?? '?';
                $id = $order['orderId'] ?? '???';

                return "{$symbol}:{$side}:{$type}:{$price} [#{$id}]";
            });

            // ✅ Alert: Orphaned orders found
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'orphaned_orders_detected',
                referenceData: [
                    'account_id' => $this->account->id,
                    'orphaned_orders_count' => $formattedOrphans->count(),
                    'orphaned_orders' => $formattedOrphans->toArray(),
                    'job_class' => class_basename(self::class),
                ],
                cacheKey: "orphaned_orders_detected:{$this->account->id}"
            );
        }
    }

    public function resolveException(Throwable $e)
    {
        NotificationService::send(
            user: Martingalian::admin(),
            canonical: 'orphaned_orders_match_error',
            referenceData: [
                'account_id' => $this->account->id,
                'user_name' => $this->account->user->name,
                'quote_canonical' => $this->account->tradingQuote->canonical,
                'job_class' => class_basename(self::class),
                'error_message' => ExceptionParser::with($e)->friendlyMessage(),
            ],
            cacheKey: "orphaned_orders_match_error:{$this->account->id}"
        );
    }
}
