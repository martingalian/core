<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use InvalidArgumentException;

/**
 * Computes evenly-spaced internal levels and counts how many candles
 * cross each level using only [low, high] spans.
 *
 * - Endpoints (ATL/ATH) are EXCLUDED from the levels.
 * - Touch counts as crossing (inclusive bounds).
 * - Levels are returned HIGH -> LOW.
 * - Hits array is aligned with levels (same order).
 */
final class LevelCrossingScanner
{
    /**
     * Small epsilon to stabilize boundary touches in float math.
     */
    private float $eps;

    public function __construct(float $eps = 1e-12)
    {
        $this->eps = $eps;
    }

    /**
     * Compute the payload:
     *  {
     *    atl: float,
     *    ath: float,
     *    step: float,
     *    levels: float[], // DESC order (highest first)
     *    hits: int[],     // aligned with levels
     *    total: int       // sum(hits)
     *  }
     *
     * @param  array<float|int|string>  $lows  oldest -> newest
     * @param  array<float|int|string>  $highs  oldest -> newest
     * @param  int  $internalLevels  number of INTERNAL levels (exclude endpoints)
     */
    public function compute(array $lows, array $highs, int $internalLevels): array
    {
        if ($internalLevels <= 0) {
            throw new InvalidArgumentException('internalLevels must be > 0');
        }
        if (count($lows) === 0 || count($lows) !== count($highs)) {
            throw new InvalidArgumentException('lows/highs must be non-empty and same length');
        }

        // Compute ATL/ATH from full window
        $atl = INF;
        $ath = -INF;
        $n = count($lows);

        for ($i = 0; $i < $n; $i++) {
            $l = (float) $lows[$i];
            $h = (float) $highs[$i];
            if ($h < $l) {
                [$l, $h] = [$h, $l];
            } // defensive
            $atl = min($atl, $l);
            $ath = max($ath, $h);
        }

        if (! is_finite($atl) || ! is_finite($ath) || $ath <= $atl) {
            return [
                'atl' => (float) $atl,
                'ath' => (float) $ath,
                'step' => 0.0,
                'levels' => [],
                'hits' => [],
                'total' => 0,
            ];
        }

        // Internal levels, endpoints excluded
        $step = ($ath - $atl) / ($internalLevels + 1);

        // Build levels LOW -> HIGH
        $levelsAsc = [];
        for ($k = 1; $k <= $internalLevels; $k++) {
            $levelsAsc[] = $atl + $step * $k;
        }

        // Reverse to HIGH -> LOW
        $levels = array_reverse($levelsAsc);
        $hits = array_fill(0, $internalLevels, 0);

        // Fast indexing
        for ($i = 0; $i < $n; $i++) {
            $lo = (float) $lows[$i];
            $hi = (float) $highs[$i];
            if ($hi < $lo) {
                [$lo, $hi] = [$hi, $lo];
            }

            $kLoAsc = (int) ceil((($lo - $atl) / $step) - $this->eps) - 1;
            $kHiAsc = (int) floor((($hi - $atl) / $step) + $this->eps) - 1;

            $kLoAsc = max(0, min($internalLevels - 1, $kLoAsc + 1));
            $kHiAsc = max(0, min($internalLevels - 1, $kHiAsc + 1));

            if ($kLoAsc > $kHiAsc) {
                continue;
            }

            for ($kAsc = $kLoAsc; $kAsc <= $kHiAsc; $kAsc++) {
                $kDesc = $internalLevels - 1 - $kAsc;
                $hits[$kDesc] += 1;
            }
        }

        return [
            'atl' => $atl,
            'ath' => $ath,
            'step' => $step,
            'levels' => $levels,
            'hits' => $hits,
            'total' => array_sum($hits),
        ];
    }
}
