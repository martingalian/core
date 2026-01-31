<?php

declare(strict_types=1);

namespace Martingalian\Core\Commands;

use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\ApiSystem;
use StepDispatcher\Support\BaseCommand;

final class UpdateRecvwindowSafetyDurationCommand extends BaseCommand
{
    protected $signature = 'martingalian:update-recvwindow-safety-duration';

    protected $description = 'Updates the API system recvwindow duration (for now only works for Binance)';

    public function handle(): int
    {
        // Fetch the Binance admin account
        $account = Account::admin('binance');

        // Get the server time from Binance API via the admin account
        $response = $account->withApi()->serverTime();
        $serverTime = json_decode($response->getBody(), associative: true)['serverTime'];

        // Get current system time in milliseconds
        $systemTime = (int) (microtime(true) * 1000);

        // Calculate the time difference in milliseconds
        $timeDifferenceMs = abs($systemTime - $serverTime);

        // Add 50% safety margin to the time difference
        $recvWindowMargin = $timeDifferenceMs + ($timeDifferenceMs * 0.50); // 50% safety margin

        // Ensure the recvWindowMargin is at least 2000
        $recvWindowMargin = max($recvWindowMargin, 2000);

        // Update the `recvwindow_margin` in the `api_systems` table
        ApiSystem::where('canonical', 'binance')->update([
            'recvwindow_margin' => $recvWindowMargin,
            'updated_at' => now(),
        ]);

        return 0;
    }
}
