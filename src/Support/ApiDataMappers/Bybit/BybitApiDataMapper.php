<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsAccountBalanceQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsAccountQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsExchangeInformationQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsLeverageBracketsQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsOpenOrdersQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsPositionsQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsServerTimeQuery;
use Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsStopOrdersQuery;

final class BybitApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsExchangeInformationQuery;
    use MapsLeverageBracketsQuery;
    use MapsOpenOrdersQuery;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;
    use MapsStopOrdersQuery;

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
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(string $token, string $quote): string
    {
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

    /**
     * Returns a canonical order type from Bybit order data.
     * Bybit uses separate fields: orderType and stopOrderType.
     *
     * @param  array<string, mixed>  $order
     */
    public function canonicalOrderType(array $order): string
    {
        $orderType = $order['orderType'] ?? '';
        $stopOrderType = $order['stopOrderType'] ?? '';

        if ($stopOrderType === 'StopLoss') {
            return 'STOP_MARKET';
        }

        if ($stopOrderType === 'TakeProfit') {
            return 'TAKE_PROFIT';
        }

        return match ($orderType) {
            'Market' => 'MARKET',
            'Limit' => 'LIMIT',
            default => 'UNKNOWN',
        };
    }
}
