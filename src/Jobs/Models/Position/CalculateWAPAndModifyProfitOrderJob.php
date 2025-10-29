<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use App\Support\NotificationService;
use App\Support\Throttler;
use Exception;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Position;
use Throwable;

final class CalculateWAPAndModifyProfitOrderJob extends BaseApiableJob
{
    /** @var Position The local Position model we will work with. */
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        // Use a single scale for BCMath ops (tick rounding is handled later).
        $scale = 8;

        // 1) Read the latest account-positions snapshot.
        $positions = ApiSnapshot::getFrom($this->position->account, 'account-positions');

        // 2) Resolve the snapshot entry by exact parsed trading pair.
        $symbolKey = $this->position->parsed_trading_pair;

        if (! is_array($positions) || ! array_key_exists($symbolKey, $positions)) {
            throw new Exception('The position used for WAPing no longer exists. Please check!');
        }

        $positionFromExchange = $positions[$symbolKey];

        // 3) Extract breakEvenPrice (string) and positionAmt (string).
        $breakEvenPrice = (string) ($positionFromExchange['breakEvenPrice'] ?? '0');
        $rawQty = (string) ($positionFromExchange['positionAmt'] ?? '0');

        // Sanity checks.
        if (bccomp($breakEvenPrice, '0', $scale) !== 1) {
            Throttler::using(NotificationService::class)
                ->withCanonical('calculate_wap_modify_profit')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$this->position->id}] {$this->position->parsed_trading_pair} — WAP not triggered: invalid breakEvenPrice={$breakEvenPrice}.",
                        title: 'WAP skipped',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        if (bccomp($rawQty, '0', $scale) === 0) {
            Throttler::using(NotificationService::class)
                ->withCanonical('calculate_wap_modify_profit_2')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$this->position->id}] {$this->position->parsed_trading_pair} — WAP not triggered: zero quantity from exchange.",
                        title: 'WAP skipped',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        // Absolute quantity (SHORT arrives negative on Binance).
        $absQty = bccomp($rawQty, '0', $scale) < 0 ? bcmul($rawQty, '-1', $scale) : $rawQty;

        // 5) Compute the target price:
        //    If ALL ladder steps are already filled (no next pending limit),
        //    we place the profit order at BEP to exit flat (no gain/loss).
        //    Otherwise use BEP adjusted by the configured profit percentage.
        $profitPct = (string) ($this->position->profit_percentage ?? '0'); // e.g. "0.350"
        $fraction = bcdiv($profitPct, '100', $scale);                     // -> "0.0035"

        $isLong = mb_strtoupper((string) $this->position->direction) === 'LONG';
        $one = '1';
        $multiplier = $isLong ? bcadd($one, $fraction, $scale) : bcsub($one, $fraction, $scale);

        // Robust “final step filled” check: there is no next pending limit price.
        // $allLimitOrdersFilled = ($this->position->nextPendingLimitOrderPrice() === null);

        // Target price selection.
        /*
        $target = $allLimitOrdersFilled
            ? $breakEvenPrice                     // Exit at BEP (flat)
            : bcmul($breakEvenPrice, $multiplier, $scale); // Normal profit target
        */

        $target = bcmul($breakEvenPrice, $multiplier, $scale); // Normal profit target

        // 6) Exchange-format price & quantity to tick/step.
        $formattedPrice = api_format_price($target, $this->position->exchangeSymbol);
        $absQty = api_format_quantity($absQty, $this->position->exchangeSymbol);

        // 7) Fetch profit order and modify.
        $profitOrder = $this->position->profitOrder();
        if (! $profitOrder) {
            Throttler::using(NotificationService::class)
                ->withCanonical('calculate_wap_modify_profit_3')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$this->position->id}] {$this->position->parsed_trading_pair} — WAP computed but profit order missing.",
                        title: 'WAP warning',
                        deliveryGroup: 'exceptions'
                    );
                });

            return;
        }

        $oldQty = (string) ($profitOrder->quantity ?? '0');
        $oldPrice = (string) ($profitOrder->price ?? '0');

        // Apply on-exchange
        $profitOrder->apiModify($absQty, $formattedPrice);
        $profitOrder->apiSync(); // Refresh local state (qty/price may be normalized by the exchange)

        // Persist reference values to avoid re-wrapping by watchers.
        $profitOrder->updateSaving([
            'reference_quantity' => $profitOrder->quantity,
            'reference_price' => $profitOrder->price,
        ]);

        // ⚠️ Avoid double-formatting / numeric-cast surprises.
        // Prefer the just-synced order quantity, which reflects exchange normalization.
        $this->position->updateSaving([
            'quantity' => $profitOrder->quantity,
            'was_waped' => true,
            'waped_at' => now(),
        ]);

        $formattedBEP = api_format_price($breakEvenPrice, $this->position->exchangeSymbol);

        // Notify once the ladder threshold is met.
        if ($this->position->totalLimitOrdersFilled() >= $this->position->account->total_limit_orders_filled_to_notify) {
            Throttler::using(NotificationService::class)
                ->withCanonical('calculate_wap_modify_profit_4')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "{$this->position->parsed_trading_pair_extended} — WAP Profit order updated"
                ."\nPrice: {$oldPrice} → {$profitOrder->price}"
                ."\nQty:   {$oldQty} → {$profitOrder->quantity}"
                ."\nBEP:   {$formattedBEP}",
                        title: "[P:{$this->position->id} O:{$profitOrder->id}] - Profit WAP updated",
                        deliveryGroup: 'exceptions'
                    );
                });
        }
    }

    public function resolveException(Throwable $e)
    {
        Throttler::using(NotificationService::class)
            ->withCanonical('calculate_wap_modify_profit_5')
            ->execute(function () {
                NotificationService::sendToAdmin(
                    message: "[{$this->position->id}] Position {$this->position->parsed_trading_pair} lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
                    title: '['.class_basename(self::class).'] - Error',
                    deliveryGroup: 'exceptions'
                );
            });

        $this->position->updateSaving([
            'error_message' => ExceptionParser::with($e)->friendlyMessage(),
        ]);
    }
}
