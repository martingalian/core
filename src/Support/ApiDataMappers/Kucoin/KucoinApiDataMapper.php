<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kucoin;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsAccountBalanceQuery;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsAccountQuery;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsExchangeInformationQuery;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsOpenOrdersQuery;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsPositionsQuery;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsServerTimeQuery;
use Martingalian\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsStopOrdersQuery;

final class KucoinApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsExchangeInformationQuery;
    use MapsOpenOrdersQuery;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;
    use MapsStopOrdersQuery;

    public function long()
    {
        return 'long';
    }

    public function short()
    {
        return 'short';
    }

    public function directionType(string $canonical)
    {
        if ($canonical === 'LONG') {
            return 'long';
        }

        if ($canonical === 'SHORT') {
            return 'short';
        }

        throw new InvalidArgumentException("Invalid KuCoin direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY') {
            return 'buy';
        }

        if ($canonical === 'SELL') {
            return 'sell';
        }

        throw new InvalidArgumentException("Invalid KuCoin side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     *
     * KuCoin Futures uses format like XBTUSDTM (perpetual) or XBTUSDM (inverse).
     * The 'M' suffix indicates a perpetual contract.
     *
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(string $token, string $quote): string
    {
        // KuCoin uses XBT instead of BTC
        if ($token === 'BTC') {
            $token = 'XBT';
        }

        // KuCoin perpetual format: XBTUSDTM, ETHUSDTM
        return $token . $quote . 'M';
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     *
     * input: XBTUSDTM
     * returns: ['base' => 'XBT', 'quote' => 'USDT']
     *
     * input: ETHUSDM
     * returns: ['base' => 'ETH', 'quote' => 'USD']
     */
    public function identifyBaseAndQuote(string $symbol): array
    {
        // Remove the 'M' suffix for perpetual contracts
        $symbolPart = preg_replace('/M$/', '', $symbol);

        if ($symbolPart === null) {
            $symbolPart = $symbol;
        }

        // KuCoin Futures primarily uses USDT, USD as quote currencies
        $availableQuoteCurrencies = [
            'USDT', 'USD', 'USDC',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (str_ends_with($symbolPart, $quoteCurrency)) {
                $base = str_replace($quoteCurrency, '', $symbolPart);

                return [
                    'base' => $base,
                    'quote' => $quoteCurrency,
                ];
            }
        }

        throw new InvalidArgumentException("Invalid KuCoin symbol format: {$symbol}");
    }

    /**
     * Returns a canonical order type from KuCoin order data.
     *
     * @param  array<string, mixed>  $order
     */
    public function canonicalOrderType(array $order): string
    {
        $type = strtolower($order['type'] ?? '');
        $stop = $order['stop'] ?? '';
        $stopPrice = (float) ($order['stopPrice'] ?? 0);

        // Check if it's a stop order
        if ($stop !== '' || $stopPrice > 0) {
            return 'STOP_MARKET';
        }

        return match ($type) {
            'market' => 'MARKET',
            'limit' => 'LIMIT',
            default => 'UNKNOWN',
        };
    }
}
