<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Position;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\BaseAssetMapper;
use Martingalian\Core\Support\Proxies\ApiDataMapperProxy;

trait HasTradingActions
{
    public function getParsedTradingPair(): ?string
    {
        if (! $this->exchangeSymbol?->symbol?->token) {
            return null;
        }

        $apiSystem = $this->account->apiSystem;

        $dataMapper = new ApiDataMapperProxy($apiSystem->canonical);

        $exchangeBaseAsset = BaseAssetMapper::where('symbol_token', $this->exchangeSymbol->symbol->token)
            ->where('api_system_id', $apiSystem->id)
            ->first();

        $baseToken = $exchangeBaseAsset
        ? $exchangeBaseAsset->exchange_token
        : $this->exchangeSymbol->symbol->token;

        return $dataMapper->baseWithQuote($baseToken, $this->exchangeSymbol->quote->canonical);
    }

    public function isOpenedOnExchange()
    {
        $openPositions = ApiSnapshot::getFrom($this->account, 'account-positions');

        return is_array($openPositions) && array_key_exists($this->parsed_trading_pair, $openPositions);
    }

    public function syncOrders()
    {
        $this->orders->whereNotNull('exchange_order_id')->each->apiSync();
    }

    public function opened_since(): ?string
    {
        $openedAt = $this->opened_at ?? $this->created_at ?? null;
        if (! $openedAt) {
            return null;
        }

        $opened = $openedAt instanceof Carbon ? $openedAt : Carbon::parse($openedAt);

        return $opened->diffForHumans(now(), [
            'parts' => 1,
            'short' => true,
            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
        ]);
    }
}
