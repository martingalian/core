<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Returns structured balance data for the account's trading quote.
     *
     * Kraken Futures response structure:
     * {
     *     "result": "success",
     *     "accounts": {
     *         "flex": {
     *             "balances": { "usdt": 1000.0, "xbt": 0.1 },
     *             "collateralValue": 10000.0,
     *             "marginRequirements": { ... },
     *             "availableFunds": 5000.0,
     *             "unrealizedPnl": 100.0
     *         },
     *         "cash": {
     *             "balances": { ... }
     *         }
     *     }
     * }
     */
    public function resolveGetBalanceResponse(Response $response, Account $account): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $tradingQuote = mb_strtolower($account->tradingQuote->canonical ?? 'usdt');

        // Kraken Futures uses 'flex' account for multi-collateral futures trading
        $accounts = $data['accounts'] ?? [];
        $flexAccount = $accounts['flex'] ?? [];

        if (empty($flexAccount)) {
            return [
                'wallet-balance' => '0',
                'available-balance' => '0',
                'cross-wallet-balance' => '0',
                'cross-unrealized-pnl' => '0',
            ];
        }

        // Get balances for the trading quote currency
        $balances = $flexAccount['balances'] ?? [];
        $quoteBalance = (string) ($balances[$tradingQuote] ?? '0');

        // Kraken provides collateralValue (total value in USD) and availableFunds
        $collateralValue = (string) ($flexAccount['collateralValue'] ?? '0');
        $availableFunds = (string) ($flexAccount['availableFunds'] ?? '0');
        $unrealizedPnl = (string) ($flexAccount['unrealizedPnl'] ?? '0');

        // Use collateral value as wallet balance for multi-collateral accounts
        // or direct quote balance for single-currency setups
        $walletBalance = $quoteBalance !== '0' ? $quoteBalance : $collateralValue;

        return [
            'wallet-balance' => $walletBalance,
            'available-balance' => $availableFunds,
            'cross-wallet-balance' => $collateralValue,
            'cross-unrealized-pnl' => $unrealizedPnl,
        ];
    }
}
