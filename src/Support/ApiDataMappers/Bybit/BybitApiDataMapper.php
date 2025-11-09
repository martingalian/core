<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsAccountQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsExchangeInformationQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsLeverageBracketsQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsServerTimeQuery;

final class BybitApiDataMapper extends BaseDataMapper
{
    use MapsAccountQuery;
    use MapsExchangeInformationQuery;
    use MapsLeverageBracketsQuery;
    use MapsServerTimeQuery;

    public function long()
    {
        return 'LONG';
    }

    public function short()
    {
        return 'SHORT';
    }

    public function directionType(string $canonical)
    {
        if ($canonical === 'LONG') {
            return 'LONG';
        }

        if ($canonical === 'SHORT') {
            return 'SHORT';
        }

        throw new InvalidArgumentException("Invalid Bybit direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY') {
            return 'Buy';
        }

        if ($canonical === 'SELL') {
            return 'Sell';
        }

        throw new InvalidArgumentException("Invalid Bybit side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     * E.g.: AVAXUSDT (Bybit uses same format as Binance, no separator).
     * E.g.: BNBPERP for USDC-settled contracts
     *
     * Takes in account, exceptions for the current token by leveraging
     * BaseAssetMapper entries.
     */
    public function baseWithQuote(string $token, string $quote): string
    {
        $apiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        // Leverage the asset mapper to return the right token for the exchange.
        $token = BaseAssetMapper::where('api_system_id', $apiSystem->id)
            ->where('symbol_token', $token)
            ->first()->exchange_token ?? $token;

        // Bybit uses PERP suffix for USDC-settled perpetual contracts
        if ($quote === 'USDC') {
            return $token.'PERP';
        }

        return $token.$quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     * input: BTCUSDT
     * returns: ['base' => 'BTC', 'quote' => 'USDT']
     * input: BNBPERP
     * returns: ['base' => 'BNB', 'quote' => 'USDC']
     */
    public function identifyBaseAndQuote(string $token): array
    {
        // Handle PERP suffix (Bybit uses PERP for USDC-settled perpetual contracts)
        if (str_ends_with($token, 'PERP')) {
            return [
                'base' => str_replace('PERP', '', $token),
                'quote' => 'USDC',
            ];
        }

        $availableQuoteCurrencies = [
            'USDT', 'USDC', 'BTC', 'ETH', 'USDE',
            'DAI', 'EUR', 'GBP', 'AUD',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (str_ends_with($token, $quoteCurrency)) {
                return [
                    'base' => str_replace($quoteCurrency, '', $token),
                    'quote' => $quoteCurrency,
                ];
            }
        }

        throw new InvalidArgumentException("Invalid token format: {$token}");
    }
}
