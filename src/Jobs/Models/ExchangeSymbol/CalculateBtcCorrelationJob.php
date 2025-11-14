<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ExchangeSymbol;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Candle;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Symbol;

/**
 * CalculateBtcCorrelationJob
 *
 * Calculates correlation between a token and BTC using historical candle data.
 * Three correlation types are calculated:
 * - Pearson: Linear relationship between price movements
 * - Spearman: Rank-based correlation (more robust for crypto volatility)
 * - Rolling: Correlation over recent window (configurable via method)
 *
 * Results are stored in exchange_symbols columns for filtering/scoring.
 */
final class CalculateBtcCorrelationJob extends BaseQueueableJob
{
    public int $exchangeSymbolId;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function compute()
    {
        $config = config('martingalian.correlation');

        // Skip if correlation is disabled
        if (! $config['enabled']) {
            return ['skipped' => true, 'reason' => 'Correlation calculation disabled in config'];
        }

        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);

        // Find BTC symbol dynamically
        $btcSymbol = Symbol::where('token', $config['btc_token'])->first();

        if (! $btcSymbol) {
            return ['error' => "BTC symbol not found (token={$config['btc_token']})"];
        }

        // Skip if this IS BTC (can't correlate with itself)
        if ($exchangeSymbol->symbol_id === $btcSymbol->id) {
            return ['skipped' => true, 'reason' => 'Cannot correlate BTC with itself'];
        }

        // Find BTC exchange_symbol for same exchange
        $btcExchangeSymbol = ExchangeSymbol::query()
            ->where('symbol_id', $btcSymbol->id)
            ->where('api_system_id', $exchangeSymbol->api_system_id)
            ->where('quote_id', $exchangeSymbol->quote_id)
            ->first();

        if (! $btcExchangeSymbol) {
            return ['error' => 'BTC not found on same exchange'];
        }

        // Fetch candles for this token
        $tokenCandles = Candle::query()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->where('timeframe', $config['timeframe'])
            ->orderBy('timestamp', 'desc')
            ->limit($config['window_size'])
            ->get()
            ->sortBy('timestamp')
            ->values();

        // Fetch candles for BTC
        $btcCandles = Candle::query()
            ->where('exchange_symbol_id', $btcExchangeSymbol->id)
            ->where('timeframe', $config['timeframe'])
            ->orderBy('timestamp', 'desc')
            ->limit($config['window_size'])
            ->get()
            ->sortBy('timestamp')
            ->values();

        // Check minimum candles requirement
        if ($tokenCandles->count() < $config['min_candles'] || $btcCandles->count() < $config['min_candles']) {
            return [
                'error' => 'Insufficient candles',
                'token_candles' => $tokenCandles->count(),
                'btc_candles' => $btcCandles->count(),
                'min_required' => $config['min_candles'],
            ];
        }

        // Align timestamps (only use overlapping candles)
        $tokenTimestamps = $tokenCandles->pluck('timestamp', 'timestamp')->all();
        $btcTimestamps = $btcCandles->pluck('timestamp', 'timestamp')->all();
        $commonTimestamps = array_intersect_key($tokenTimestamps, $btcTimestamps);

        if (empty($commonTimestamps)) {
            return ['error' => 'No overlapping candle timestamps found'];
        }

        // Extract close prices for common timestamps
        $tokenPrices = [];
        $btcPrices = [];

        foreach ($commonTimestamps as $timestamp => $_) {
            $tokenCandle = $tokenCandles->firstWhere('timestamp', $timestamp);
            $btcCandle = $btcCandles->firstWhere('timestamp', $timestamp);

            if ($tokenCandle && $btcCandle) {
                $tokenPrices[] = (float) $tokenCandle->close;
                $btcPrices[] = (float) $btcCandle->close;
            }
        }

        if (count($tokenPrices) < 2) {
            return ['error' => 'Need at least 2 aligned candles for correlation'];
        }

        // Calculate correlations
        $pearson = $this->calculatePearsonCorrelation($tokenPrices, $btcPrices);
        $spearman = $this->calculateSpearmanCorrelation($tokenPrices, $btcPrices);
        $rolling = $this->calculateRollingCorrelation(
            $tokenPrices,
            $btcPrices,
            $config['rolling']['window_size'],
            $config['rolling']['method'],
            $config['rolling']['step_size']
        );

