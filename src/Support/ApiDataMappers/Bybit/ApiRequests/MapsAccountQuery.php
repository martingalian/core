<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);
        $properties->set('options.accountType', 'UNIFIED');

        return $properties;
    }

    public function resolveQueryAccountResponse(Response $response): array
    {
        $response = json_decode((string) $response->getBody(), true);

        // Extract the result data from Bybit's response structure
        if (! isset($response['result'])) {
            return [];
        }

        $result = $response['result'];

        // Bybit returns account data in a 'list' array for UNIFIED accounts
        if (isset($result['list']) && is_array($result['list']) && count($result['list']) > 0) {
            $accountData = $result['list'][0];

            // Map Bybit fields to match Binance structure expected by StoreAccountsBalancesCommand
            return [
                'totalWalletBalance' => $accountData['totalWalletBalance'] ?? '0',
                'totalUnrealizedProfit' => $accountData['totalPerpUPL'] ?? '0',
                'totalMaintMargin' => $accountData['totalMaintenanceMargin'] ?? '0',
                'totalMarginBalance' => $accountData['totalMarginBalance'] ?? '0',
            ];
        }

        return [];
    }
}
