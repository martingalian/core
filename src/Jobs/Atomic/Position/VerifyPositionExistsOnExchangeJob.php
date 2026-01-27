<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Atomic\Position;

use Illuminate\Support\Str;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Jobs\Lifecycles\Position\CancelPositionJob;
use Martingalian\Core\Jobs\Lifecycles\Position\ReplacePositionOrdersJob;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Support\Math;
use Martingalian\Core\Support\Proxies\JobProxy;

/**
 * VerifyPositionExistsOnExchangeJob (Atomic)
 *
 * Reads the account-positions snapshot (fetched by QueryAccountPositionsJob)
 * and checks if the position still exists on the exchange.
 *
 * Decision:
 * - Position GONE → dispatches CancelPositionJob (position was closed externally)
 * - Position EXISTS → dispatches ReplacePositionOrdersJob (orders need recreation)
 */
final class VerifyPositionExistsOnExchangeJob extends BaseQueueableJob
{
    public Position $position;

    public string $triggerStatus;

    public ?string $message;

    public function __construct(int $positionId, string $triggerStatus, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->triggerStatus = $triggerStatus;
        $this->message = $message;
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        $position = $this->position;
        $tradingPair = mb_strtoupper(mb_trim($position->parsed_trading_pair));
        $positionExistsOnExchange = false;

        // Read the account-positions snapshot
        $openPositions = ApiSnapshot::getFrom($position->account, 'account-positions');

        if (is_array($openPositions)) {
            foreach ($openPositions as $key => $positionData) {
                $symbol = $positionData['symbol'] ?? $key;
                $symbol = mb_strtoupper(mb_trim($symbol));

                if ($symbol !== $tradingPair) {
                    continue;
                }

                // Get position amount (different exchanges use different field names)
                $positionAmt = (string) ($positionData['positionAmt']
                    ?? $positionData['size']
                    ?? $positionData['qty']
                    ?? $positionData['available']
                    ?? '0');

                $absAmount = Math::lt($positionAmt, '0')
                    ? Math::multiply($positionAmt, '-1')
                    : $positionAmt;

                if (Math::gt($absAmount, '0')) {
                    $positionExistsOnExchange = true;
                    break;
                }
            }
        }

        $resolver = JobProxy::with($position->account);

        if (! $positionExistsOnExchange) {
            // Position closed externally — cancel locally
            Step::create([
                'class' => $resolver->resolve(CancelPositionJob::class),
                'arguments' => [
                    'positionId' => $position->id,
                    'message' => $this->message ?? "Position closed externally ({$this->triggerStatus})",
                ],
                'child_block_uuid' => (string) Str::uuid(),
            ]);
        } else {
            // Position still exists — replace missing orders
            Step::create([
                'class' => $resolver->resolve(ReplacePositionOrdersJob::class),
                'arguments' => [
                    'positionId' => $position->id,
                    'message' => $this->message ?? "Orders {$this->triggerStatus} — replacing",
                ],
                'child_block_uuid' => (string) Str::uuid(),
            ]);
        }

        return [
            'position_id' => $position->id,
            'symbol' => $tradingPair,
            'position_exists_on_exchange' => $positionExistsOnExchange,
            'dispatched' => $positionExistsOnExchange ? 'ReplacePositionOrdersJob' : 'CancelPositionJob',
        ];
    }
}
