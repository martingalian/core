<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bitget;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsAccountBalanceQuery;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsAccountQuery;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsExchangeInformationQuery;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsOpenOrdersQuery;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPlanOrdersQuery;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPositionsQuery;
use Martingalian\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsServerTimeQuery;

final class BitgetApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsExchangeInformationQuery;
    use MapsOpenOrdersQuery;
    use MapsPlanOrdersQuery;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;

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
        if ($canonical === 'LONG' || $canonical === 'long') {
            return 'long';
        }

        if ($canonical === 'SHORT' || $canonical === 'short') {
            return 'short';
        }

        throw new InvalidArgumentException("Invalid BitGet direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY' || $canonical === 'buy') {
            return 'buy';
        }

        if ($canonical === 'SELL' || $canonical === 'sell') {
            return 'sell';
        }

        throw new InvalidArgumentException("Invalid BitGet side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     *
     * BitGet V2 API uses simple format like BTCUSDT, ETHUSDT.
     * No suffix needed for perpetual contracts.
     *
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(string $token, string $quote): string
    {
        // BitGet uses simple format: BTCUSDT, ETHUSDT (similar to Binance)
        return $token . $quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     *
     * input: BTCUSDT
     * returns: ['base' => 'BTC', 'quote' => 'USDT']
     *
     * input: ETHUSDC
     * returns: ['base' => 'ETH', 'quote' => 'USDC']
     */
    public function identifyBaseAndQuote(string $symbol): array
    {
        // BitGet primarily uses USDT, USDC as quote currencies
        $availableQuoteCurrencies = [
            'USDT', 'USDC', 'USD',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (str_ends_with($symbol, $quoteCurrency)) {
                $base = str_replace($quoteCurrency, '', $symbol);

                return [
                    'base' => $base,
                    'quote' => $quoteCurrency,
                ];
            }
        }

        throw new InvalidArgumentException("Invalid BitGet symbol format: {$symbol}");
    }
}
