<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Models\Candle;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\User;
use Throwable;

/**
 * CheckPriceSpikeAndCooldownJob
 *
 * Batch mode: processes all ExchangeSymbols and applies cooldown when an upside
 * price spike is detected against the latest 1D candle close.
 *
 * Rules:
 * - Threshold: per symbol, ExchangeSymbol::disable_on_price_spike_percentage (percent).
 * - Cooldown hours: per symbol, ExchangeSymbol::price_spike_cooldown_hours (int, fallback 72).
 * - Current price: prefer ExchangeSymbol::mark_price; fallback to the 1D close.
 * - Spike condition: ((current - close1d) / close1d) * 100 >= threshold (upside only).
 * - tradeable_at: set to max(existing tradeable_at, now + cooldown_hours). Never set to null.
 * - When spike applies: always update (sliding window) and log via logApplicationEvent().
 * - No user notifications on success. On exceptions, notify admins.
 *
 * Implementation notes:
 * - Uses chunkById to avoid loading the entire table into memory.
 * - ASCII-only punctuation in messages.
 */
final class CheckPriceSpikeAndCooldownJob extends BaseQueueableJob
{
    public function relatable()
    {
        return null;
    }

    public function compute()
    {
        $summary = [
            'processed' => 0,
            'cooled' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        ExchangeSymbol::query()
            ->chunkById(500, function ($symbols) use (&$summary) {
                foreach ($symbols as $ex) {
                    try {
                        $result = $this->processSymbol($ex);

                        if ($result['status'] === 'cooled') {
                            $summary['cooled']++;
                            $summary['details'][] = [
                                'symbol_id' => $ex->id,
                                'pair' => (string) $ex->parsed_trading_pair,
                                'spike_pct' => $result['spike_pct'],
                                'cooldown_h' => $result['cooldown_h'],
                                'cooled_until' => $result['cooled_until'],
                            ];
                        } else {
                            $summary['skipped']++;
                        }

                        $summary['processed']++;
                    } catch (Throwable $e) {
                        $summary['errors']++;

                        // Per your requirement, notify admins on exceptions:
                        User::notifyAdminsViaPushover(
                            "[{$ex->id}] - ExchangeSymbol price spike check error - ".ExceptionParser::with($e)->friendlyMessage(),
                            '[Batch: '.class_basename(static::class)."] Symbol {$ex->id} error",
                            'nidavellir_errors'
                        );

                        $summary['details'][] = [
                            'symbol_id' => $ex->id,
                            'pair' => (string) $ex->parsed_trading_pair,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }, 'id');

        return $summary;
    }

    /**
     * Error handler — top-level batch failure.
     * Notify admins once if the batch itself fails.
     */
    public function resolveException(Throwable $e): void
    {
        User::notifyAdminsViaPushover(
            'Batch price spike check error - '.ExceptionParser::with($e)->friendlyMessage(),
            '['.class_basename(self::class).'] Batch error',
            'nidavellir_errors'
        );
    }

    /**
     * Process one symbol: compute spike and apply cooldown if needed.
     *
     * @return array{status:string, spike_pct?:float, cooldown_h?:int, cooled_until?:string}
     */
    protected function processSymbol(ExchangeSymbol $ex): array
    {
        // 1) Read per-symbol spike threshold (percent). If not set or <= 0, skip.
        $thresholdPct = (float) ($ex->disable_on_price_spike_percentage ?? 0.0);
        if (! is_finite($thresholdPct) || $thresholdPct <= 0.0) {
            return ['status' => 'skipped'];
        }

        // 2) Fetch latest 1D candle for this symbol.
        $latest1d = Candle::query()
            ->where('exchange_symbol_id', $ex->id)
            ->where('timeframe', '1d')
            ->orderByDesc('timestamp') // assumes monotonically increasing
            ->first();

        if (! $latest1d || $latest1d->close === null || $latest1d->close === '') {
            return ['status' => 'skipped'];
        }

        // Reference CLOSE from the latest 1D candle.
        $refClose = (float) $latest1d->close;
        if (! is_finite($refClose) || $refClose <= 0.0) {
            return ['status' => 'skipped'];
        }

        // 3) Resolve current price: prefer live mark price; fallback to the 1D close.
        $current = ($ex->mark_price !== null && $ex->mark_price !== '')
            ? (float) $ex->mark_price
            : $refClose;

        if (! is_finite($current) || $current <= 0.0) {
            return ['status' => 'skipped'];
        }

        // 4) Compute upside percent change vs latest 1D close.
        $pct = (($current - $refClose) / $refClose) * 100.0;

        // 5) If spike >= threshold, apply or extend cool-down window by symbol attribute.
        if (is_finite($pct) && $pct >= $thresholdPct) {
            // Read symbol-specific cooldown hours; fallback to 72 if null/invalid.
            $cooldownHoursRaw = $ex->price_spike_cooldown_hours;
            $cooldownHours = (int) (is_numeric($cooldownHoursRaw) ? $cooldownHoursRaw : 72);
            if ($cooldownHours <= 0) {
                $cooldownHours = 72; // guardrail against bad data
            }

            $proposedUntil = Carbon::now()->addHours($cooldownHours);

            // If tradeable_at already set in the future, keep the later date.
            // Never set tradeable_at to null; always move or keep it.
            $existing = $ex->tradeable_at instanceof Carbon
                ? $ex->tradeable_at
                : (! empty($ex->tradeable_at) ? Carbon::parse($ex->tradeable_at) : null);

            $finalUntil = $existing && $existing->greaterThan($proposedUntil)
                ? $existing
                : $proposedUntil;

            // Persist cool-down window (always update when spike happens, even if unchanged).
            $ex->updateSaving([
                'tradeable_at' => $finalUntil, // Eloquent casts Carbon to datetime
            ]);

            // Application log entry only when a spike cooldown is applied.
            $refCloseF = api_format_price($refClose, $ex);
            $currentF = api_format_price($current, $ex);
            $msg = sprintf(
                'Cooldown applied for %s: change +%.2f%% vs latest 1D close (ref=%s, cur=%s, threshold=%.2f%%). Cooldown %dh. Tradeable at %s.',
                (string) $ex->parsed_trading_pair,
                $pct,
                $refCloseF,
                $currentF,
                $thresholdPct,
                $cooldownHours,
                $finalUntil->format('Y-m-d H:i')
            );
            $ex->logApplicationEvent($msg, self::class, __FUNCTION__);

            return [
                'status' => 'cooled',
                'spike_pct' => $pct,
                'cooldown_h' => $cooldownHours,
                'cooled_until' => $finalUntil->toDateTimeString(),
            ];
        }

        // No spike strong enough; do not log to keep logs clean.
        return ['status' => 'skipped'];
    }
}
