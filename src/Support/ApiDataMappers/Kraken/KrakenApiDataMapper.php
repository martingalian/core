<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken;

use InvalidArgumentException;
use Martingalian\Core\Abstracts\BaseDataMapper;
use Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests\MapsAccountBalanceQuery;
use Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests\MapsAccountQuery;
use Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests\MapsExchangeInformationQuery;
use Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests\MapsOpenOrdersQuery;
use Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests\MapsPositionsQuery;
use Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests\MapsServerTimeQuery;

final class KrakenApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsExchangeInformationQuery;
    use MapsOpenOrdersQuery;
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
        if ($canonical === 'LONG') {
            return 'long';
        }

        if ($canonical === 'SHORT') {
            return 'short';
        }

        throw new InvalidArgumentException("Invalid Kraken direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY') {
            return 'buy';
        }

        if ($canonical === 'SELL') {
            return 'sell';
        }

        throw new InvalidArgumentException("Invalid Kraken side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     *
     * Kraken Futures uses format like PF_XBTUSD (multi-collateral) or PI_ETHUSD (inverse).
     * PF_ = Perpetual Flex (multi-collateral)
     * PI_ = Perpetual Inverse
     *
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(string $token, string $quote): string
    {
        // Kraken uses XBT instead of BTC
        if ($token === 'BTC') {
            $token = 'XBT';
        }

        // Determine prefix based on quote currency
        // PF_ for USD-denominated multi-collateral perpetuals
        // PI_ for inverse perpetuals
        $prefix = 'PF_';

        return $prefix.$token.$quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     *
     * input: PF_XBTUSD
     * returns: ['base' => 'XBT', 'quote' => 'USD']
     *
     * input: PI_ETHUSD
     * returns: ['base' => 'ETH', 'quote' => 'USD']
     */
    public function identifyBaseAndQuote(string $token): array
    {
        // Remove prefix (PF_, PI_, FI_, etc.)
        $symbolPart = preg_replace('/^[A-Z]{2}_/', '', $token);

        if ($symbolPart === null || $symbolPart === $token) {
            // No prefix found, try to parse as-is
            $symbolPart = $token;
        }

        // Kraken Futures primarily uses USD as quote currency
        $availableQuoteCurrencies = [
            'USD', 'USDT', 'USDC', 'EUR', 'GBP', 'XBT',
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

        throw new InvalidArgumentException("Invalid token format: {$token}");
    }
}