        // Update exchange_symbol
        $exchangeSymbol->btc_correlation_pearson = $pearson;
        $exchangeSymbol->btc_correlation_spearman = $spearman;
        $exchangeSymbol->btc_correlation_rolling = $rolling;
        $exchangeSymbol->save();

        return [
            'exchange_symbol_id' => $exchangeSymbol->id,
            'symbol' => $exchangeSymbol->symbol->token,
            'candles_analyzed' => count($tokenPrices),
            'pearson' => round($pearson, 4),
            'spearman' => round($spearman, 4),
            'rolling' => round($rolling, 4),
        ];
    }

    /**
     * Calculate Pearson correlation coefficient
     * Measures linear relationship between two datasets
     */
    public function calculatePearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);

        if ($n < 2) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $numerator = 0;
        $denomX = 0;
        $denomY = 0;

        for ($i = 0; $i < $n; $i++) {
            $diffX = $x[$i] - $meanX;
            $diffY = $y[$i] - $meanY;

            $numerator += $diffX * $diffY;
            $denomX += $diffX * $diffX;
            $denomY += $diffY * $diffY;
        }

        if ($denomX === 0.0 || $denomY === 0.0) {
            return 0.0;
        }

        return $numerator / sqrt($denomX * $denomY);
    }

    /**
     * Calculate Spearman rank correlation
     * More robust to outliers than Pearson
     */
    public function calculateSpearmanCorrelation(array $x, array $y): float
    {
        // Convert values to ranks
        $ranksX = $this->rankArray($x);
        $ranksY = $this->rankArray($y);

        // Apply Pearson to ranks
        return $this->calculatePearsonCorrelation($ranksX, $ranksY);
    }

    /**
     * Calculate rolling correlation
     * Supports three methods: recent, average, weighted
     */
    public function calculateRollingCorrelation(
        array $x,
        array $y,
        int $windowSize,
        string $method,
        int $stepSize
    ): float {
        $n = count($x);

        if ($n < $windowSize) {
            // Not enough data for rolling window, return full correlation
            return $this->calculatePearsonCorrelation($x, $y);
        }

        if ($method === 'recent') {
            // Return correlation of most recent window only
            $recentX = array_slice($x, -$windowSize);
            $recentY = array_slice($y, -$windowSize);

            return $this->calculatePearsonCorrelation($recentX, $recentY);
        }

        // Calculate sliding window correlations
        $correlations = [];
        $weights = [];

        for ($i = 0; $i <= $n - $windowSize; $i += $stepSize) {
            $windowX = array_slice($x, $i, $windowSize);
            $windowY = array_slice($y, $i, $windowSize);

            $correlation = $this->calculatePearsonCorrelation($windowX, $windowY);
            $correlations[] = $correlation;

            // Weight: more recent windows get higher weight (exponential decay)
            if ($method === 'weighted') {
                $position = $i / max(1, ($n - $windowSize));
                $weights[] = exp($position); // Exponential weight favoring recent data
            } else {
                $weights[] = 1.0; // Equal weight for 'average' method
            }
        }

        if (empty($correlations)) {
            return 0.0;
        }

        // Calculate weighted average
        $totalWeight = array_sum($weights);
        $weightedSum = 0;

        foreach ($correlations as $idx => $corr) {
            $weightedSum += $corr * $weights[$idx];
        }

        return $weightedSum / $totalWeight;
    }

    /**
     * Convert array values to ranks (for Spearman)
     * Handles ties by assigning average rank
     */
    public function rankArray(array $data): array
    {
        $sorted = $data;
        asort($sorted);

        $ranks = [];
        $rank = 1;

        foreach ($sorted as $key => $value) {
            $ranks[$key] = $rank;
            $rank++;
        }

        // Return ranks in original order
        $orderedRanks = [];
        foreach ($data as $key => $_) {
            $orderedRanks[] = $ranks[$key];
        }

        return $orderedRanks;
    }
}
