<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Illuminate\Support\Facades\DB;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\Debuggable;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\LeverageBracket;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Symbol;
use RuntimeException;
use Throwable;

/*
 * SyncLeverageBracketsJob
 *
 * • Keeps existing JSON sync on exchange_symbols.leverage_brackets (unchanged).
 * • Adds normalized upsert into leverage_brackets table (per bracket row).
 * • Deletes stale bracket rows that no longer exist for a symbol.
 * • Skips unknown symbols/quotes exactly like before.
 * • Wraps per-symbol normalization in a transaction for consistency.
 */
final class SyncLeverageBracketsJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        // Load the API system instance by ID.
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable()
    {
        // Allow job context/logs to link to the ApiSystem.
        return $this->apiSystem;
    }

    public function assignExceptionHandler()
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        // Only run for exchange-type systems.
        return $this->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        // Fetch leverage brackets data from the exchange.
        $response = $this->apiSystem->apiQueryLeverageBracketsData();
        $brackets = $response->result ?? null;

        if (! is_array($brackets)) {
            // Fail fast on malformed payloads (keeps production safe/observable).
            throw new RuntimeException('Invalid leverage brackets response.');
        }

        // Filter out test and irrelevant symbols.
        $brackets = $this->removeUnnecessarySymbols($brackets);

        foreach ($brackets as $entry) {
            $symbolCode = $entry['symbol'] ?? null;
            if (! $symbolCode) {
                continue;
            }

            try {
                // Identify base/quote using the API's canonical mapper.
                $pair = $this->apiSystem->apiMapper()->identifyBaseAndQuote($symbolCode);
            } catch (Throwable $e) {
                continue;
            }

            if (! isset($pair['base'], $pair['quote'])) {
                continue;
            }

            // Get or skip unknown symbols and quotes.
            $symbol = Symbol::getByExchangeBaseAsset($pair['base'], $this->apiSystem);
            if (! $symbol) {
                continue;
            }

            $quote = Quote::firstWhere('canonical', $pair['quote']);
            if (! $quote) {
                continue;
            }

            // --- KEEP: Update JSON blob on exchange_symbols (existing prod behavior) ---
            ExchangeSymbol::where([
                'symbol_id' => $symbol->id,
                'api_system_id' => $this->apiSystem->id,
                'quote_id' => $quote->id,
            ])->update([
                'leverage_brackets' => $entry['brackets'] ?? [],
            ]);

            // Retrieve the ExchangeSymbol row we just updated.
            $exchangeSymbol = ExchangeSymbol::where([
                'symbol_id' => $symbol->id,
                'api_system_id' => $this->apiSystem->id,
                'quote_id' => $quote->id,
            ])->first();

            if (! $exchangeSymbol) {
                continue;
            }

            // --- NEW: Normalize into leverage_brackets table ---
            // Wrap the per-symbol sync in a transaction to keep delete/upserts atomic.
            DB::transaction(function () use ($exchangeSymbol, $entry) {
                $now = now();

                $rows = is_array($entry['brackets'] ?? null) ? $entry['brackets'] : [];

                // If the exchange returns no brackets, purge current rows for this symbol.
                if (empty($rows)) {
                    LeverageBracket::where('exchange_symbol_id', $exchangeSymbol->id)->delete();

                    return;
                }

                // Collect current bracket identifiers we are about to upsert.
                $currentBrackets = collect($rows)
                    ->filter(fn ($b) => isset($b['bracket']))
                    ->pluck('bracket')
                    ->all();

                // Delete stale brackets not present anymore.
                LeverageBracket::where('exchange_symbol_id', $exchangeSymbol->id)
                    ->whereNotIn('bracket', $currentBrackets)
                    ->delete();

                // Upsert each bracket row (idempotent).
                foreach ($rows as $b) {
                    // Skip malformed entries (must have a bracket number).
                    if (! isset($b['bracket'])) {
                        continue;
                    }

                    LeverageBracket::updateOrCreate(
                        [
                            'exchange_symbol_id' => $exchangeSymbol->id,
                            'bracket' => (int) $b['bracket'],
                        ],
                        [
                            // Keep precision-sensitive fields as strings in PHP (handled via casts).
                            'initial_leverage' => isset($b['initialLeverage']) ? (int) $b['initialLeverage'] : null,
                            'notional_floor' => isset($b['notionalFloor']) ? (string) $b['notionalFloor'] : '0',
                            'notional_cap' => isset($b['notionalCap']) ? (string) $b['notionalCap'] : '0',
                            'maint_margin_ratio' => isset($b['maintMarginRatio']) ? (string) $b['maintMarginRatio'] : '0',
                            'cum' => $b['cum'] ?? null,
                            'source_payload' => $b,
                            'synced_at' => $now,
                        ]
                    );
                }
            });

            // Log debug information for auditing and traceability.
            Debuggable::debug($exchangeSymbol, 'Leverage data was synced', $exchangeSymbol->symbol->token);
        }

        return $response->result;
    }

    protected function removeUnnecessarySymbols(array $entries): array
    {
        // Filter out symbols that contain underscores or end with "SETTLED".
        return array_filter($entries, function ($entry) {
            $symbol = $entry['symbol'] ?? '';

            return ! str_contains($symbol, '_') && ! str_ends_with($symbol, 'SETTLED');
        });
    }
}
