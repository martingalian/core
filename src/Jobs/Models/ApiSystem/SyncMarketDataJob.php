<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\ApiSystem;

use Illuminate\Support\Carbon;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Quote;
use Martingalian\Core\Models\Symbol;

/*
 * SyncMarketDataJob
 *
 * • Retrieves and stores exchange market data (tick size, precision, etc.).
 * • Only executes for systems flagged as exchange-capable.
 * • Skips symbols with underscores (likely test or synthetic pairs).
 * • Maps token pairs into Symbol and Quote models.
 * • Creates or updates matching ExchangeSymbol records.
 * • Persists delivery (delisting) info and reacts to changes.
 * • When a delivery (delisting) change is detected, disables trading and:
 *     - Notifies admin with list of all open positions (users + accounts)
 *     - Does NOT automatically close positions - admin reviews manually
 */
final class SyncMarketDataJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    /** @var array<string> Accumulates missing base tokens during this run */
    protected array $missingTokens = [];

    public function __construct(int $apiSystemId)
    {
        // Load the API system for which to sync market data.
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable()
    {
        // Link job logs and metadata to the ApiSystem model.
        return $this->apiSystem;
    }

    public function assignExceptionHandler()
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        // Ensure the job only runs for valid exchange systems.
        return $this->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        // Fetch full market data (e.g., symbol info, precision settings).
        $apiResponse = $this->apiSystem->apiQueryMarketData();

        foreach ($apiResponse->result as $tokenData) {
            // Skip test/synthetic symbols that contain underscores.
            if (str_contains($tokenData['pair'], '_')) {
                continue;
            }

            // Identify base and quote assets for the symbol.
            // Some exchanges (like Bybit) provide these directly in the API response.
            if (isset($tokenData['baseAsset'], $tokenData['quoteAsset'])) {
                $pair = [
                    'base' => $tokenData['baseAsset'],
                    'quote' => $tokenData['quoteAsset'],
                ];
            } else {
                $pair = $this->apiSystem->apiMapper()->identifyBaseAndQuote($tokenData['pair']);
            }

            // Ensure we have a Symbol for the base asset; otherwise collect it for CSV.
            $symbol = Symbol::getByExchangeBaseAsset($pair['base'], $this->apiSystem);
            if (! $symbol) {
                // Accumulate the bare token (e.g. "ETH", "SOL") to later persist as CSV.
                $this->missingTokens[] = (string) $pair['base'];

                continue;
            }

            // Ensure we have a Quote for the quote asset.
            $quote = Quote::firstWhere('canonical', $pair['quote']);
            if (! $quote) {
                continue;
            }

            // Create or update the ExchangeSymbol record with market precision & trading data.
            /** @var ExchangeSymbol $exchangeSymbol */
            $exchangeSymbol = ExchangeSymbol::updateOrCreate(
                [
                    'symbol_id' => $symbol->id,
                    'api_system_id' => $this->apiSystem->id,
                    'quote_id' => $quote->id,
                ],
                [
                    'symbol_information' => $tokenData,
                    'price_precision' => $tokenData['pricePrecision'],
                    'quantity_precision' => $tokenData['quantityPrecision'],
                    'tick_size' => $tokenData['tickSize'],
                    'min_price' => $tokenData['minPrice'],
                    'max_price' => $tokenData['maxPrice'],
                    'min_notional' => $tokenData['minNotional'],
                    'limit_quantity_multipliers' => [2, 2, 2, 2],
                ]
            );

            // --- Delivery (delisting) handling ---------------------------------------------
            // Incoming deliveryDate from Binance is milliseconds since epoch (or 0/absent).
            $incomingMs = (int) ($tokenData['deliveryDate'] ?? 0);

            // Snapshot current stored ms (nullable).
            $currentMs = $exchangeSymbol->delivery_ts_ms ? (int) $exchangeSymbol->delivery_ts_ms : null;

            if ($currentMs === null && $incomingMs > 0) {
                // Case 2.1 — We never saved a delivery date before: just persist it (no side-effects).
                $exchangeSymbol->forceFill([
                    'delivery_ts_ms' => $incomingMs,
                    'delivery_at' => Carbon::createFromTimestampMs($incomingMs)->utc(),
                ])->save();

                // No further action.
                continue;
            }

            if ($currentMs !== null && $incomingMs > 0 && $incomingMs !== $currentMs) {
                // Case 2.2 — Delivery changed: trigger "delisting" workflow.
                $exchangeSymbol->forceFill([
                    'delivery_ts_ms' => $incomingMs,
                    'delivery_at' => Carbon::createFromTimestampMs($incomingMs)->utc(),
                    'is_tradeable' => 0, // immediately stop trading this pair
                ])->save();

                // Note: Admin notification is handled by ExchangeSymbolObserver when delivery_ts_ms changes
            }
            // --------------------------------------------------------------------------------
        }

        return $apiResponse->result;
    }
}
