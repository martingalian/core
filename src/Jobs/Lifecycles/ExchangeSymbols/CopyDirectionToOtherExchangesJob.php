<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\TokenMapper;

final class CopyDirectionToOtherExchangesJob extends BaseQueueableJob
{
    public function __construct(
        public int $sourceExchangeSymbolId,
    ) {}

    public function compute(): void
    {
        // 1. Load source Binance symbol
        $sourceSymbol = ExchangeSymbol::find($this->sourceExchangeSymbolId);
        if (! $sourceSymbol) {
            $this->response = ['error' => 'Source symbol not found'];

            return;
        }

        // 2. Get Binance API system ID
        $binanceSystem = ApiSystem::where('canonical', 'binance')->first();
        if (! $binanceSystem || $sourceSymbol->api_system_id !== $binanceSystem->id) {
            $this->response = ['error' => 'Source symbol is not from Binance'];

            return;
        }

        // 3. Find all other exchanges
        $otherExchanges = ApiSystem::where('canonical', '!=', 'binance')
            ->where('is_exchange', true)
            ->get();

        $copiedCount = 0;
        $targetSymbols = [];

        // 4. For each exchange, find matching token
        foreach ($otherExchanges as $exchange) {
            // Try direct token match first
            $targetSymbol = ExchangeSymbol::query()
                ->where('api_system_id', $exchange->id)
                ->where('token', $sourceSymbol->token)
                ->where('overlaps_with_binance', true)
                ->first();

            // If no direct match, try TokenMapper
            if (! $targetSymbol) {
                $mappedToken = TokenMapper::query()
                    ->where('binance_token', $sourceSymbol->token)
                    ->where('api_system_id', $exchange->id)
                    ->first();

                if ($mappedToken) {
                    $targetSymbol = ExchangeSymbol::query()
                        ->where('api_system_id', $exchange->id)
                        ->where('token', $mappedToken->exchange_token)
                        ->where('overlaps_with_binance', true)
                        ->first();
                }
            }

            // 5. Copy direction data if target found
            if ($targetSymbol) {
                $targetSymbol->updateSaving([
                    'direction' => $sourceSymbol->direction,
                    'indicators_values' => $sourceSymbol->indicators_values,
                    'indicators_timeframe' => $sourceSymbol->indicators_timeframe,
                    'indicators_synced_at' => $sourceSymbol->indicators_synced_at,
                    'auto_disabled' => false,
                    'auto_disabled_reason' => null,
                ]);

                $copiedCount++;
                $targetSymbols[] = [
                    'exchange' => $exchange->canonical,
                    'token' => $targetSymbol->token,
                    'id' => $targetSymbol->id,
                ];
            }
        }

        $this->response = [
            'source_symbol_id' => $this->sourceExchangeSymbolId,
            'source_token' => $sourceSymbol->token,
            'direction' => $sourceSymbol->direction,
            'copied_to_count' => $copiedCount,
            'target_symbols' => $targetSymbols,
        ];
    }
}
