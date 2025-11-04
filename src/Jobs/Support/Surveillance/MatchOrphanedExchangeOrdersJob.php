<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Support\Surveillance;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Support\Throttler;
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
            Throttler::using(NotificationService::class)
                ->withCanonical('orphaned_orders_detected')
                ->execute(function () use ($formattedOrphans) {
                    NotificationService::send(
                        user: Martingalian::admin(),
                        message: 'Orphaned Orders detected: '.$formattedOrphans->implode(', '),
                        title: 'Orphaned Orders Detected',
                        canonical: 'orphaned_orders_detected',
                        deliveryGroup: 'exceptions'
                    );
                });
        }
    }

    public function resolveException(Throwable $e)
    {
        $account = $this->account;
        Throttler::using(NotificationService::class)
            ->withCanonical('orphaned_orders_match_error')
            ->execute(function () use ($account, $e) {
                NotificationService::send(
                    user: Martingalian::admin(),
                    message: "[{$account->id}] Account {$account->user->name}/{$account->tradingQuote->canonical} surveillance error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    canonical: 'orphaned_orders_match_error',
                    deliveryGroup: 'exceptions'
                );
            });
    }
}
