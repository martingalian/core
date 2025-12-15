<?php

declare(strict_types=1);

namespace Martingalian\Core\Martingalian\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;

trait DetectsStalePrices
{
    /**
     * Recover symbols that were marked as stale but now have fresh prices.
     *
     * @return int Number of symbols recovered
     */
    public static function recoverFreshSymbols(int $thresholdSeconds): int
    {
        $staleThreshold = now()->subSeconds($thresholdSeconds);

        return ExchangeSymbol::query()
            ->where('has_stale_price', true)
            ->whereNotNull('mark_price_synced_at')
            ->where('mark_price_synced_at', '>=', $staleThreshold)
            ->update(['has_stale_price' => false]);
    }

    /**
     * Get exchange symbols with stale mark prices.
     *
     * A symbol is considered stale if:
     * - It has a mark_price (not null)
     * - AND mark_price_synced_at is null or older than threshold
     *
     * @return Collection<int, ExchangeSymbol>
     */
    public static function getStaleSymbols(int $thresholdSeconds): Collection
    {
        $staleThreshold = now()->subSeconds($thresholdSeconds);

        return ExchangeSymbol::query()
            ->whereNotNull('mark_price')
            ->where(function (Builder $query) use ($staleThreshold): void {
                $query->whereNull('mark_price_synced_at')
                    ->orWhere('mark_price_synced_at', '<', $staleThreshold);
            })
            ->get();
    }

    /**
     * Mark symbols as having stale prices.
     *
     * Only marks symbols that aren't already flagged.
     *
     * @param  Collection<int, ExchangeSymbol>  $symbols
     * @return int Number of symbols marked
     */
    public static function markStaleSymbols(Collection $symbols): int
    {
        $markedCount = 0;

        foreach ($symbols as $symbol) {
            /** @var ExchangeSymbol $symbol */
            if (! $symbol->has_stale_price) {
                $symbol->update(['has_stale_price' => true]);
                $markedCount++;
            }
        }

        return $markedCount;
    }

    /**
     * Group stale symbols by exchange for reporting.
     *
     * @param  Collection<int, ExchangeSymbol>  $staleSymbols
     * @return array<string, array{exchange: string, canonical: string, stale_example: array<string, mixed>|null, api_system: ApiSystem, stale_count: int}>
     */
    public static function groupStaleByExchange(Collection $staleSymbols): array
    {
        /** @var array<string, array{exchange: string, canonical: string, stale_example: array<string, mixed>|null, api_system: ApiSystem, stale_count: int}> $grouped */
        $grouped = [];

        $exchangeIds = $staleSymbols->pluck('api_system_id')->unique();

        foreach ($exchangeIds as $apiSystemId) {
            /** @var int $apiSystemIdInt */
            $apiSystemIdInt = $apiSystemId;
            $apiSystem = ApiSystem::find($apiSystemIdInt);

            if (! $apiSystem) {
                continue;
            }

            /** @var ApiSystem $apiSystem */
            /** @var string $exchangeName */
            $exchangeName = $apiSystem->canonical;

            $exchangeStaleCount = $staleSymbols->where('api_system_id', $apiSystemIdInt)->count();
            $staleExample = self::getStaleExampleFromCollection($staleSymbols, $apiSystemIdInt);

            $grouped[$exchangeName] = [
                'exchange' => $apiSystem->name,
                'canonical' => $exchangeName,
                'stale_example' => $staleExample,
                'api_system' => $apiSystem,
                'stale_count' => $exchangeStaleCount,
            ];
        }

        return $grouped;
    }

    /**
     * Get one stale symbol example from a collection for notification purposes.
     *
     * @param  Collection<int, ExchangeSymbol>  $staleSymbols
     * @return array<string, mixed>|null
     */
    public static function getStaleExampleFromCollection(Collection $staleSymbols, int $apiSystemId): ?array
    {
        $stale = $staleSymbols->firstWhere('api_system_id', $apiSystemId);

        if (! $stale) {
            return null;
        }

        /** @var ExchangeSymbol $stale */
        $minutesAgo = $stale->mark_price_synced_at
            ? $stale->mark_price_synced_at->diffInMinutes(now())
            : 999;

        $markPrice = is_numeric($stale->mark_price) ? (float) $stale->mark_price : 0.0;

        return [
            'symbol' => $stale->token.'/'.$stale->quote,
            'price' => number_format($markPrice, 8),
            'minutes_ago' => $minutesAgo,
        ];
    }
}
